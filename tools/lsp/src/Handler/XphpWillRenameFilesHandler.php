<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\CancellationToken;
use Amp\Promise;
use Amp\Success;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Namespace_;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\FileOperationFilter;
use Phpactor\LanguageServerProtocol\FileOperationOptions;
use Phpactor\LanguageServerProtocol\FileOperationPattern;
use Phpactor\LanguageServerProtocol\FileOperationRegistrationOptions;
use Phpactor\LanguageServerProtocol\RenameFilesParams;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use Phpactor\LanguageServerProtocol\WorkspaceEdit;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Resolver\NamespaceMoveProvider;
use XPHP\Lsp\Resolver\RenameProvider;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * `workspace/willRenameFiles` -- Cycle L Half B.
 *
 * When the client (the xphp PhpStorm plugin, or VS Code's project-tree
 * rename) is about to rename a file, this hook returns a `WorkspaceEdit`
 * containing every text edit needed to follow PSR-4: rename the top-
 * level ClassLike declaration to match the new basename, plus rename
 * every workspace reference to it.
 *
 * The client applies these text edits THEN performs the file rename
 * itself (per LSP spec for `workspace/willRenameFiles`).  We do NOT
 * emit a `RenameFile` resource op here -- the client already owns the
 * file move.  This is why we route through {@see RenameProvider::renameSymbolOnly}
 * rather than the standard `rename()` path.
 *
 * Safety: silently returns `null` (no edits) when the file doesn't
 * follow the PSR-4 single-declaration convention.  The plugin shows
 * the user a confirmation modal in those cases; if they decline, no
 * edits are applied.  If they accept, the plugin renames the file
 * without consulting us and the source stays in its pre-rename state
 * -- safe-but-stale, fixable by hand.
 *
 * Capability: advertised under `workspace.fileOperations.willRename`
 * with a `**\/*.xphp` + `**\/*.php` filter so the client only sends
 * notifications for PHP-shaped files.
 */
final class XphpWillRenameFilesHandler implements Handler, CanRegisterCapabilities
{
    /**
     * Per-identifier regex matching the same shape PHP itself accepts:
     * `[a-zA-Z_][a-zA-Z0-9_]*`.  Used both for filtering the new
     * basename (must be a valid identifier candidate) and for the
     * old-basename PSR-4 match.
     */
    private const IDENTIFIER_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $cache,
        private readonly XphpSourceParser $parser,
        private readonly RenameProvider $renameProvider,
        private readonly NamespaceMoveProvider $namespaceMoveProvider,
    ) {
    }

    public function methods(): array
    {
        return [
            'workspace/willRenameFiles' => 'willRenameFiles',
        ];
    }

    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        // Spec shape per LSP 3.16 §workspace.fileOperations: declare a
        // FileOperationRegistrationOptions with one or more glob
        // filters.  We register the SAME filters as didChangeWatchedFiles
        // -- xphp and plain PHP files (the latter because composer's
        // PSR-4 convention applies to both, and PhpStorm may rename
        // either through the same gesture).
        $opts = new FileOperationRegistrationOptions([
            new FileOperationFilter(new FileOperationPattern('**/*.xphp'), 'file'),
            new FileOperationFilter(new FileOperationPattern('**/*.php'), 'file'),
        ]);
        $capabilities->workspace = [
            'fileOperations' => new FileOperationOptions(
                willRename: $opts,
            ),
        ];
    }

    /**
     * @return Promise<WorkspaceEdit|null>
     */
    public function willRenameFiles(RenameFilesParams $params, ?CancellationToken $cancel = null): Promise
    {
        if ($cancel !== null && $cancel->isRequested()) {
            return new Success(null);
        }
        $documentChanges = [];
        foreach ($params->files as $file) {
            if ($cancel !== null && $cancel->isRequested()) {
                return new Success(null);
            }
            $edit = $this->editsForFileRename($file->oldUri, $file->newUri, $cancel);
            if ($edit === null) {
                continue;
            }
            foreach ($edit->documentChanges ?? [] as $change) {
                $documentChanges[] = $change;
            }
        }
        if ($documentChanges === []) {
            return new Success(null);
        }
        return new Success(new WorkspaceEdit(null, $documentChanges));
    }

    /**
     * Compute the rename WorkspaceEdit for a single file move.  Returns
     * null when the file isn't a safe PSR-4 candidate (multi-class
     * file, basename mismatch, basename isn't an identifier, or new
     * basename equals old).
     *
     * IntelliJ caveat (prod-test 2026-05-30 16:18 log id=59): the LSP
     * spec says `workspace/willRenameFiles` is sent BEFORE the file
     * is renamed, but PhpStorm sends it 4ms AFTER -- the order on
     * the wire is `didClose(old) + didChangeWatchedFiles(deleted+
     * created) + willRenameFiles(old->new) + didOpen(new)`.  By the
     * time we run, the OLD file is gone (off disk AND out of
     * workspace) and the NEW file isn't open yet.  We probe both
     * URIs for source so the handler works under both spec-compliant
     * clients (VS Code) and IntelliJ's post-hoc dispatch.
     *
     * The rename pipeline still uses the OLD URI: that's where the
     * class WAS declared (and where ReferenceFinder's findReferences
     * needs to anchor its cursor walk).  When the OLD file is gone
     * but the NEW file has matching content, we feed the NEW file's
     * source into the OLD URI's analysis -- the resulting edits
     * target the OLD URI, which the client interprets correctly
     * (the file will be at the new path by the time it applies the
     * edits, and most clients re-target outdated URIs via the
     * applier's lookup).
     */
    private function editsForFileRename(
        string $oldUri,
        string $newUri,
        ?CancellationToken $cancel,
    ): ?WorkspaceEdit {
        $oldStem = self::basenameStem($oldUri);
        $newStem = self::basenameStem($newUri);
        if ($oldStem === null || $newStem === null) {
            return null;
        }
        if ($oldStem === $newStem) {
            // Cycle L.1: pure move (same basename, different parent
            // directory).  Class short name doesn't change but the
            // namespace prefix follows PSR-4 to the new path.
            // Delegate to NamespaceMoveProvider which builds the
            // namespace-declaration edit + use-statement edits +
            // FQN-reference edits across the workspace.
            return $this->namespaceMoveProvider->move($oldUri, $newUri, $oldStem, $cancel);
        }
        if (
            preg_match(self::IDENTIFIER_PATTERN, $oldStem) !== 1
            || preg_match(self::IDENTIFIER_PATTERN, $newStem) !== 1
        ) {
            return null;
        }

        // Pick the URI we'll drive the rename against: prefer the old
        // URI if the file is still reachable there (spec-compliant
        // client), fall back to the new URI when IntelliJ already
        // moved the file before sending willRenameFiles.  Either way
        // the source on disk still carries the OLD class name --
        // findClassLikeNameOffset matches it against $oldStem.
        $operatingUri = $oldUri;
        $source = $this->sourceFor($oldUri);
        if ($source === null) {
            $source = $this->sourceFor($newUri);
            if ($source === null) {
                return null;
            }
            $operatingUri = $newUri;
        }
        $offset = $this->findClassLikeNameOffset($operatingUri, $source, $oldStem);
        if ($offset === null) {
            return null;
        }

        // RenameProvider's chain (resolveTargetAt -> findReferences)
        // guards on `workspace->has($uri)` and returns null when the
        // URI isn't open in the workspace.  Under IntelliJ's post-hoc
        // dispatch, neither the old NOR the new URI is open at this
        // moment (didClose fired for old, didOpen for new comes
        // AFTER willRenameFiles).  Inject the operating URI into the
        // workspace just for this call so the chain has source to
        // anchor against, then forget it -- the upcoming didOpen
        // will re-establish the version-keyed entry properly.
        $injected = false;
        if (!$this->workspace->has($operatingUri)) {
            $this->workspace->open(new TextDocumentItem($operatingUri, 'xphp', 0, $source));
            $injected = true;
        }
        try {
            return $this->renameProvider->renameSymbolOnly($operatingUri, $offset, $newStem, $cancel);
        } finally {
            if ($injected) {
                $this->workspace->remove(new \Phpactor\LanguageServerProtocol\TextDocumentIdentifier($operatingUri));
            }
        }
    }

    /**
     * Strip directory + extension from a `file://`-style URI, returning
     * just the basename stem.  Null on malformed input.
     */
    private static function basenameStem(string $uri): ?string
    {
        $path = str_starts_with($uri, 'file://') ? substr($uri, strlen('file://')) : $uri;
        $basename = basename($path);
        if ($basename === '') {
            return null;
        }
        $dot = strrpos($basename, '.');
        if ($dot === false || $dot === 0) {
            return $basename;
        }
        return substr($basename, 0, $dot);
    }

    /**
     * Resolve the source text for `$uri` -- prefers the live workspace
     * copy (so an in-flight edit's content drives the rename),
     * filesystem fallback otherwise.
     */
    private function sourceFor(string $uri): ?string
    {
        if ($this->workspace->has($uri)) {
            return $this->workspace->get($uri)->text;
        }
        if (str_starts_with($uri, 'file://')) {
            $path = substr($uri, strlen('file://'));
            $bytes = @file_get_contents($path);
            return $bytes !== false ? $bytes : null;
        }
        return null;
    }

    /**
     * Locate the byte offset of the ClassLike *name token* whose name
     * matches `$oldStem` (the basename stem).  Returns null when the
     * file declares zero or multiple ClassLikes at the top level --
     * the PSR-4 safety guard.  Multi-namespace files are also rejected
     * by the same multi-declaration check.
     *
     * The returned offset feeds into {@see RenameProvider::renameSymbolOnly}
     * which expects a byte position landing on the class identifier
     * (so its {@see ReferenceFinder::resolveTargetAt} cursor walk hits
     * the ClassLike target).
     */
    private function findClassLikeNameOffset(string $uri, string $source, string $oldStem): ?int
    {
        // Prefer the version-keyed open-doc cache when the file's open
        // in the workspace (live AST already paid for by hover /
        // completion).  For closed files, seed the warmer cache at
        // version 0 so subsequent reference resolution shares the
        // parse cost.
        if ($this->workspace->has($uri)) {
            $item = $this->workspace->get($uri);
            $parsed = $this->cache->getOrParse($uri, $item->version, $source);
        } else {
            $this->cache->seedIfAbsent($uri, $source);
            $parsed = $this->cache->peek($uri);
        }
        $ast = $parsed?->ast;
        if ($ast === null) {
            // Cache miss or analyze produced a null AST -- fall back
            // to the tolerant parser one more time so a single rename
            // still services even if the cache layer rejected the
            // file (e.g. midway through a fast edit).
            try {
                $direct = $this->parser->parseTolerantWithMap($source);
            } catch (\Throwable) {
                return null;
            }
            if ($direct === null || $direct->ast === null) {
                return null;
            }
            $ast = $direct->ast;
        }

        // Count + locate top-level ClassLike declarations.  PSR-4
        // requires exactly one per file; anything else (zero, multiple,
        // multiple namespaces) is unsafe to rename automatically.
        $matches = [];
        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_) {
                foreach ($stmt->stmts as $inner) {
                    if ($inner instanceof ClassLike && $inner->name !== null) {
                        $matches[] = $inner;
                    }
                }
                continue;
            }
            if ($stmt instanceof ClassLike && $stmt->name !== null) {
                $matches[] = $stmt;
            }
        }
        if (count($matches) !== 1) {
            return null;
        }
        $only = $matches[0];
        if ($only->name === null) {
            return null;
        }
        if ($only->name->toString() !== $oldStem) {
            // Class name didn't match the basename -- not a PSR-4 file,
            // don't auto-rename.
            return null;
        }
        $offset = $only->name->getStartFilePos();
        return $offset < 0 ? null : $offset;
    }
}
