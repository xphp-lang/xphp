<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\OptionalVersionedTextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\RenameFile;
use Phpactor\LanguageServerProtocol\TextDocumentEdit;
use Phpactor\LanguageServerProtocol\TextEdit;
use Phpactor\LanguageServerProtocol\WorkspaceEdit;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\FqnIndex;

/**
 * Builds the `WorkspaceEdit` payload for `textDocument/rename`.
 *
 * Strategy: reuse `ReferenceFinder` to enumerate every reference to the
 * symbol at the cursor (including the declaration), then for each
 * reference emit a `TextEdit` that swaps just the SHORT name -- the
 * last `\`-segment of any qualified Name node, or the whole identifier
 * for member-access positions.  This handles:
 *
 *   - `use App\Foo;`        -> `use App\Bar;`
 *   - `new Foo()`           -> `new Bar()`
 *   - `new App\Foo()`       -> `new App\Bar()`
 *   - `class Foo`           -> `class Bar`
 *   - `$x->foo()`           -> `$x->bar()`
 *   - `$x->prop`            -> `$x->renamed`
 *   - `Util::foo()`         -> `Util::bar()`
 *
 * MVP non-scope:
 *   - File rename for ClassLike targets.  PSR-4 expects `Foo.php`
 *     to declare `Foo`; once the class is renamed, the file should
 *     follow.  PhpStorm typically prompts the user; we leave that
 *     to a follow-up and let the user rename manually.
 *   - Renames that cross subclass-inherited members (covered by the
 *     same exact-FQN-match limit `ReferenceFinder` carries today).
 */
final class RenameProvider
{
    private const IDENTIFIER_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ReferenceFinder $finder,
        private readonly FqnIndex $fqnIndex,
        /**
         * Whether the client advertised support for the `rename` resource
         * operation in `workspace.workspaceEdit.resourceOperations`.  Set
         * by LspDispatcherFactory from `InitializeParams`.  PhpStorm's
         * LSP plugin (as of build 261.x) declares `["create"]` only and
         * silently drops `RenameFile` ops, so we elide them to keep the
         * response shape honest.  VS Code supports `["create", "rename",
         * "delete"]` and will apply them.
         */
        private readonly bool $clientSupportsRenameFile = true,
    ) {
    }

    /**
     * @throws InvalidRenameNameException when `$newName` is not a valid
     *     PHP identifier.  The handler converts this to an LSP error
     *     response with a friendly message.
     */
    public function rename(
        string $uri,
        int $byteOffset,
        string $newName,
        ?\Amp\CancellationToken $cancel = null,
    ): ?WorkspaceEdit {
        return $this->renameInternal($uri, $byteOffset, $newName, true, $cancel);
    }

    /**
     * Cycle L: text-edits-only variant for `workspace/willRenameFiles`.
     *
     * Same machinery as {@see rename} but never emits a `RenameFile`
     * resource operation -- the client is already in the middle of
     * renaming the file itself, so we just need to update the source
     * declaration + every cross-file reference.  Without this variant
     * the handler would respond with a `RenameFile` op that races
     * against the client's own pending rename and lands as a no-op
     * (the source file no longer exists at the old URI by then).
     */
    public function renameSymbolOnly(
        string $uri,
        int $byteOffset,
        string $newName,
        ?\Amp\CancellationToken $cancel = null,
    ): ?WorkspaceEdit {
        return $this->renameInternal($uri, $byteOffset, $newName, false, $cancel);
    }

    private function renameInternal(
        string $uri,
        int $byteOffset,
        string $newName,
        bool $allowFileRenameOp,
        ?\Amp\CancellationToken $cancel,
    ): ?WorkspaceEdit {
        if (!self::isValidIdentifier($newName)) {
            throw new InvalidRenameNameException(sprintf(
                '"%s" is not a valid PHP identifier; rename aborted.',
                $newName,
            ));
        }

        $oldShortName = $this->finder->shortNameAt($uri, $byteOffset);
        if ($oldShortName === null) {
            return null;
        }

        $locations = $this->finder->findReferences($uri, $byteOffset, true, $cancel);
        if ($locations === []) {
            return null;
        }

        // Group locations by URI so each TextDocumentEdit carries all
        // edits for its document.  Source text caching avoids re-reading
        // the same file once per reference within it.
        /** @var array<string, list<Location>> $byUri */
        $byUri = [];
        foreach ($locations as $loc) {
            $byUri[$loc->uri][] = $loc;
        }

        $documentChanges = [];
        foreach ($byUri as $editUri => $locs) {
            $source = $this->sourceFor($editUri);
            if ($source === null) {
                continue;
            }
            $positionMap = new PositionMap($source);
            $edits = [];
            foreach ($locs as $loc) {
                $edit = self::buildEditForReference($source, $positionMap, $loc, $oldShortName, $newName);
                if ($edit !== null) {
                    $edits[] = $edit;
                }
            }
            if ($edits === []) {
                continue;
            }
            $documentChanges[] = new TextDocumentEdit(
                new OptionalVersionedTextDocumentIdentifier($editUri),
                $edits,
            );
        }
        // Class rename: if the class is declared in a file whose basename
        // matches the OLD short name (the PSR-4 convention), also emit a
        // RenameFile op so `Foo.xphp` becomes `Bar.xphp` alongside the
        // class itself.  Skip when the file name doesn't follow PSR-4
        // (e.g. multiple classes per file, autoloader-less code, etc.) --
        // we'd rather under-rename than rename the wrong file.
        if ($allowFileRenameOp) {
            $renameFile = $this->buildFileRenameOp($uri, $byteOffset, $oldShortName, $newName);
            if ($renameFile !== null) {
                $documentChanges[] = $renameFile;
            }
        }

        if ($documentChanges === []) {
            return null;
        }
        return new WorkspaceEdit(null, $documentChanges);
    }

    /**
     * Emit a `RenameFile` resource operation when the target is a class
     * whose declaration file basename matches the old short name.  PSR-4
     * code expects `class Foo` to live in `Foo.xphp`; renaming the class
     * to `Bar` should follow with `Bar.xphp`.
     */
    private function buildFileRenameOp(
        string $uri,
        int $byteOffset,
        string $oldShortName,
        string $newName,
    ): ?RenameFile {
        if (!$this->clientSupportsRenameFile) {
            return null;
        }
        $target = $this->finder->targetAt($uri, $byteOffset);
        if ($target === null || ($target['kind'] ?? null) !== 'class') {
            return null;
        }
        $fqn = $target['fqn'] ?? null;
        if (!is_string($fqn)) {
            return null;
        }
        $location = $this->fqnIndex->locationForFqn($fqn);
        if ($location === null) {
            return null;
        }
        $oldUri = (string) $location['uri'];
        // Strip `file://` so we can manipulate the path; rebuild it for
        // the new URI.  Open-doc URIs may not carry the prefix; preserve
        // whichever form arrived.
        $hasFilePrefix = str_starts_with($oldUri, 'file://');
        $oldPath = $hasFilePrefix ? substr($oldUri, strlen('file://')) : $oldUri;
        $baseDir = dirname($oldPath);
        $basename = basename($oldPath);
        $ext = '';
        $dotPos = strrpos($basename, '.');
        if ($dotPos !== false && $dotPos > 0) {
            $ext = substr($basename, $dotPos); // includes the dot
            $stem = substr($basename, 0, $dotPos);
        } else {
            $stem = $basename;
        }
        if ($stem !== $oldShortName) {
            return null;
        }
        // dirname('/foo') is '/', not '' -- avoid the doubled slash
        // when concatenating.
        $newPath = rtrim($baseDir, '/') . '/' . $newName . $ext;
        $newUri = $hasFilePrefix ? 'file://' . $newPath : $newPath;
        return new RenameFile('rename', $oldUri, $newUri);
    }

    /**
     * Trim the `Range` to cover only the SHORT NAME portion of the
     * reference (the last `\`-segment).  For unqualified identifiers
     * the range is already the short name; for qualified Name nodes
     * (`App\Foo`) we shift `start` past the last `\` so the prefix
     * survives the rename.
     *
     * Alias safety: if the location's short-name text doesn't match
     * `$oldShortName`, the reference reached the target through an
     * alias (`use function App\Models\{foo as bar}`; `$x = bar();`).
     * Renaming the source function should preserve aliased call sites
     * -- they explicitly refer to the function via `bar`, not by its
     * source name.  Skip the edit and let the alias keep working
     * (`use function App\Models\{newName as bar}`).
     */
    private static function buildEditForReference(
        string $source,
        PositionMap $positionMap,
        Location $loc,
        string $oldShortName,
        string $newName,
    ): ?TextEdit {
        $startByte = $positionMap->positionToOffset($loc->range->start->line, $loc->range->start->character);
        $endByte = $positionMap->positionToOffset($loc->range->end->line, $loc->range->end->character);
        if ($endByte <= $startByte || $endByte > strlen($source)) {
            return null;
        }
        $text = substr($source, $startByte, $endByte - $startByte);
        // VarLikeIdentifier nodes (property name token) span `$name`
        // including the dollar sign; the target's short name doesn't
        // carry one, so strip a leading `$` from both sides of the
        // comparison.  Keep it OUT of the rename range -- the dollar
        // must survive the rename.
        $hasDollar = str_starts_with($text, '$');
        $textForCompare = $hasDollar ? substr($text, 1) : $text;
        $lastBackslash = strrpos($textForCompare, '\\');
        $shortText = $lastBackslash === false ? $textForCompare : substr($textForCompare, $lastBackslash + 1);
        if ($shortText !== $oldShortName) {
            return null;
        }
        if ($lastBackslash === false && !$hasDollar) {
            return new TextEdit($loc->range, $newName);
        }
        $offsetFromStart = ($hasDollar ? 1 : 0) + ($lastBackslash === false ? 0 : $lastBackslash + 1);
        $shortStartByte = $startByte + $offsetFromStart;
        [$line, $char] = $positionMap->offsetToPosition($shortStartByte);
        return new TextEdit(
            new Range(new Position($line, $char), $loc->range->end),
            $newName,
        );
    }

    private function sourceFor(string $uri): ?string
    {
        if ($this->workspace->has($uri)) {
            return $this->workspace->get($uri)->text;
        }
        if (str_starts_with($uri, 'file://')) {
            $path = substr($uri, strlen('file://'));
            $source = @file_get_contents($path);
            return $source !== false ? $source : null;
        }
        return null;
    }

    private static function isValidIdentifier(string $name): bool
    {
        return preg_match(self::IDENTIFIER_PATTERN, $name) === 1;
    }
}
