<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use Phpactor\LanguageServerProtocol\CodeAction;
use Phpactor\LanguageServerProtocol\CodeActionKind;
use Phpactor\LanguageServerProtocol\OptionalVersionedTextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\TextDocumentEdit;
use Phpactor\LanguageServerProtocol\TextEdit;
use Phpactor\LanguageServerProtocol\WorkspaceEdit;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\AstPositionResolver;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\FqnIndex;

/**
 * Cycle B — codeAction Sprint A.
 *
 * Two refactor.rewrite quick-fixes triggered by the cursor's `Name`
 * node:
 *
 *  - **Import class**: cursor on a single-segment `Name` (e.g.
 *    `User`) whose short name is NOT in the file's `use` map and
 *    NOT declared in the current namespace.  The workspace's
 *    `FqnIndex` is consulted for all FQNs whose tail segment
 *    matches; one CodeAction per candidate is emitted with a
 *    WorkspaceEdit that inserts `use App\Models\User;` in the
 *    file's import block.
 *
 *  - **Simplify FQN**: cursor on a fully-qualified `\App\Models\User`
 *    Name node.  Two-part edit: insert `use App\Models\User;` in the
 *    import block, replace the FQN text with the short name.
 *    Skipped when the short name is already bound to a DIFFERENT
 *    FQN in the use map (would conflict).
 *
 * Both fixes use `CodeActionKind::REFACTOR_REWRITE` -- intentionally
 * NOT `quickfix` because PhpStorm filters quickfixes by attached
 * diagnostic; rewrite actions surface from the lightbulb / Alt+Enter
 * regardless of diagnostics, which matches the prod feel users expect
 * for "I know I want to import this even though there's no error".
 */
final class ImportCodeActionProvider
{
    public function __construct(
        private readonly FqnIndex $fqnIndex,
        private readonly ParsedDocumentCache $cache,
    ) {
    }

    /**
     * @return list<CodeAction>
     */
    public function actionsAt(string $uri, int $version, string $source, int $offset): array
    {
        $result = $this->cache->getOrParse($uri, $version, $source);
        if ($result->ast === null || $result->ast === []) {
            return [];
        }
        $hit = AstPositionResolver::nameAtOffset($result->ast, $offset);
        if ($hit === null) {
            return [];
        }
        $name = $hit['name'];

        $context = ClassNameImportContext::extract($result->ast);
        $useMap = $context->useMap;
        $namespace = $context->namespace;
        $positionMap = new PositionMap($source);
        $insertion = $this->computeInsertionPosition($result->ast, $positionMap);

        if ($name->isFullyQualified()) {
            return $this->simplifyFqnActions($uri, $version, $name, $useMap, $positionMap, $insertion);
        }
        if (!$name->isUnqualified()) {
            // Multi-segment unqualified (Foo\Bar) -- ambiguous without
            // resolving the leading segment; out of scope for V1.
            return [];
        }
        return $this->importClassActions($uri, $version, $name, $useMap, $namespace, $insertion);
    }

    /**
     * Decide where the inserted `use ...;` line should go.  Preference
     * order:
     *
     *   1. Just after the LAST existing use statement inside the
     *      file's namespace block -- alphabetical insertion is a
     *      nice-to-have follow-up but for V1 we append.
     *   2. Just after the `namespace ...;` declaration when no
     *      existing use statements are present.
     *   3. Just after the `<?php` open tag for files without a
     *      namespace declaration.
     *
     * Returns the LSP {line, character} where the `use ...;\n` text
     * should be inserted (zero-width range insertion).
     */
    private function computeInsertionPosition(array $ast, PositionMap $positionMap): Position
    {
        $namespaceNode = null;
        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_) {
                $namespaceNode = $stmt;
                break;
            }
        }
        $stmts = $namespaceNode?->stmts ?? $ast;

        $lastUseEnd = null;
        $firstNonUseStart = null;
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Use_ || $stmt instanceof GroupUse) {
                $end = $stmt->getEndFilePos();
                if ($end >= 0) {
                    $lastUseEnd = $end;
                }
                continue;
            }
            if ($firstNonUseStart === null) {
                $start = $stmt->getStartFilePos();
                if ($start >= 0) {
                    $firstNonUseStart = $start;
                }
            }
        }

        if ($lastUseEnd !== null) {
            // Append after the last use -- insertion is at the start of
            // the line AFTER the `;`.
            [$line] = $positionMap->offsetToPosition($lastUseEnd + 1);
            return new Position($line + 1, 0);
        }

        if ($namespaceNode !== null) {
            $endPos = $namespaceNode->getStartFilePos();
            // namespace nodes wrap their inner statements; we want the
            // line right after `namespace ... ;`, not the closing `}`.
            // Find the FIRST inner statement start (if any) and insert
            // on its line; otherwise after the namespace declaration.
            if ($firstNonUseStart !== null) {
                [$line] = $positionMap->offsetToPosition($firstNonUseStart);
                return new Position($line, 0);
            }
            if ($endPos >= 0) {
                [$line] = $positionMap->offsetToPosition($endPos);
                // Two lines down: blank line after `namespace`, then the use.
                return new Position($line + 2, 0);
            }
        }

        // No namespace, no use -- file starts with `<?php`.  Insert on
        // line 1 (right after the `<?php` line).
        return new Position(1, 0);
    }

    /**
     * @param array<string, string> $useMap
     * @return list<CodeAction>
     */
    private function importClassActions(
        string $uri,
        int $version,
        Name $name,
        array $useMap,
        string $namespace,
        Position $insertion,
    ): array {
        $shortName = $name->toString();
        if (!ClassFqnPredicate::is($shortName)) {
            return [];
        }
        if (isset($useMap[$shortName])) {
            return [];
        }
        // Same-namespace short-name resolution: if `App\Demos\User`
        // already exists for `namespace App\Demos`, no import needed.
        $sameNamespaceFqn = $namespace !== '' ? $namespace . '\\' . $shortName : $shortName;

        $candidates = $this->fqnIndex->fqnsByShortName($shortName);
        $actions = [];
        foreach ($candidates as $fqn) {
            if ($fqn === $sameNamespaceFqn) {
                continue;
            }
            $actions[] = new CodeAction(
                title: sprintf('Import %s', $fqn),
                kind: CodeActionKind::REFACTOR_REWRITE,
                edit: $this->buildImportEdit($uri, $version, $fqn, $insertion),
            );
        }
        return $actions;
    }

    /**
     * @param array<string, string> $useMap
     * @return list<CodeAction>
     */
    private function simplifyFqnActions(
        string $uri,
        int $version,
        Name $name,
        array $useMap,
        PositionMap $positionMap,
        Position $insertion,
    ): array {
        $fqn = $name->toString();
        $parts = explode('\\', $fqn);
        $shortName = end($parts);
        if ($shortName === false || $shortName === '') {
            return [];
        }
        // Conflict: short name already bound to a different FQN.
        if (isset($useMap[$shortName]) && $useMap[$shortName] !== $fqn) {
            return [];
        }
        $start = $name->getStartFilePos();
        $end = $name->getEndFilePos();
        if ($start < 0 || $end < 0) {
            return [];
        }
        [$startLine, $startChar] = $positionMap->offsetToPosition($start);
        [$endLine, $endChar] = $positionMap->offsetToPosition($end + 1);
        $replace = new TextEdit(
            new Range(new Position($startLine, $startChar), new Position($endLine, $endChar)),
            $shortName,
        );
        $edits = [$replace];
        // Skip the use insertion when the FQN is already imported (we
        // just shorten the reference; no new use is needed).
        if (!isset($useMap[$shortName])) {
            $edits[] = new TextEdit(
                new Range($insertion, $insertion),
                sprintf("use %s;\n", $fqn),
            );
        }
        return [
            new CodeAction(
                title: sprintf('Simplify %s', $fqn),
                kind: CodeActionKind::REFACTOR_REWRITE,
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

    private function buildImportEdit(string $uri, int $version, string $fqn, Position $insertion): WorkspaceEdit
    {
        $edit = new TextEdit(
            new Range($insertion, $insertion),
            sprintf("use %s;\n", $fqn),
        );
        return new WorkspaceEdit(
            null,
            [new TextDocumentEdit(
                new OptionalVersionedTextDocumentIdentifier($uri, $version),
                [$edit],
            )],
        );
    }
}
