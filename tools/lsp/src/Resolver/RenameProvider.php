<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\OptionalVersionedTextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\TextDocumentEdit;
use Phpactor\LanguageServerProtocol\TextEdit;
use Phpactor\LanguageServerProtocol\WorkspaceEdit;
use XPHP\Lsp\PositionMap;

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
    ) {
    }

    /**
     * @throws InvalidRenameNameException when `$newName` is not a valid
     *     PHP identifier.  The handler converts this to an LSP error
     *     response with a friendly message.
     */
    public function rename(string $uri, int $byteOffset, string $newName): ?WorkspaceEdit
    {
        if (!self::isValidIdentifier($newName)) {
            throw new InvalidRenameNameException(sprintf(
                '"%s" is not a valid PHP identifier; rename aborted.',
                $newName,
            ));
        }

        $locations = $this->finder->findReferences($uri, $byteOffset, true);
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
                $edit = self::buildEditForReference($source, $positionMap, $loc, $newName);
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
        if ($documentChanges === []) {
            return null;
        }
        return new WorkspaceEdit(null, $documentChanges);
    }

    /**
     * Trim the `Range` to cover only the SHORT NAME portion of the
     * reference (the last `\`-segment).  For unqualified identifiers
     * the range is already the short name; for qualified Name nodes
     * (`App\Foo`) we shift `start` past the last `\` so the prefix
     * survives the rename.
     */
    private static function buildEditForReference(
        string $source,
        PositionMap $positionMap,
        Location $loc,
        string $newName,
    ): ?TextEdit {
        $startByte = $positionMap->positionToOffset($loc->range->start->line, $loc->range->start->character);
        $endByte = $positionMap->positionToOffset($loc->range->end->line, $loc->range->end->character);
        if ($endByte <= $startByte || $endByte > strlen($source)) {
            return null;
        }
        $text = substr($source, $startByte, $endByte - $startByte);
        $lastBackslash = strrpos($text, '\\');
        if ($lastBackslash === false) {
            return new TextEdit($loc->range, $newName);
        }
        $shortStartByte = $startByte + $lastBackslash + 1;
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
