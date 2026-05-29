<?php

declare(strict_types=1);

namespace XPHP\Lsp\Analyzer;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use RuntimeException;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\Registry;
use XPHP\Transpiler\Monomorphize\TypeHierarchy;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Workspace-level analyzer that drives the existing Registry + TypeHierarchy machinery
 * across every parsed file and converts each thrown RuntimeException into a per-file
 * Diagnostic. Per-file Analyzer handles syntax errors; this one catches:
 *  - duplicate template declarations across files
 *  - hash collisions
 *  - bound violations (the highest-value diagnostic surface)
 *
 * The walk is manual (not via RegistryCollector) because we need to track *which file*
 * each Name/ClassLike came from so the diagnostic lands on the right URI. RegistryCollector
 * does the same work for the compiler — see src/Transpiler/Monomorphize/RegistryCollector.php
 * for the canonical version we're mirroring here.
 */
final readonly class WorkspaceAnalyzer
{
    /**
     * @param array<string, array{ast: list<Node\Stmt>, source: string}> $files keyed by URI/path
     * @return array<string, list<Diagnostic>> diagnostics keyed by URI/path
     */
    public function analyze(array $files): array
    {
        $diagnosticsByFile = array_fill_keys(array_keys($files), []);

        $astPerFile = [];
        foreach ($files as $path => $entry) {
            $astPerFile[$path] = $entry['ast'];
        }
        $hierarchy = TypeHierarchy::fromAstPerFile($astPerFile);
        $registry = new Registry(hierarchy: $hierarchy);

        // First pass: definitions. Catch duplicate-declaration RuntimeExceptions and pin
        // them on the second declaration's file (which is what the compiler also reports).
        foreach ($files as $path => $entry) {
            $positionMap = new PositionMap($entry['source']);
            $this->walkDefinitions($entry['ast'], $registry, $path, $positionMap, $diagnosticsByFile[$path]);
        }

        // Second pass: instantiations. Bound violations fire here.
        foreach ($files as $path => $entry) {
            $positionMap = new PositionMap($entry['source']);
            $this->walkInstantiations($entry['ast'], $registry, $positionMap, $diagnosticsByFile[$path]);
        }

        // Third pass: constructor argument-type checking (V1 of the
        // post-monomorphization arg type checker).  Catches the class
        // of bugs where `new C<T>(…)` or plain `new C(…)` is called
        // with an arg whose static type can't satisfy the (substituted)
        // ctor param's declared type -- a runtime TypeError waiting
        // to happen.
        $argChecks = (new ConstructorArgumentChecker())->check($files, $hierarchy);
        foreach ($argChecks as $path => $diags) {
            foreach ($diags as $diag) {
                $diagnosticsByFile[$path][] = $diag;
            }
        }

        return $diagnosticsByFile;
    }

    /**
     * @param list<Node\Stmt> $ast
     * @param list<Diagnostic> $diagnostics
     */
    private function walkDefinitions(
        array $ast,
        Registry $registry,
        string $sourceFile,
        PositionMap $positionMap,
        array &$diagnostics,
    ): void {
        $visitor = new class($registry, $sourceFile, $positionMap, $diagnostics) extends NodeVisitorAbstract {
            /** @param list<Diagnostic> $diagnostics */
            public function __construct(
                private readonly Registry $registry,
                private readonly string $sourceFile,
                private readonly PositionMap $positionMap,
                private array &$diagnostics,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if (!$node instanceof ClassLike || $node->name === null) {
                    return null;
                }
                $params = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
                $fqn = $node->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
                if (!is_array($params) || $params === [] || !is_string($fqn)) {
                    return null;
                }
                // Deliberately do NOT pre-check for "already registered" — the whole point
                // of running this in the analyzer is to surface the duplicate-declaration
                // RuntimeException from Registry::recordDefinition as a diagnostic.
                try {
                    $this->registry->recordDefinition(
                        $fqn,
                        $node->name->toString(),
                        $params,
                        $node,
                        $this->sourceFile,
                    );
                } catch (RuntimeException $e) {
                    $this->diagnostics[] = self::buildDiagnostic(
                        $this->positionMap,
                        $node->name,
                        $node->getStartLine(),
                        DiagnosticCode::Definition,
                        $e->getMessage(),
                    );
                }
                return null;
            }

            private static function buildDiagnostic(
                PositionMap $positionMap,
                ?\PhpParser\Node\Identifier $identifier,
                int $fallbackNikicLine,
                DiagnosticCode $code,
                string $message,
            ): Diagnostic {
                // Prefer the identifier's actual byte span — squiggles the class
                // name, not the whole line. Fall back to the full-line range when
                // position info is missing (synthetic nodes etc.).
                if ($identifier !== null && $identifier->getStartFilePos() >= 0) {
                    [$sl, $sc, $el, $ec] = $positionMap->rangeFromOffsets(
                        $identifier->getStartFilePos(),
                        $identifier->getEndFilePos() + 1,
                    );
                } else {
                    [$sl, $sc, $el, $ec] = $positionMap->fullLineRangeFromNikic($fallbackNikicLine);
                }
                return new Diagnostic($sl, $sc, $el, $ec, $message, code: $code);
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
    }

    /**
     * @param list<Node\Stmt> $ast
     * @param list<Diagnostic> $diagnostics
     */
    private function walkInstantiations(
        array $ast,
        Registry $registry,
        PositionMap $positionMap,
        array &$diagnostics,
    ): void {
        $visitor = new class($registry, $positionMap, $diagnostics) extends NodeVisitorAbstract {
            /** @param list<Diagnostic> $diagnostics */
            public function __construct(
                private readonly Registry $registry,
                private readonly PositionMap $positionMap,
                private array &$diagnostics,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if (!$node instanceof Name) {
                    return null;
                }
                $args = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
                $fqn = $node->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
                if (!is_array($args) || $args === [] || !is_string($fqn)) {
                    return null;
                }
                foreach ($args as $a) {
                    if (!$a->isConcrete()) {
                        return null;
                    }
                }
                try {
                    $this->registry->recordInstantiation($fqn, $args);
                } catch (RuntimeException $e) {
                    // Pin the range to the offending Name's byte span (squiggles
                    // just the generic identifier, e.g. `Box` in
                    // `new Box<int>()`) rather than the whole line. Fall back to
                    // the full line if position info is missing.
                    if ($node->getStartFilePos() >= 0 && $node->getEndFilePos() >= 0) {
                        [$sl, $sc, $el, $ec] = $this->positionMap->rangeFromOffsets(
                            $node->getStartFilePos(),
                            $node->getEndFilePos() + 1,
                        );
                    } else {
                        [$sl, $sc, $el, $ec] = $this->positionMap->fullLineRangeFromNikic($node->getStartLine());
                    }
                    $this->diagnostics[] = new Diagnostic(
                        startLine: $sl,
                        startCharacter: $sc,
                        endLine: $el,
                        endCharacter: $ec,
                        message: $e->getMessage(),
                        // Registry::recordInstantiation has two error paths
                        // (bound violation vs. hash collision). The triage helper
                        // distinguishes them by the message's leading phrase so
                        // editors / users can act on the right hint (raise
                        // XPHP_HASH_LENGTH vs. fix the bound).
                        code: DiagnosticCode::fromRegistryRecordInstantiationException($e),
                    );
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
    }
}
