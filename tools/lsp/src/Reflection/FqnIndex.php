<?php

declare(strict_types=1);

namespace XPHP\Lsp\Reflection;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Workspace-wide FQN -> declaration index, covering BOTH open documents
 * (live via the workspace + cache) and on-disk files under rootPath
 * (lazy filesystem walk).  Single source of truth for every "where is
 * `App\Containers\Collection` declared?" query in the LSP.
 *
 * Two consumers exist today:
 *   - `FilesystemSourceLocator` (worse-reflection adapter, wants stripped
 *     source by FQN) -- thin adapter on top of pathFor() + a file_get_contents.
 *   - `FilesystemClassLikeLookup` (GenericResolver dependency, wants the
 *     parsed `ClassLike` AST with xphp attributes intact) -- thin adapter
 *     on top of parsedFor().
 *
 * Open-doc URIs win over filesystem paths when they collide on the same
 * FQN: a generic class with unsaved edits in the editor reflects the
 * editor state, not the on-disk state.
 *
 * Filesystem walk lifecycle: built lazily on first call, retained for the
 * LSP session.  A file watcher (`workspace/didChangeWatchedFiles`) is the
 * follow-up (Phase 2.4 in the roadmap); for now an LSP restart picks up
 * new files added to disk after session start.
 *
 * Skipped paths in the filesystem walk:
 *   - `.git`, `vendor`, `node_modules`, `var`, `build`
 *
 * Inherited from FilesystemSourceLocator's prior behaviour; same
 * rationale (build artifacts, third-party trees, irrelevant binaries).
 */
final class FqnIndex
{
    private const SKIP_DIRS = ['.git', 'vendor', 'node_modules', 'var', 'build'];

    /**
     * @var array<string, string>|null  FQN -> absolute filesystem path; null until first build.
     */
    private ?array $filesystemMap = null;

    /**
     * @var array<string, string>|null  FQN -> "class" or "function" kind for the filesystem entries.
     */
    private ?array $filesystemKinds = null;

    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $cache,
        private readonly XphpSourceParser $parser,
        private readonly string $rootPath,
    ) {
    }

    /**
     * Path to the declaration site for `$fqn`, or null if no declaration
     * is known.  Open-doc URIs win over filesystem paths.
     */
    public function pathFor(string $fqn): ?string
    {
        $needle = ltrim($fqn, '\\');
        if ($needle === '') {
            return null;
        }
        $uri = $this->openDocUriFor($needle);
        if ($uri !== null) {
            return $uri;
        }
        $filesystemMap = $this->filesystemMap();
        return $filesystemMap[$needle] ?? null;
    }

    /**
     * Locate the `Function_` AST for `$fqn` (free function, not method).
     * Open-doc declarations win.  Filesystem-only declarations parse on
     * demand via `XphpSourceParser::parseTolerant()` so the function's
     * `ATTR_METHOD_GENERIC_PARAMS` survives even for mid-edit sources.
     *
     * Used by `GenericResolver` to substitute type-args on generic-
     * function call sites (`identity<User>(...)`).  Methods reuse
     * `classLikeFor()` -> `findMethod` instead.
     */
    public function functionFor(string $fqn): ?Function_
    {
        $needle = ltrim($fqn, '\\');
        if ($needle === '') {
            return null;
        }
        $hit = $this->openDocFunction($needle);
        if ($hit !== null) {
            return $hit;
        }
        $filesystemMap = $this->filesystemMap();
        if (!isset($filesystemMap[$needle])) {
            return null;
        }
        return $this->functionFromFile($filesystemMap[$needle], $needle);
    }

    /**
     * Locate the `ClassLike` AST for `$fqn` with xphp attributes
     * (`ATTR_GENERIC_PARAMS`, `ATTR_TEMPLATE_FQN`, ...) intact.  Open-doc
     * declarations short-circuit through the shared `ParsedDocumentCache`;
     * filesystem-only declarations parse on demand via `XphpSourceParser`
     * (tolerant) so attributes are still attached even on partially
     * malformed sources.
     */
    public function classLikeFor(string $fqn): ?ClassLike
    {
        $needle = ltrim($fqn, '\\');
        if ($needle === '') {
            return null;
        }

        // Open-doc first: live view of unsaved edits beats the on-disk copy.
        $hit = $this->openDocClassLike($needle);
        if ($hit !== null) {
            return $hit;
        }

        $filesystemMap = $this->filesystemMap();
        if (!isset($filesystemMap[$needle])) {
            return null;
        }
        return $this->classLikeFromFile($filesystemMap[$needle], $needle);
    }

    /**
     * Every class/interface/trait FQN known to the index, from both open
     * docs and the filesystem.  De-duplicated; ordering is insertion-stable
     * (open docs first, then filesystem in walk order).
     *
     * @return list<string>
     */
    public function allClassFqns(): array
    {
        $fqns = [];
        foreach ($this->openDocClassFqns() as $fqn) {
            $fqns[$fqn] = true;
        }
        foreach ($this->filesystemKinds() as $fqn => $kind) {
            if ($kind === 'class') {
                $fqns[$fqn] = true;
            }
        }
        return array_keys($fqns);
    }

    /**
     * Every top-level function FQN known to the index, both sources.
     *
     * @return list<string>
     */
    public function allFunctionFqns(): array
    {
        $fqns = [];
        foreach ($this->openDocFunctionFqns() as $fqn) {
            $fqns[$fqn] = true;
        }
        foreach ($this->filesystemKinds() as $fqn => $kind) {
            if ($kind === 'function') {
                $fqns[$fqn] = true;
            }
        }
        return array_keys($fqns);
    }

    // -- open-doc side (cheap; re-walked per call, ParsedDocumentCache memoizes) -------

    private function openDocUriFor(string $fqn): ?string
    {
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            foreach (self::collectDeclarations($result->ast) as $declared => $_kind) {
                if ($declared === $fqn) {
                    return (string) $uri;
                }
            }
        }
        return null;
    }

    private function openDocFunction(string $fqn): ?Function_
    {
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            $hit = self::findFunctionInAst($result->ast, $fqn);
            if ($hit !== null) {
                return $hit;
            }
        }
        return null;
    }

    private function functionFromFile(string $path, string $needle): ?Function_
    {
        $source = @file_get_contents($path);
        if ($source === false) {
            return null;
        }
        try {
            $ast = $this->parser->parseTolerant($source);
        } catch (\Throwable) {
            return null;
        }
        if ($ast === null) {
            return null;
        }
        return self::findFunctionInAst($ast, $needle);
    }

    /**
     * @param list<Node\Stmt> $ast
     */
    private static function findFunctionInAst(array $ast, string $needle): ?Function_
    {
        $visitor = new class($needle) extends NodeVisitorAbstract {
            public ?Function_ $found = null;
            private string $currentNamespace = '';

            public function __construct(private readonly string $needle)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($this->found !== null) {
                    return null;
                }
                if ($node instanceof Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    return null;
                }
                if (!$node instanceof Function_) {
                    return null;
                }
                $short = $node->name->toString();
                $fqn = $this->currentNamespace !== ''
                    ? $this->currentNamespace . '\\' . $short
                    : $short;
                if ($fqn === $this->needle) {
                    $this->found = $node;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->found;
    }

    private function openDocClassLike(string $fqn): ?ClassLike
    {
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            $hit = self::findClassLikeInAst($result->ast, $fqn);
            if ($hit !== null) {
                return $hit;
            }
        }
        return null;
    }

    /**
     * @return list<string>
     */
    private function openDocClassFqns(): array
    {
        $fqns = [];
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            foreach (self::collectDeclarations($result->ast) as $fqn => $kind) {
                if ($kind === 'class') {
                    $fqns[$fqn] = true;
                }
            }
        }
        return array_keys($fqns);
    }

    /**
     * @return list<string>
     */
    private function openDocFunctionFqns(): array
    {
        $fqns = [];
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            foreach (self::collectDeclarations($result->ast) as $fqn => $kind) {
                if ($kind === 'function') {
                    $fqns[$fqn] = true;
                }
            }
        }
        return array_keys($fqns);
    }

    // -- filesystem side (lazy, walked once per LSP session) ---------------------------

    /**
     * @return array<string, string>  FQN -> path
     */
    private function filesystemMap(): array
    {
        if ($this->filesystemMap === null) {
            $this->buildFilesystemIndex();
        }
        return $this->filesystemMap ?? [];
    }

    /**
     * @return array<string, string>  FQN -> "class" or "function"
     */
    private function filesystemKinds(): array
    {
        if ($this->filesystemKinds === null) {
            $this->buildFilesystemIndex();
        }
        return $this->filesystemKinds ?? [];
    }

    private function buildFilesystemIndex(): void
    {
        $map = [];
        $kinds = [];
        if (!is_dir($this->rootPath)) {
            @fwrite(STDERR, sprintf(
                "[xphp-lsp fqn-index] rootPath %s not a directory; filesystem index empty\n",
                $this->rootPath,
            ));
            $this->filesystemMap = $map;
            $this->filesystemKinds = $kinds;
            return;
        }

        $filesScanned = 0;
        foreach ($this->iterator() as $file) {
            /** @var SplFileInfo $file */
            $ext = $file->getExtension();
            if ($ext !== 'php' && $ext !== 'xphp') {
                continue;
            }
            $filesScanned++;

            $source = @file_get_contents($file->getPathname());
            if ($source === false) {
                continue;
            }

            try {
                $ast = $this->parser->parseTolerant($source);
            } catch (Throwable) {
                continue;
            }
            if ($ast === null) {
                continue;
            }

            foreach (self::collectDeclarations($ast) as $fqn => $kind) {
                $map[$fqn] = $file->getPathname();
                $kinds[$fqn] = $kind;
            }
        }

        @fwrite(STDERR, sprintf(
            "[xphp-lsp fqn-index] indexed %d FQNs from %d files under %s (skipped: %s)\n",
            count($map),
            $filesScanned,
            $this->rootPath,
            implode(', ', self::SKIP_DIRS),
        ));

        $this->filesystemMap = $map;
        $this->filesystemKinds = $kinds;
    }

    private function classLikeFromFile(string $path, string $needle): ?ClassLike
    {
        $source = @file_get_contents($path);
        if ($source === false) {
            return null;
        }
        try {
            $ast = $this->parser->parseTolerant($source);
        } catch (Throwable) {
            return null;
        }
        if ($ast === null) {
            return null;
        }
        return self::findClassLikeInAst($ast, $needle);
    }

    private function iterator(): RecursiveIteratorIterator
    {
        $directoryIterator = new RecursiveDirectoryIterator(
            $this->rootPath,
            RecursiveDirectoryIterator::SKIP_DOTS,
        );

        $filter = new \RecursiveCallbackFilterIterator(
            $directoryIterator,
            static function (SplFileInfo $file): bool {
                if ($file->isDir()) {
                    return !in_array($file->getFilename(), self::SKIP_DIRS, true);
                }
                return true;
            },
        );

        return new RecursiveIteratorIterator($filter);
    }

    // -- AST helpers -------------------------------------------------------------------

    /**
     * Walk an AST collecting `FQN => "class"|"function"` for every ClassLike
     * and top-level Function_ declaration.  Methods and closures don't
     * count -- only namespace-level functions.
     *
     * @param list<Node\Stmt> $ast
     * @return array<string, string>
     */
    private static function collectDeclarations(array $ast): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var array<string, string> */
            public array $fqns = [];

            private string $currentNamespace = '';

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    return null;
                }
                $short = null;
                $kind = null;
                if ($node instanceof ClassLike && $node->name !== null) {
                    $short = $node->name->toString();
                    $kind = 'class';
                } elseif ($node instanceof Function_) {
                    $short = $node->name->toString();
                    $kind = 'function';
                }
                if ($short !== null) {
                    $fqn = $this->currentNamespace !== ''
                        ? $this->currentNamespace . '\\' . $short
                        : $short;
                    $this->fqns[$fqn] = $kind;
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
     */
    private static function findClassLikeInAst(array $ast, string $needle): ?ClassLike
    {
        $visitor = new class($needle) extends NodeVisitorAbstract {
            public ?ClassLike $found = null;

            public function __construct(private readonly string $needle)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($this->found !== null) {
                    return null;
                }
                if (!$node instanceof ClassLike) {
                    return null;
                }
                $fqn = $node->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
                if (is_string($fqn) && $fqn === $this->needle) {
                    $this->found = $node;
                    return null;
                }
                // Fallback for non-generic classes that don't carry
                // ATTR_TEMPLATE_FQN: reconstruct from namespace + short
                // name walked from the AST itself.
                $current = $node->name?->toString();
                if ($current !== null) {
                    $ns = self::namespaceOf($node);
                    $built = $ns !== '' ? $ns . '\\' . $current : $current;
                    if ($built === $this->needle) {
                        $this->found = $node;
                    }
                }
                return null;
            }

            private static function namespaceOf(Node $node): string
            {
                $parent = $node->getAttribute('parent');
                while ($parent instanceof Node) {
                    if ($parent instanceof Node\Stmt\Namespace_) {
                        return $parent->name?->toString() ?? '';
                    }
                    $parent = $parent->getAttribute('parent');
                }
                return '';
            }
        };

        // Wrap the traversal so namespace context is tracked manually
        // (NameResolver would be heavier than we need; ATTR_TEMPLATE_FQN
        // covers the generic-class case and we only need the fallback for
        // non-generic classes).
        $tracker = new class($visitor) extends NodeVisitorAbstract {
            private string $currentNamespace = '';

            public function __construct(private readonly NodeVisitorAbstract $inner)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    return null;
                }
                if ($node instanceof ClassLike && $node->name !== null) {
                    if ($node->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN) === null) {
                        $short = $node->name->toString();
                        $fqn = $this->currentNamespace !== ''
                            ? $this->currentNamespace . '\\' . $short
                            : $short;
                        $node->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, $fqn);
                    }
                }
                $this->inner->enterNode($node);
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($tracker);
        $traverser->traverse($ast);
        return $visitor->found;
    }
}
