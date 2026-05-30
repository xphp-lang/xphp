<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use Amp\CancellationToken;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\OptionalVersionedTextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\TextDocumentEdit;
use Phpactor\LanguageServerProtocol\TextEdit;
use Phpactor\LanguageServerProtocol\WorkspaceEdit;
use Throwable;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Cycle L.1: cross-directory file move → namespace rename + reference
 * updates.
 *
 * When the user moves `Models/User.xphp` → `Containers/User.xphp` in
 * the project tree, PSR-4 expects the namespace to follow: `App\Models`
 * → `App\Containers`.  The basename and class short name don't change;
 * what changes is the **namespace prefix** of the class's FQN and
 * every cross-file reference to it.
 *
 * This provider builds the `WorkspaceEdit` for that transformation.
 * Distinct from {@see RenameProvider} (which swaps the trailing
 * short name and preserves the namespace): here we swap the
 * namespace prefix and preserve the trailing short name.  The two
 * are mutually exclusive in the cases this handler is invoked for
 * (`XphpWillRenameFilesHandler::editsForFileRename` routes pure
 * moves here, pure renames to `RenameProvider`).
 *
 * PSR-4 mapping derivation: we don't parse `composer.json`.
 * Instead, we infer the prefix-to-namespace map from the source
 * file's existing namespace declaration vs its old path.  If the
 * old path is `<root>/playground/src/Models/User.xphp` and the
 * old namespace is `App\Models`, then `playground/src` ↔ `App`
 * is the PSR-4 prefix.  Applying that to the new path
 * `<root>/playground/src/Containers/User.xphp` yields
 * `App\Containers`.  Robust to any layout where the existing file
 * sits in its proper PSR-4 location; degrades gracefully (returns
 * null) for files that don't follow the convention.
 *
 * Reference shapes the walker handles:
 *
 *   - `use App\Models\User;` → `use App\Containers\User;`
 *   - `use App\Models\User as Foo;` → `use App\Containers\User as Foo;`
 *   - `use App\Models\{User};` → `use App\Containers\{User};` (group use)
 *   - `\App\Models\User` (fully qualified) → `\App\Containers\User`
 *   - `App\Models\User` (qualified, not fully) → `App\Containers\User`
 *   - Bare `User` (short, resolved through a `use`) → no edit needed;
 *     the `use` statement above is the carrier.
 *
 * Each edit replaces the namespace prefix portion of the Name
 * node's text, leaving the trailing short name intact.
 */
final class NamespaceMoveProvider
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $cache,
        private readonly FqnIndex $fqnIndex,
        private readonly XphpSourceParser $parser,
    ) {
    }

    /**
     * Build the WorkspaceEdit for a pure cross-directory file move
     * (same basename, different parent directory).  Returns null
     * when:
     *   - source unreadable from either URI
     *   - file declares 0 or >1 top-level ClassLikes (PSR-4 safety)
     *   - declared namespace doesn't end with the old dir's trailing
     *     segments (file isn't in its PSR-4 location)
     *   - new path doesn't share the derived PSR-4 root with the old
     *     path (move across PSR-4 prefixes — out of scope for V1)
     *   - derived new namespace equals old namespace (move WITHIN
     *     the same namespace, e.g. case-only directory rename on a
     *     case-insensitive filesystem; nothing to edit)
     */
    public function move(
        string $oldUri,
        string $newUri,
        string $basenameStem,
        ?CancellationToken $cancel = null,
    ): ?WorkspaceEdit {
        // Track WHICH URI yielded the source.  IntelliJ's post-hoc
        // dispatch (the file's already been moved when willRenameFiles
        // fires) means the OLD URI is often dead and only the NEW URI
        // is reachable.  Hardcoding the source edit against $oldUri
        // (the prior implementation) made the edit silently fail when
        // the client tried to apply it to a path that no longer
        // existed; the cross-file reference edits still landed because
        // their URIs didn't move, but the source's own namespace
        // declaration stayed unchanged.  Prod log
        // `xphp-20260530-182553` showed this clearly: id=10 succeeded
        // for Models→Containers but only the Demos files actually
        // changed on disk; every subsequent Containers→Models undo
        // then PSR-4-inferred against a stale `namespace App\Models;`
        // and returned null.
        $source = $this->sourceFor($oldUri);
        $sourceEditUri = $oldUri;
        if ($source === null) {
            $source = $this->sourceFor($newUri);
            if ($source === null) {
                return null;
            }
            $sourceEditUri = $newUri;
        }

        // Find the single ClassLike + Namespace_ in the source.
        $info = $this->parseSourceShape($source, $basenameStem);
        if ($info === null) {
            return null;
        }
        [$oldNamespace, $namespaceNameStart, $namespaceNameEnd] = $info;

        $newNamespace = self::deriveNewNamespace($oldUri, $newUri, $oldNamespace);
        if ($newNamespace === null || $newNamespace === $oldNamespace) {
            return null;
        }

        $oldFqn = $oldNamespace . '\\' . $basenameStem;
        $newFqn = $newNamespace . '\\' . $basenameStem;

        $documentChanges = [];

        // 1. Source file: edit the namespace declaration.  Target the
        //    URI we actually read from (see the post-hoc-dispatch
        //    comment above).
        $sourceEdit = $this->buildNamespaceDeclarationEdit($source, $namespaceNameStart, $namespaceNameEnd, $newNamespace);
        if ($sourceEdit !== null) {
            $documentChanges[] = new TextDocumentEdit(
                new OptionalVersionedTextDocumentIdentifier($sourceEditUri),
                [$sourceEdit],
            );
        }

        // 2. Workspace + filesystem: edit every cross-file reference
        //    to the old FQN to use the new namespace prefix.  Source
        //    file's own internal `use App\Models\Other` etc. don't
        //    point at the moved class so they don't need editing.
        //    Mark BOTH old and new URIs as seen -- under IntelliJ's
        //    post-hoc dispatch the source might appear in either the
        //    workspace or the filesystem walk under either URI, and
        //    we've already handled the source-side edit above.
        $seenUris = [$oldUri => true, $newUri => true];
        foreach ($this->workspace as $docUri => $item) {
            if ($cancel !== null && $cancel->isRequested()) {
                return null;
            }
            $docUriStr = (string) $docUri;
            if (isset($seenUris[$docUriStr])) {
                continue;
            }
            $seenUris[$docUriStr] = true;
            $edit = $this->buildEditsForFile($docUriStr, $item->text, $oldFqn, $oldNamespace, $newNamespace);
            if ($edit !== null) {
                $documentChanges[] = $edit;
            }
        }

        foreach ($this->fqnIndex->indexedFilesystemPaths() as $path) {
            if ($cancel !== null && $cancel->isRequested()) {
                return null;
            }
            $fsUri = 'file://' . $path;
            if (isset($seenUris[$fsUri])) {
                continue;
            }
            $fsSource = @file_get_contents($path);
            if ($fsSource === false) {
                continue;
            }
            // Cheap pre-filter: skip files that don't textually mention
            // the old short name AND don't mention the namespace head.
            // (Either would be required for a reference to exist.)
            if (!str_contains($fsSource, $basenameStem) && !str_contains($fsSource, self::firstSegment($oldNamespace))) {
                continue;
            }
            $edit = $this->buildEditsForFile($fsUri, $fsSource, $oldFqn, $oldNamespace, $newNamespace);
            if ($edit !== null) {
                $documentChanges[] = $edit;
            }
        }

        if ($documentChanges === []) {
            return null;
        }
        return new WorkspaceEdit(null, $documentChanges);
    }

    /**
     * Walk one file's AST collecting edits that swap the namespace
     * prefix on every Name node resolving to the old FQN.  Edit
     * granularity: replace the leading-N-segments slice of each
     * Name's text, preserving the trailing short name.
     *
     * Returns null when no edits land for this file (no edit
     * payload required).
     */
    private function buildEditsForFile(
        string $uri,
        string $source,
        string $oldFqn,
        string $oldNamespace,
        string $newNamespace,
    ): ?TextDocumentEdit {
        try {
            $parsed = $this->parser->parseTolerantWithMap($source);
        } catch (Throwable) {
            return null;
        }
        if ($parsed === null || $parsed->ast === null) {
            return null;
        }
        // Resolve names so we can match Name nodes by their resolvedName attribute.
        $resolved = self::cloneWithResolvedNames($parsed->ast);

        $oldNamespaceNorm = ltrim($oldNamespace, '\\');
        $oldFqnNorm = ltrim($oldFqn, '\\');

        $edits = [];
        $finder = new NodeFinder();
        foreach ($finder->find($resolved, static fn (Node $n): bool => true) as $node) {
            if (!$node instanceof Name) {
                continue;
            }
            $literal = $node->toString();
            $literalUnqualified = ltrim($literal, '\\');
            $resolvedName = $node->getAttribute('resolvedName');
            $matchesOld = false;
            if ($resolvedName instanceof Name && ltrim($resolvedName->toString(), '\\') === $oldFqnNorm) {
                $matchesOld = true;
            } elseif ($literalUnqualified === $oldFqnNorm) {
                // Fallback for fully-qualified literals NameResolver
                // passed through without producing a resolvedName.
                $matchesOld = true;
            }
            if (!$matchesOld) {
                continue;
            }
            // Edit only when the literal text carries the namespace
            // prefix.  Bare short-name references (after a `use`) have
            // no namespace to rewrite -- the `use` statement above
            // gets edited separately and that's enough.
            if (!str_contains($literalUnqualified, '\\')) {
                continue;
            }
            $start = $node->getStartFilePos();
            $end = $node->getEndFilePos();
            if ($start < 0 || $end < $start) {
                continue;
            }
            $rangeText = substr($source, $start, $end - $start + 1);
            $hadLeadingBackslash = str_starts_with($rangeText, '\\');
            $bodyText = $hadLeadingBackslash ? substr($rangeText, 1) : $rangeText;
            if (!str_starts_with($bodyText, $oldNamespaceNorm . '\\')) {
                // Resolved to the old FQN but textually expressed
                // through a `use` alias or partial qualification.
                // Skip -- the `use` statement (if any) carries the
                // change.
                continue;
            }
            $replacement = ($hadLeadingBackslash ? '\\' : '')
                . $newNamespace
                . substr($bodyText, strlen($oldNamespaceNorm));

            $positionMap = new PositionMap($source);
            [$startLine, $startChar] = $positionMap->offsetToPosition($start);
            [$endLine, $endChar] = $positionMap->offsetToPosition($end + 1);
            $edits[] = new TextEdit(
                new Range(new Position($startLine, $startChar), new Position($endLine, $endChar)),
                $replacement,
            );
        }

        if ($edits === []) {
            return null;
        }
        return new TextDocumentEdit(
            new OptionalVersionedTextDocumentIdentifier($uri),
            $edits,
        );
    }

    /**
     * Build the edit on the source file's `namespace X;` declaration.
     * Replaces the namespace name token range with the new namespace
     * string.  The trailing semicolon / opening brace stays put.
     */
    private function buildNamespaceDeclarationEdit(
        string $source,
        int $nameStart,
        int $nameEnd,
        string $newNamespace,
    ): ?TextEdit {
        if ($nameStart < 0 || $nameEnd < $nameStart) {
            return null;
        }
        $positionMap = new PositionMap($source);
        [$startLine, $startChar] = $positionMap->offsetToPosition($nameStart);
        [$endLine, $endChar] = $positionMap->offsetToPosition($nameEnd + 1);
        return new TextEdit(
            new Range(new Position($startLine, $startChar), new Position($endLine, $endChar)),
            $newNamespace,
        );
    }

    /**
     * Inspect the source AST to confirm a single PSR-4 ClassLike +
     * single Namespace_ block, and return:
     *   - old namespace name (e.g. "App\Models")
     *   - byte offset of the namespace name token start
     *   - byte offset of the namespace name token end (inclusive)
     *
     * Returns null when the file isn't a single-class single-
     * namespace PSR-4 file, mirroring the same safety guard
     * {@see XphpWillRenameFilesHandler::findClassLikeNameOffset}
     * enforces for the rename pipeline.
     *
     * @return array{0: string, 1: int, 2: int}|null
     */
    private function parseSourceShape(string $source, string $basenameStem): ?array
    {
        try {
            $parsed = $this->parser->parseTolerantWithMap($source);
        } catch (Throwable) {
            return null;
        }
        if ($parsed === null || $parsed->ast === null) {
            return null;
        }
        $namespaces = [];
        $classLikes = [];
        foreach ($parsed->ast as $stmt) {
            if ($stmt instanceof Namespace_) {
                $namespaces[] = $stmt;
                foreach ($stmt->stmts as $inner) {
                    if ($inner instanceof ClassLike && $inner->name !== null) {
                        $classLikes[] = $inner;
                    }
                }
                continue;
            }
            if ($stmt instanceof ClassLike && $stmt->name !== null) {
                $classLikes[] = $stmt;
            }
        }
        if (count($namespaces) !== 1 || count($classLikes) !== 1) {
            return null;
        }
        $namespace = $namespaces[0];
        if ($namespace->name === null) {
            // Anonymous namespace `namespace { ... }` doesn't carry
            // a PSR-4 prefix; nothing to swap.
            return null;
        }
        $class = $classLikes[0];
        if ($class->name === null || $class->name->toString() !== $basenameStem) {
            return null;
        }
        return [
            $namespace->name->toString(),
            $namespace->name->getStartFilePos(),
            $namespace->name->getEndFilePos(),
        ];
    }

    /**
     * Derive the new namespace by inferring the PSR-4 path prefix
     * mapping from the source's existing namespace + old path, then
     * applying it to the new path.
     *
     * Algorithm:
     *   1. Compute path dir segments for both old and new URIs.
     *   2. Split the old namespace into segments.
     *   3. Walk the old dir segments from the right, matching against
     *      the namespace segments from the right.  The common suffix
     *      length tells us how many trailing path segments correspond
     *      to namespace segments.
     *   4. The non-overlapping leading path segments + the
     *      non-overlapping leading namespace segments are the PSR-4
     *      prefix mapping: `prefixPath/ <-> prefixNamespace\`.
     *   5. Verify the new path starts with `prefixPath/`.  If not,
     *      the move crosses PSR-4 roots -- bail.
     *   6. New namespace = prefixNamespace + (new path's segments
     *      after prefixPath).
     */
    public static function deriveNewNamespace(string $oldUri, string $newUri, string $oldNamespace): ?string
    {
        $oldDir = self::dirPath($oldUri);
        $newDir = self::dirPath($newUri);
        if ($oldDir === null || $newDir === null) {
            return null;
        }
        if ($oldDir === $newDir) {
            return null;
        }
        $oldDirSegs = self::pathSegments($oldDir);
        $newDirSegs = self::pathSegments($newDir);
        $nsSegs = self::namespaceSegments($oldNamespace);
        if ($nsSegs === []) {
            return null;
        }
        // Walk from the right matching namespace segments to dir segments.
        $matchLen = 0;
        $oldDirCount = count($oldDirSegs);
        $nsCount = count($nsSegs);
        while ($matchLen < $oldDirCount && $matchLen < $nsCount) {
            $dirSeg = $oldDirSegs[$oldDirCount - 1 - $matchLen];
            $nsSeg = $nsSegs[$nsCount - 1 - $matchLen];
            if ($dirSeg !== $nsSeg) {
                break;
            }
            $matchLen++;
        }
        if ($matchLen === 0) {
            // The source's namespace doesn't end with any of the
            // old dir's trailing segments -- file isn't in its PSR-4
            // location.  Bail rather than guess.
            return null;
        }
        // Non-overlapping prefixes:
        $pathPrefix = array_slice($oldDirSegs, 0, $oldDirCount - $matchLen);
        $nsPrefix = array_slice($nsSegs, 0, $nsCount - $matchLen);

        // Confirm the new path starts with the same path prefix.
        if (count($newDirSegs) < count($pathPrefix)) {
            return null;
        }
        for ($i = 0, $n = count($pathPrefix); $i < $n; $i++) {
            if (($newDirSegs[$i] ?? null) !== $pathPrefix[$i]) {
                return null;
            }
        }
        $newSuffix = array_slice($newDirSegs, count($pathPrefix));
        $newNsSegs = array_merge($nsPrefix, $newSuffix);
        if ($newNsSegs === []) {
            // Root-level placement: target is the anonymous namespace
            // (no prefix).  Skip rather than emit an empty namespace
            // declaration.
            return null;
        }
        return implode('\\', $newNsSegs);
    }

    /**
     * Extract the directory portion of a `file://...` URI, returning
     * a `/`-separated absolute path string.  Null on inputs we can't
     * parse.
     */
    private static function dirPath(string $uri): ?string
    {
        $path = str_starts_with($uri, 'file://') ? substr($uri, strlen('file://')) : $uri;
        if ($path === '') {
            return null;
        }
        $dir = dirname($path);
        return $dir === '' || $dir === '.' ? null : $dir;
    }

    /**
     * Split a `/`-separated path into non-empty segments.  Leading
     * `/` produces a leading empty segment which we drop.
     *
     * @return list<string>
     */
    private static function pathSegments(string $path): array
    {
        $parts = explode('/', $path);
        $out = [];
        foreach ($parts as $p) {
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * Split a `\`-separated namespace into segments.  Leading
     * backslash produces a leading empty segment which we drop.
     *
     * @return list<string>
     */
    private static function namespaceSegments(string $namespace): array
    {
        $parts = explode('\\', $namespace);
        $out = [];
        foreach ($parts as $p) {
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * First segment of a namespace string (e.g. "App" from
     * "App\Models").  Used by the cheap pre-filter to skip files
     * that can't possibly reference the moved class.
     */
    private static function firstSegment(string $namespace): string
    {
        $segs = self::namespaceSegments($namespace);
        return $segs === [] ? '' : $segs[0];
    }

    /**
     * Resolve the source text for a URI, preferring the workspace's
     * live copy.  Mirrors {@see XphpWillRenameFilesHandler::sourceFor}.
     *
     * Race window: when PhpStorm dispatches willRenameFiles, neither
     * URI is guaranteed to be available -- the OLD URI just received
     * didClose, the NEW URI's didOpen is typically ~20 ms behind,
     * and the OS-level rename can have a brief in-flight window
     * where neither path exists on disk.  The xphp PhpStorm plugin
     * pre-empts this by reading source bytes in `prepareChange`
     * (where the file is GUARANTEED at its old location and VFS
     * content is available) and seeding the server's workspace via
     * a synthetic `textDocument/didOpen` for the NEW URI BEFORE
     * sending willRenameFiles.  By the time we run, `workspace.has(
     * newUri)` is true and the lookup hits deterministically -- no
     * sleep/retry needed.
     *
     * For VS Code (which does honour LSP-spec timing -- willRenameFiles
     * fires BEFORE the move actually happens) sourceFor(oldUri) hits
     * via filesystem before falling back.  Either client path leaves
     * us with a deterministic source lookup.
     */
    private function sourceFor(string $uri): ?string
    {
        if ($this->workspace->has($uri)) {
            return $this->workspace->get($uri)->text;
        }
        if (!str_starts_with($uri, 'file://')) {
            return null;
        }
        $path = substr($uri, strlen('file://'));
        $bytes = @file_get_contents($path);
        return $bytes !== false ? $bytes : null;
    }

    /**
     * Clone-and-resolve the AST so Name nodes carry their
     * `resolvedName` attribute.  Same pattern (and rationale) as
     * {@see \XPHP\Lsp\Resolver\ReferenceFinder::cloneWithResolvedNames}.
     *
     * @param list<Node\Stmt> $ast
     * @return list<Node\Stmt>
     */
    private static function cloneWithResolvedNames(array $ast): array
    {
        $clone = unserialize(serialize($ast));
        $errorHandler = new \PhpParser\ErrorHandler\Collecting();
        $resolver = new NameResolver($errorHandler, ['replaceNodes' => false]);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($resolver);
        $traverser->traverse($clone);
        return $clone;
    }
}
