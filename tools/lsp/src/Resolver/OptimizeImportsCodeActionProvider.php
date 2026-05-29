<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;
use Phpactor\LanguageServerProtocol\CodeAction;
use Phpactor\LanguageServerProtocol\CodeActionKind;
use Phpactor\LanguageServerProtocol\OptionalVersionedTextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\TextDocumentEdit;
use Phpactor\LanguageServerProtocol\TextEdit;
use Phpactor\LanguageServerProtocol\WorkspaceEdit;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;

/**
 * Cycle F — codeAction Sprint C: Optimize Imports.
 *
 * Emits a single `source.organizeImports` CodeAction whenever the
 * file has at least one `use` whose alias is not referenced anywhere
 * in the AST (excluding the use statements themselves).  The
 * resulting WorkspaceEdit removes each unused use as a whole-line
 * delete (start-of-line through end-of-line + newline).
 *
 * Scope notes:
 *   - Only TYPE_NORMAL (class-like) imports are considered.  `use
 *     function` and `use const` go through separate symbol tables and
 *     would need their own per-kind scan; out of scope for V1.
 *   - GroupUse statements with a mix of used + unused aliases are
 *     handled coarsely: the WHOLE statement is removed if ALL its
 *     aliases are unused; otherwise it's left alone.  Per-alias
 *     surgery inside a GroupUse is a follow-up.
 *   - PHPDoc-only references aren't detected (parser doesn't expand
 *     `@param Foo` into Name nodes), so an import used only in a
 *     docblock looks unused.  Accept this V1 imprecision.
 */
final class OptimizeImportsCodeActionProvider
{
    public function __construct(
        private readonly ParsedDocumentCache $cache,
    ) {
    }

    /**
     * @return list<CodeAction>
     */
    public function actionsFor(string $uri, int $version, string $source): array
    {
        $result = $this->cache->getOrParse($uri, $version, $source);
        if ($result->ast === null || $result->ast === []) {
            return [];
        }
        $unused = self::collectUnusedUses($result->ast);
        if ($unused === []) {
            return [];
        }
        $positionMap = new PositionMap($source);
        $edits = [];
        foreach ($unused as $stmt) {
            $start = $stmt->getStartFilePos();
            $end = $stmt->getEndFilePos();
            if ($start < 0 || $end < 0) {
                continue;
            }
            [$startLine] = $positionMap->offsetToPosition($start);
            // Whole-line delete: [start-of-line, start-of-NEXT-line).
            $edits[] = new TextEdit(
                new Range(
                    new Position($startLine, 0),
                    new Position($startLine + 1, 0),
                ),
                '',
            );
        }
        if ($edits === []) {
            return [];
        }
        return [
            new CodeAction(
                title: 'Optimize imports',
                kind: CodeActionKind::SOURCE_ORGANIZE_IMPORTS,
                edit: new WorkspaceEdit(
                    null,
                    [new TextDocumentEdit(
                        new OptionalVersionedTextDocumentIdentifier($uri, $version),
                        $edits,
                    )],
                ),
            ),
        ];
    }

    /**
     * Walk the AST, collect every Use_/GroupUse, then walk it AGAIN
     * collecting Name references outside use statements.  A use is
     * "unused" when none of its aliases appear in the references set.
     *
     * @param list<Node\Stmt> $ast
     * @return list<Use_|GroupUse>
     */
    private static function collectUnusedUses(array $ast): array
    {
        // Top-level walk: namespaces wrap a body of stmts; we want
        // both the file-level and inside-namespace top-level stmts.
        $topLevel = $ast;
        foreach ($ast as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                $topLevel = $stmt->stmts;
                break;
            }
        }
        $useStmts = [];
        foreach ($topLevel as $stmt) {
            if ($stmt instanceof Use_ && self::isClassLikeUse($stmt->type)) {
                $useStmts[] = $stmt;
                continue;
            }
            if ($stmt instanceof GroupUse && self::isClassLikeUse($stmt->type)) {
                $useStmts[] = $stmt;
            }
        }
        if ($useStmts === []) {
            return [];
        }
        $referenced = self::collectReferencedShortNames($ast, $useStmts);
        $unused = [];
        foreach ($useStmts as $useStmt) {
            $aliases = self::aliasesOf($useStmt);
            if ($aliases === []) {
                continue;
            }
            $allUnused = true;
            foreach ($aliases as $alias) {
                if (isset($referenced[$alias])) {
                    $allUnused = false;
                    break;
                }
            }
            if ($allUnused) {
                $unused[] = $useStmt;
            }
        }
        return $unused;
    }

    private static function isClassLikeUse(int $type): bool
    {
        // TYPE_UNKNOWN: the parent statement carries the kind; both
        // Use_ and GroupUse default to TYPE_NORMAL when unset.
        return $type === Use_::TYPE_UNKNOWN || $type === Use_::TYPE_NORMAL;
    }

    /**
     * @return list<string>
     */
    private static function aliasesOf(Use_|GroupUse $stmt): array
    {
        $aliases = [];
        foreach ($stmt->uses as $useUse) {
            // Skip individual TYPE_FUNCTION / TYPE_CONSTANT entries
            // even inside a TYPE_NORMAL parent.
            if ($useUse instanceof UseUse
                && $useUse->type !== Use_::TYPE_UNKNOWN
                && $useUse->type !== Use_::TYPE_NORMAL
            ) {
                continue;
            }
            $aliases[] = $useUse->getAlias()->toString();
        }
        return $aliases;
    }

    /**
     * Walk every Name node that ISN'T inside one of the supplied use
     * statements and collect the first segment of its parts (the
     * short name).  This is what the use map binds; any reference
     * we find here shows that the corresponding use is alive.
     *
     * @param list<Node\Stmt>  $ast
     * @param list<Use_|GroupUse> $useStmts
     * @return array<string, true>
     */
    private static function collectReferencedShortNames(array $ast, array $useStmts): array
    {
        $useStartByteSet = [];
        foreach ($useStmts as $useStmt) {
            $start = $useStmt->getStartFilePos();
            $end = $useStmt->getEndFilePos();
            if ($start >= 0 && $end >= 0) {
                $useStartByteSet[] = [$start, $end];
            }
        }
        $referenced = [];
        $walker = static function (array $nodes) use (&$walker, &$referenced, $useStartByteSet): void {
            foreach ($nodes as $node) {
                if (!$node instanceof Node) {
                    continue;
                }
                // Skip nodes whose byte span is wholly inside a use
                // statement -- the alias TOKEN inside `use App\Foo;`
                // doesn't count as a reference.
                $start = $node->getStartFilePos();
                if ($start >= 0) {
                    foreach ($useStartByteSet as $range) {
                        if ($start >= $range[0] && $start <= $range[1]) {
                            continue 2;
                        }
                    }
                }
                if ($node instanceof Name) {
                    $parts = $node->getParts();
                    if ($parts !== []) {
                        $referenced[$parts[0]] = true;
                    }
                }
                foreach ($node->getSubNodeNames() as $subName) {
                    $sub = $node->$subName;
                    if (is_array($sub)) {
                        $walker($sub);
                    } elseif ($sub instanceof Node) {
                        $walker([$sub]);
                    }
                }
            }
        };
        $walker($ast);
        return $referenced;
    }
}
