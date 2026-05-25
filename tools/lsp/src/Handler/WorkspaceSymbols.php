<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;

/**
 * Walks every open document and collects the FQNs of every ClassLike (class,
 * interface, trait). Used by the completion handler to suggest candidates
 * inside `<…>` type-arg positions, and by the definition handler to resolve
 * Ctrl+click on a type-arg short name.
 *
 * Parses via the shared `ParsedDocumentCache` so an unchanged workspace
 * doesn't re-parse on every completion keystroke (the original MVP did,
 * which is O(N) parses per `<`).
 */
final readonly class WorkspaceSymbols
{
    public function __construct(
        private PhpactorWorkspace $workspace,
        private ParsedDocumentCache $cache,
    ) {
    }

    /**
     * @return list<string>  Fully-qualified ClassLike names across the open workspace.
     */
    public function allClassFqns(): array
    {
        $fqns = [];
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            foreach (self::collectFqns($result->ast) as $fqn) {
                $fqns[$fqn] = true;
            }
        }
        return array_keys($fqns);
    }

    /**
     * @return list<string>  Fully-qualified top-level function names across
     *                       the open workspace.  Methods and closures don't
     *                       appear here -- only `function name() {...}`
     *                       declarations at namespace level.
     */
    public function allFunctionFqns(): array
    {
        $fqns = [];
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            foreach (self::collectFunctionFqns($result->ast) as $fqn) {
                $fqns[$fqn] = true;
            }
        }
        return array_keys($fqns);
    }

    /**
     * Find the declaration site of a ClassLike (class, interface, trait)
     * across the open workspace and return its source location.  Matching is
     * by **short name** -- the last `\`-segment of each candidate's FQN.
     * First hit wins; cross-namespace collisions on the same short name
     * resolve to the first document the workspace iterator hands us, which
     * is good-enough for an MVP and trackable as a follow-up.
     *
     * Used by the definition handler for the type-arg Ctrl+click case:
     * `identity<User>(...)` -> click `User` -> resolve to the `class User`
     * declaration whatever namespace it lives in.
     *
     * Returns null when no open document defines a matching ClassLike.  Note
     * that we only see open documents; an unopened on-disk declaration won't
     * resolve until the user opens that file, same constraint
     * `XphpDefinitionHandler` already documents.
     */
    public function findClassByName(string $shortName): ?Location
    {
        if ($shortName === '') {
            return null;
        }
        // Phase 3 polish: when multiple open documents define a class
        // with the same short name (typical in repos with parallel
        // fixture trees -- `tests/Fixtures/User.xphp` shadowing the real
        // `src/Models/User.xphp`), prefer the non-fixture / non-vendor
        // candidate.  Rank-walk every match, lowest penalty wins; first
        // hit among equal-penalty matches preserves prior order.
        /** @var array{location: Location, penalty: int}|null $best */
        $best = null;
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            $found = self::findClassDeclaration($result->ast, $shortName);
            if ($found === null) {
                continue;
            }
            $positionMap = new PositionMap($item->text);
            [$startLine, $startChar] = $positionMap->offsetToPosition($found['startOffset']);
            [$endLine, $endChar] = $positionMap->offsetToPosition($found['endOffset']);
            $location = new Location(
                $uri,
                new Range(
                    new Position($startLine, $startChar),
                    new Position($endLine, $endChar),
                ),
            );
            $penalty = self::pathPenalty($uri);
            if ($best === null || $penalty < $best['penalty']) {
                $best = ['location' => $location, 'penalty' => $penalty];
            }
        }
        return $best === null ? null : $best['location'];
    }

    /**
     * Score a URI's "is this canonical workspace code" likelihood.
     * Lower is better.  Fixture / test / vendor paths get a positive
     * penalty so the canonical implementation outranks them when the
     * short name collides.  Match is case-insensitive on the path
     * segment to catch both `tests/` and `Tests/` etc.
     */
    private static function pathPenalty(string $uri): int
    {
        $needle = strtolower($uri);
        $penalty = 0;
        foreach (['/vendor/', '/tests/', '/test/', '/fixtures/', '/fixture/', '/stubs/', '/stub/'] as $segment) {
            if (str_contains($needle, $segment)) {
                $penalty += 10;
            }
        }
        return $penalty;
    }

    /**
     * @param list<Node\Stmt> $ast
     * @return list<string>
     */
    private static function collectFqns(array $ast): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<string> */
            public array $fqns = [];

            private string $currentNamespace = '';

            public function enterNode(Node $node): null
            {
                if ($node instanceof Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                }
                if ($node instanceof ClassLike && $node->name !== null) {
                    $short = $node->name->toString();
                    $this->fqns[] = $this->currentNamespace !== ''
                        ? $this->currentNamespace . '\\' . $short
                        : $short;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->fqns;
    }

    /**
     * @param list<Node\Stmt> $ast
     * @return list<string>
     */
    private static function collectFunctionFqns(array $ast): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<string> */
            public array $fqns = [];

            private string $currentNamespace = '';

            public function enterNode(Node $node): null
            {
                if ($node instanceof Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    return null;
                }
                // Only top-level Function_ nodes count; methods and closures
                // aren't surfaced as "functions" in completion candidates.
                if ($node instanceof Function_) {
                    $short = $node->name->toString();
                    $this->fqns[] = $this->currentNamespace !== ''
                        ? $this->currentNamespace . '\\' . $short
                        : $short;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->fqns;
    }

    /**
     * @param list<Node\Stmt> $ast
     * @return array{startOffset: int, endOffset: int}|null
     */
    private static function findClassDeclaration(array $ast, string $shortName): ?array
    {
        $visitor = new class($shortName) extends NodeVisitorAbstract {
            public ?int $startOffset = null;
            public ?int $endOffset = null;

            public function __construct(private readonly string $shortName)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($this->startOffset !== null) {
                    return null;
                }
                if (!$node instanceof ClassLike || $node->name === null) {
                    return null;
                }
                if ($node->name->toString() !== $this->shortName) {
                    return null;
                }
                // Range targets the class NAME token -- same convention as
                // `XphpDefinitionHandler::findTemplateInAst` so navigation
                // jumps land on the identifier, not the modifier-laden
                // opening line.
                $this->startOffset = $node->name->getStartFilePos();
                $this->endOffset = $node->name->getEndFilePos() + 1;
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        if ($visitor->startOffset === null || $visitor->endOffset === null) {
            return null;
        }
        return ['startOffset' => $visitor->startOffset, 'endOffset' => $visitor->endOffset];
    }
}
