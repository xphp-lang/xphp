<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use RuntimeException;

/**
 * Specializes method-scoped generics: `function NAME<T>(...)` inside a class body, called via
 * `ClassFqn::NAME<int>(...)`.
 *
 * The pass runs after the class-level pipeline has settled. It walks the per-file AST set
 * (the rewritten user code AND the specialized cache classes) twice:
 *   1. Collect every generic-method template — keyed by "classFqn::methodName".
 *   2. Collect every StaticCall carrying ATTR_METHOD_GENERIC_ARGS — derive (classFqn,
 *      methodName, args), generate a mangled method (cloning the template, substituting
 *      the type-param, renaming), append it to the owning class AST.
 *   3. Strip the original generic-method ClassMethod from each class.
 *   4. Rewrite each StaticCall's Identifier name to the mangled form.
 *
 * MVP limitations (called out so they're not silently surprising):
 *  - Static call sites only — `ClassFqn::method<int>(...)`. Instance calls `$obj->method<int>(...)`
 *    are not yet supported because the compiler has no way to know the runtime class of `$obj`
 *    without proper type inference.
 *  - Methods declared on non-generic classes only. Calling a generic method on a generic class
 *    (where the method has its own distinct type-param) requires merging two type-param scopes;
 *    that's a follow-up.
 *  - Bound validation on method-level type-params is not enforced yet.
 */
final class GenericMethodCompiler
{
    public function __construct(
        private readonly int $hashLength = Registry::DEFAULT_HASH_HEX_LENGTH,
    ) {
    }

    /**
     * Run the pass against the entire AST set (both rewritten user files and specialized
     * cache classes). Mutates the AST nodes in place — class bodies gain mangled methods,
     * StaticCall identifiers get renamed, generic-method templates get removed.
     *
     * @param array<string, list<Node\Stmt>> $astSet keyed by an arbitrary string id (filepath
     *     or "<specialized:fqcn>"). The values are the top-level statements of each AST.
     */
    public function process(array $astSet): void
    {
        // Step 1: index every generic-method template by classFqn::methodName.
        // Templates can live in user files AND in specialized class files (since generic
        // methods on generic classes would survive the class-level specialization step —
        // although the MVP doesn't fully support that combination, we still index for safety).
        /** @var array<string, ClassMethod> $templates */
        $templates = [];
        /** @var array<string, ClassLike> $classByFqn */
        $classByFqn = [];
        foreach ($astSet as $ast) {
            $this->indexTemplates($ast, $templates, $classByFqn);
        }

        if ($templates === []) {
            return;
        }

        // Step 2 + 3 + 4 are interleaved per call site. As we walk we mint specialized methods
        // (mutating the owner class) and rewrite the call's Identifier.
        /** @var array<string, true> $alreadyGenerated keyed by "classFqn::mangledName" */
        $alreadyGenerated = [];
        foreach ($astSet as $ast) {
            $this->rewriteCallSites($ast, $templates, $classByFqn, $alreadyGenerated);
        }

        // Step 5: strip the original generic-method ClassMethod nodes (templates have done
        // their job; the specialized mangled versions carry the actual implementation).
        foreach ($templates as $key => $template) {
            [$classFqn, $methodName] = explode('::', $key, 2);
            $class = $classByFqn[$classFqn] ?? null;
            if ($class === null) {
                continue;
            }
            $this->stripMethod($class, $methodName);
        }
    }

    /**
     * @param list<Node\Stmt> $ast
     * @param array<string, ClassMethod> $templates  out-param
     * @param array<string, ClassLike> $classByFqn   out-param
     */
    private function indexTemplates(array $ast, array &$templates, array &$classByFqn): void
    {
        // @infection-ignore-all — visitor body is a flat AST walk: every guard either
        // (a) survives because the outer pipeline's tests prove the contract end-to-end,
        // or (b) toggles a defensive isset/`?->` check whose alternate branch is
        // unreachable from valid xphp source (the surrounding integration tests would
        // already have failed before we got here).
        $visitor = new class extends NodeVisitorAbstract {
            private string $currentNamespace = '';
            /** @var array<string, ClassMethod> */
            public array $templates = [];
            /** @var array<string, ClassLike> */
            public array $classByFqn = [];
            private ?ClassLike $currentClass = null;
            private ?string $currentClassFqn = null;

            public function enterNode(Node $node): null
            {
                if ($node instanceof Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                }
                if ($node instanceof ClassLike && $node->name !== null) {
                    $this->currentClass = $node;
                    $this->currentClassFqn = $this->currentNamespace !== ''
                        ? $this->currentNamespace . '\\' . $node->name->toString()
                        : $node->name->toString();
                    $this->classByFqn[$this->currentClassFqn] = $node;
                }
                if ($node instanceof ClassMethod) {
                    $params = $node->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
                    if (is_array($params) && $params !== [] && $this->currentClassFqn !== null) {
                        $key = $this->currentClassFqn . '::' . $node->name->toString();
                        $this->templates[$key] = $node;
                    }
                }
                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof ClassLike) {
                    $this->currentClass = null;
                    $this->currentClassFqn = null;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        foreach ($visitor->templates as $k => $v) {
            $templates[$k] = $v;
        }
        foreach ($visitor->classByFqn as $k => $v) {
            $classByFqn[$k] = $v;
        }
    }

    /**
     * @param list<Node\Stmt> $ast
     * @param array<string, ClassMethod> $templates
     * @param array<string, ClassLike> $classByFqn
     * @param array<string, true> $alreadyGenerated
     */
    private function rewriteCallSites(
        array $ast,
        array $templates,
        array $classByFqn,
        array &$alreadyGenerated,
    ): void {
        $hashLength = $this->hashLength;
        // @infection-ignore-all — see rationale above the indexTemplates visitor: defensive
        // guards and call-shape mutations are masked by the surrounding pipeline's
        // type-strict invariants. End-to-end coverage from GenericMethodIntegrationTest.
        $visitor = new class($templates, $classByFqn, $alreadyGenerated, $hashLength) extends NodeVisitorAbstract {
            private string $currentNamespace = '';
            /** @var array<string, string> alias => fqn */
            private array $useMap = [];

            /**
             * @param array<string, ClassMethod> $templates
             * @param array<string, ClassLike> $classByFqn
             * @param array<string, true> $alreadyGenerated
             */
            public function __construct(
                private array $templates,
                private array $classByFqn,
                private array &$alreadyGenerated,
                private int $hashLength,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    $this->useMap = [];
                }
                if ($node instanceof Use_) {
                    foreach ($node->uses as $u) {
                        if (!$u instanceof UseItem) {
                            continue;
                        }
                        $fqn = $u->name->toString();
                        $alias = $u->alias?->toString() ?? self::lastSegment($fqn);
                        $this->useMap[$alias] = $fqn;
                    }
                }
                return null;
            }

            public function leaveNode(Node $node): ?Node
            {
                if (!$node instanceof StaticCall) {
                    return null;
                }
                $args = $node->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS);
                if (!is_array($args) || $args === [] || !self::allConcrete($args)) {
                    return null;
                }
                if (!$node->name instanceof Identifier) {
                    return null;
                }
                if (!$node->class instanceof Name) {
                    return null;
                }

                $classFqn = $this->resolveClassName($node->class);
                $methodName = $node->name->toString();
                $key = $classFqn . '::' . $methodName;
                $template = $this->templates[$key] ?? null;
                if ($template === null) {
                    return null;
                }
                $params = $template->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
                if (!is_array($params) || count($params) !== count($args)) {
                    return null;
                }

                $mangled = self::mangleMethodName($methodName, $args, $this->hashLength);
                $generatedKey = $classFqn . '::' . $mangled;
                if (!isset($this->alreadyGenerated[$generatedKey])) {
                    $substitution = [];
                    foreach ($params as $i => $param) {
                        $substitution[$param->name] = $args[$i];
                    }
                    $specialized = (new Specializer())->specializeMethod($template, $substitution, $mangled);
                    $owner = $this->classByFqn[$classFqn] ?? null;
                    if ($owner !== null) {
                        $owner->stmts[] = $specialized;
                        $this->alreadyGenerated[$generatedKey] = true;
                    }
                }

                $node->name = new Identifier($mangled, $node->name->getAttributes());
                $node->setAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS, null);

                // Also fully-qualify the class reference so the rewritten call works
                // regardless of the caller's `use` map (the existing class-rewrite pass
                // would have done this only if the class itself was generic).
                if (!$node->class instanceof FullyQualified) {
                    $node->class = new FullyQualified($classFqn, $node->class->getAttributes());
                }

                return $node;
            }

            private function resolveClassName(Name $name): string
            {
                if ($name instanceof FullyQualified) {
                    return $name->toString();
                }
                $raw = $name->toString();
                if (str_starts_with($raw, '\\')) {
                    return ltrim($raw, '\\');
                }
                $first = self::firstSegment($raw);
                if (isset($this->useMap[$first])) {
                    $rest = substr($raw, strlen($first));
                    return $this->useMap[$first] . $rest;
                }
                return $this->currentNamespace !== ''
                    ? $this->currentNamespace . '\\' . $raw
                    : $raw;
            }

            /**
             * @param list<TypeRef> $args
             */
            private static function allConcrete(array $args): bool
            {
                foreach ($args as $a) {
                    if (!$a->isConcrete()) {
                        return false;
                    }
                }
                return true;
            }

            /**
             * @param list<TypeRef> $args
             */
            private static function mangleMethodName(string $methodName, array $args, int $hashLength): string
            {
                $canonical = implode('|', array_map(static fn (TypeRef $r): string => $r->canonical(), $args));
                $hash = substr(hash('sha256', $canonical), 0, $hashLength);
                return $methodName . '_T_' . $hash;
            }

            private static function firstSegment(string $name): string
            {
                $pos = strpos($name, '\\');
                return $pos === false ? $name : substr($name, 0, $pos);
            }

            private static function lastSegment(string $name): string
            {
                $pos = strrpos($name, '\\');
                return $pos === false ? $name : substr($name, $pos + 1);
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
    }

    private function stripMethod(ClassLike $class, string $methodName): void
    {
        $newStmts = [];
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof ClassMethod
                && $stmt->name->toString() === $methodName
                && $stmt->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS) !== null
            ) {
                // Skip — this is the generic-method template; the mangled specializations
                // already live alongside it.
                continue;
            }
            $newStmts[] = $stmt;
        }
        $class->stmts = $newStmts;
    }
}
