<?php

declare(strict_types=1);

namespace XPHP\Lsp\Reflection;

use Phpactor\TextDocument\TextDocument;
use Phpactor\TextDocument\TextDocumentBuilder;
use Phpactor\WorseReflection\Core\Exception\SourceNotFound;
use Phpactor\WorseReflection\Core\Name;
use Phpactor\WorseReflection\Core\SourceCodeLocator;
use XPHP\Lsp\Stderr;
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
    /**
     * FQN -> built TextDocument.  Avoids re-reading and re-stripping the
     * same file across the dozens of `locate()` calls worse-reflection
     * issues for one FQN within a single user request (~12 lookups of
     * `ReflectionMethod` for a single hover, per prod log analysis).
     *
     * @var array<string, TextDocument>
     */
    private array $hitCache = [];

    /**
     * Set of FQNs we've already logged a miss for.  Suppresses the
     * `[xphp-lsp locator] miss ...` stderr spam (the same FQN missing
     * 30+ times per request).  Still throws `SourceNotFound` on every
     * call (worse-reflection's chain needs the exception to fall
     * through to the next locator); we just don't repeat the log.
     *
     * @var array<string, true>
     */
    private array $loggedMisses = [];

    /**
     * Snapshot of {@see FqnIndex::filesystemVersion} when the caches
     * above were populated.  An increment (from
     * {@see FqnIndex::invalidateFilesystem}) flushes both.
     */
    private int $observedVersion = -1;

    public function __construct(
        private readonly FqnIndex $index,
        private readonly XphpSourceParser $parser,
        private readonly string $rootPath,
    ) {
    }

    public function locate(Name $name): TextDocument
    {
        $this->flushIfStale();

        $needle = ltrim((string) $name, '\\');

        // Hit-cache: same FQN looked up multiple times in the same
        // request returns the cached TextDocument without re-reading
        // the file or running the strip pass.
        if (isset($this->hitCache[$needle])) {
            return $this->hitCache[$needle];
        }

        // Fix L: short-circuit when the FQN is a namespace-resolved
        // type-param.  nikic's name resolver attaches the enclosing
        // namespace to every bare identifier, so a hover on `T` inside
        // `namespace App\Containers` becomes `App\Containers\T`.  That
        // never resolves to a class, but pre-fix-L we'd still consult
        // pathFor, log a miss, throw -- repeated dozens of times per
        // request when worse-reflection's chain re-asks.  Now we
        // recognise the type-param shape and bail silently.
        if ($this->index->isTypeParamFqn($needle)) {
            throw new SourceNotFound(sprintf(
                '"%s" is a type-param reference, not a class FQN',
                $needle,
            ));
        }

        $path = $this->index->pathFor($needle);

        if ($path === null) {
            // Fix 3: when `$needle` is a namespace-resolved reference
            // to a built-in PHP function (e.g. `App\Demos\gettype`,
            // `XPHP\Lsp\Resolver\max`), suppress the stderr miss line
            // AND differentiate the exception message so tests can
            // observe the path.  nikic's name resolver emits the
            // namespaced form speculatively; PHP's runtime falls back
            // to global-scope function lookup, but the locator never
            // gets that fallback because functions are never
            // registered with `pathFor`.  Still throw SourceNotFound
            // so worse-reflection's chain falls through normally.
            if ($this->index->isBareBuiltinFunctionFqn($needle)) {
                throw new SourceNotFound(sprintf(
                    '"%s" is a namespace-resolved global function reference, not a class FQN',
                    $needle,
                ));
            }
            if (!isset($this->loggedMisses[$needle])) {
                $this->loggedMisses[$needle] = true;
                Stderr::write(sprintf(
                    "[xphp-lsp locator] miss %s (no declaration indexed under %s)\n",
                    $needle,
                    $this->rootPath,
                ));
            }
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

        $document = TextDocumentBuilder::create($stripped)
            ->uri($path)
            ->language('php')
            ->build();

        $this->hitCache[$needle] = $document;
        return $document;
    }

    /**
     * Flush the hit-cache + logged-miss set when the underlying
     * {@see FqnIndex} bumps its filesystem version.  Called at the
     * start of every {@see locate} to keep the caches consistent
     * with the index's freshness without requiring the index to call
     * back into the locator on invalidation.
     */
    private function flushIfStale(): void
    {
        $current = $this->index->filesystemVersion();
        if ($current === $this->observedVersion) {
            return;
        }
        $this->hitCache = [];
        $this->loggedMisses = [];
        $this->observedVersion = $current;
    }

    private static function shouldStrip(string $path): bool
    {
        return str_ends_with($path, '.xphp');
    }
}
