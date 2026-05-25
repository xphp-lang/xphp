<?php

declare(strict_types=1);

namespace XPHP\Lsp\Reflection;

use Phpactor\TextDocument\TextDocument;
use Phpactor\TextDocument\TextDocumentBuilder;
use Phpactor\WorseReflection\Core\Exception\SourceNotFound;
use Phpactor\WorseReflection\Core\Name;
use Phpactor\WorseReflection\Core\SourceCodeLocator;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * worse-reflection adapter: resolves an FQN to a stripped `TextDocument`
 * by delegating the actual indexing to `FqnIndex`.
 *
 * Phase-0 refactor: this class used to own its own FQN -> path walk +
 * map, parallel to the workspace-side walk in `WorkspaceSymbols` / the
 * AST-attribute walk in `WorkspaceClassLikeLookup`.  All three are now
 * unified behind `FqnIndex`, with this locator a thin adapter that
 * (a) asks for a path, (b) reads + strips the file content,
 * (c) hands the result back to worse-reflection's locator chain.
 *
 * Behaviour preserved:
 *  - `.xphp` files go through `XphpSourceParser::strip()` so generic
 *    `<...>` clauses become equal-length whitespace and worse-reflection's
 *    tolerant parser ingests them cleanly.
 *  - `.php` files are returned as-is.
 *  - `SourceNotFound` surfaces when the FQN isn't indexed OR the indexed
 *    file disappeared from disk between map build and lookup.
 *  - `[xphp-lsp locator]` stderr traces still fire so production
 *    diagnostics from prior sessions are still grep-able.
 */
final class FilesystemSourceLocator implements SourceCodeLocator
{
    public function __construct(
        private readonly FqnIndex $index,
        private readonly XphpSourceParser $parser,
        private readonly string $rootPath,
    ) {
    }

    public function locate(Name $name): TextDocument
    {
        $needle = ltrim((string) $name, '\\');
        $path = $this->index->pathFor($needle);

        if ($path === null) {
            @fwrite(STDERR, sprintf(
                "[xphp-lsp locator] miss %s (no declaration indexed under %s)\n",
                $needle,
                $this->rootPath,
            ));
            throw new SourceNotFound(sprintf(
                'No file under "%s" declares "%s"',
                $this->rootPath,
                $needle,
            ));
        }

        // Filesystem paths only -- open-doc URIs (file:// or otherwise)
        // route through the `WorkspaceSourceLocator` upstream in the
        // chain.  pathFor() may return an open-doc URI; in that case
        // bail and let worse-reflection's chain fall through.
        if (str_contains($path, '://')) {
            throw new SourceNotFound(sprintf(
                'FQN "%s" is open in workspace; defer to WorkspaceSourceLocator',
                $needle,
            ));
        }

        $source = @file_get_contents($path);
        if ($source === false) {
            throw new SourceNotFound(sprintf(
                'Indexed source disappeared from disk: "%s"',
                $path,
            ));
        }

        $stripped = self::shouldStrip($path) ? $this->parser->strip($source) : $source;

        return TextDocumentBuilder::create($stripped)
            ->uri($path)
            ->language('php')
            ->build();
    }

    private static function shouldStrip(string $path): bool
    {
        return str_ends_with($path, '.xphp');
    }
}
