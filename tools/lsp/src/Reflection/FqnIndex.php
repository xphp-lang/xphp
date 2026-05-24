<?php

declare(strict_types=1);

namespace XPHP\Lsp\Reflection;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Transpiler\Monomorphize\TypeParam;
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

    /**
     * @var array<string, array{kind: string, line: int, char: int}>|null
     *   FQN -> declaration metadata for the filesystem entries.  Carries the
     *   finer ClassLike kind (interface/trait/enum/class) + the identifier
     *   token's (line, char) so `allDeclarations()` can build a Location
     *   without re-parsing.  Open-doc declarations are walked on demand and
     *   not stored here.
     */
    private ?array $filesystemSymbols = null;

    /**
     * @var array<string, list<string>>|null  FQN -> ordered list of generic-param names; null until first build.
     * Populated alongside the filesystem walk so consumers like `GenericParamRegistry::prettify`
     * don't trigger N additional parses to read `ATTR_GENERIC_PARAMS` per class.
     */
    private ?array $filesystemGenericParams = null;

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
     * Yield every generic ClassLike's `(fqn, paramNames)` pair across both
     * sources.  Used by `GenericParamRegistry` to populate its
     * placeholder lookup without doing its own workspace walk.
     *
     * Open-doc declarations are walked through `ParsedDocumentCache` so
     * unsaved edits reflect immediately; filesystem-only declarations
     * come from the pre-built generic-params map populated during
     * `buildFilesystemIndex` (no extra parses).
     *
     * Open-doc wins on FQN collisions: a class declared both on disk and
     * in an open buffer yields once with the open-doc's param list.
     *
     * @return iterable<string, list<string>>  yields `fqn => paramNames`
     */
    public function iterGenericClasses(): iterable
    {
        $seen = [];
        // Open docs first so their data wins on collision.
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            foreach (self::collectGenericClasses($result->ast) as $fqn => $paramNames) {
                if (isset($seen[$fqn])) {
                    continue;
                }
                $seen[$fqn] = true;
                yield $fqn => $paramNames;
            }
        }
        foreach ($this->filesystemGenericParams() as $fqn => $paramNames) {
            if (isset($seen[$fqn])) {
                continue;
            }
            $seen[$fqn] = true;
            yield $fqn => $paramNames;
        }
    }

    /**
     * Yield every declaration the index knows about, from both open docs and
     * the filesystem.  Used by `workspace/symbol` to filter and emit
     * SymbolInformation across the workspace without each handler doing its
     * own walk.
     *
     * Each entry carries:
     *   - `fqn`     full backslash-qualified name (no leading `\`)
     *   - `kind`    "class" | "interface" | "trait" | "enum" | "function"
     *   - `uri`     file URI (open docs return the workspace URI;
     *               filesystem-only files return `file://` + absolute path)
     *   - `line`    LSP-coords line (0-based) of the identifier token
     *   - `char`    LSP-coords character offset of the identifier token
     *
     * Open-doc URIs win over filesystem paths when the same FQN is declared
     * in both -- the editor view always reflects unsaved edits.
     *
     * @return iterable<array{fqn: string, kind: string, uri: string, line: int, char: int}>
     */
    public function allDeclarations(): iterable
    {
        $seen = [];
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            $offsets = $result->byteOffsetMap;
            foreach (self::collectSymbolHits($result->ast) as $hit) {
                if (isset($seen[$hit['fqn']])) {
                    continue;
                }
                $seen[$hit['fqn']] = true;
                $origByte = $offsets->toOriginal($hit['startByte']);
                [$line, $char] = self::byteToLineChar($item->text, $origByte);
                yield [
                    'fqn' => $hit['fqn'],
                    'kind' => $hit['kind'],
                    'uri' => (string) $uri,
                    'line' => $line,
                    'char' => $char,
                ];
            }
        }
        foreach ($this->filesystemSymbols() as $fqn => $meta) {
            if (isset($seen[$fqn])) {
                continue;
            }
            $seen[$fqn] = true;
            yield [
                'fqn' => $fqn,
                'kind' => $meta['kind'],
                'uri' => 'file://' . ($this->filesystemMap()[$fqn] ?? ''),
                'line' => $meta['line'],
                'char' => $meta['char'],
            ];
        }
    }

    /**
     * Locate the declaration of `$fqn` and return its identifier-token
     * position.  Open-doc declarations win; filesystem-only declarations
     * fall through using the pre-built filesystem-symbols map.  Returns
     * null when no declaration is known.
     *
     * Used by the definition handler's Path 1 (generic-instantiation
     * Name with ATTR_TEMPLATE_FQN) so GTD on `new Box<...>()` jumps to
     * the `Box` template whether it's open or on disk.
     *
     * @return array{uri: string, line: int, char: int, short: string}|null
     */
    public function locationForFqn(string $fqn): ?array
    {
        $needle = ltrim($fqn, '\\');
        if ($needle === '') {
            return null;
        }
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            $offsets = $result->byteOffsetMap;
            foreach (self::collectSymbolHits($result->ast) as $hit) {
                if ($hit['fqn'] !== $needle) {
                    continue;
                }
                $origByte = $offsets->toOriginal($hit['startByte']);
                [$line, $char] = self::byteToLineChar($item->text, $origByte);
                return [
                    'uri' => (string) $uri,
                    'line' => $line,
                    'char' => $char,
                    'short' => self::shortOf($hit['fqn']),
                ];
            }
        }
        $symbols = $this->filesystemSymbols();
        if (!isset($symbols[$needle])) {
            return null;
        }
        $path = $this->filesystemMap()[$needle] ?? null;
        if ($path === null) {
            return null;
        }
        return [
            'uri' => 'file://' . $path,
            'line' => $symbols[$needle]['line'],
            'char' => $symbols[$needle]['char'],
            'short' => self::shortOf($needle),
        ];
    }

    /**
     * Locate ANY declaration whose short name matches `$shortName`, first
     * across open docs then across filesystem.  First match wins -- short-
     * name collisions across namespaces (e.g. App\Models\User vs
     * App\Fixtures\User) resolve in iteration order with no preference.
     *
     * Cross-namespace tie-breaking (preferring non-fixture paths, prox-
     * imity to current file, etc.) is parked as Phase 3 polish.
     *
     * Used by the definition handler's Path 2 (type-arg identifier inside
     * a generic clause -- the `User` of `identity<User>(...)`) which the
     * parser strips before the post-strip parser ever sees it, so we only
     * have the short identifier source-text and need to resolve it via
     * workspace+filesystem lookup.
     *
     * @return array{uri: string, line: int, char: int, short: string}|null
     */
    public function locationByShortName(string $shortName): ?array
    {
        if ($shortName === '') {
            return null;
        }
        $tailSuffix = '\\' . $shortName;
        $tailLen = strlen($tailSuffix);
        foreach ($this->allDeclarations() as $hit) {
            if ($hit['fqn'] === $shortName) {
                return [
                    'uri' => $hit['uri'],
                    'line' => $hit['line'],
                    'char' => $hit['char'],
                    'short' => $shortName,
                ];
            }
            if (strlen($hit['fqn']) > $tailLen
                && substr($hit['fqn'], -$tailLen) === $tailSuffix
            ) {
                return [
                    'uri' => $hit['uri'],
                    'line' => $hit['line'],
                    'char' => $hit['char'],
                    'short' => $shortName,
                ];
            }
        }
        return null;
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

    /**
     * @return array<string, list<string>>  FQN -> ordered generic param names
     */
    private function filesystemGenericParams(): array
    {
        if ($this->filesystemGenericParams === null) {
            $this->buildFilesystemIndex();
        }
        return $this->filesystemGenericParams ?? [];
    }

    /**
     * @return array<string, array{kind: string, line: int, char: int}>
     */
    private function filesystemSymbols(): array
    {
        if ($this->filesystemSymbols === null) {
            $this->buildFilesystemIndex();
        }
        return $this->filesystemSymbols ?? [];
    }

    private function buildFilesystemIndex(): void
    {
        $map = [];
        $kinds = [];
        $genericParams = [];
        $symbols = [];
        if (!is_dir($this->rootPath)) {
            @fwrite(STDERR, sprintf(
                "[xphp-lsp fqn-index] rootPath %s not a directory; filesystem index empty\n",
                $this->rootPath,
            ));
            $this->filesystemMap = $map;
            $this->filesystemKinds = $kinds;
            $this->filesystemGenericParams = $genericParams;
            $this->filesystemSymbols = $symbols;
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
                $parsed = $this->parser->parseTolerantWithMap($source);
            } catch (Throwable) {
                continue;
            }
            if ($parsed === null) {
                continue;
            }
            $ast = $parsed->ast;
            $offsets = $parsed->byteOffsetMap;

            foreach (self::collectDeclarations($ast) as $fqn => $kind) {
                $map[$fqn] = $file->getPathname();
                $kinds[$fqn] = $kind;
            }
            foreach (self::collectGenericClasses($ast) as $fqn => $paramNames) {
                $genericParams[$fqn] = $paramNames;
            }
            foreach (self::collectSymbolHits($ast) as $hit) {
                $origByte = $offsets->toOriginal($hit['startByte']);
                [$line, $char] = self::byteToLineChar($source, $origByte);
                $symbols[$hit['fqn']] = [
                    'kind' => $hit['kind'],
                    'line' => $line,
                    'char' => $char,
                ];
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
        $this->filesystemGenericParams = $genericParams;
        $this->filesystemSymbols = $symbols;
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
     * Walk an AST collecting one entry per ClassLike / Function_ declaration
     * with the finer ClassLike kind preserved (interface / trait / enum /
     * class) and the identifier token's start byte (stripped-source coords --
     * caller back-translates through the byte-offset map before mapping to
     * line/character).
     *
     * Used by `allDeclarations()` to power workspace/symbol search; the
     * coarser `collectDeclarations()` path is kept for the existing FQN-only
     * consumers.
     *
     * @param list<Node\Stmt> $ast
     * @return list<array{fqn: string, kind: string, startByte: int}>
     */
    private static function collectSymbolHits(array $ast): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<array{fqn: string, kind: string, startByte: int}> */
            public array $hits = [];

            private string $currentNamespace = '';

            public function enterNode(Node $node): null
            {
                if ($node instanceof Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    return null;
                }
                $short = null;
                $kind = null;
                $startByte = null;
                if ($node instanceof ClassLike && $node->name !== null) {
                    $short = $node->name->toString();
                    $kind = match (true) {
                        $node instanceof Interface_ => 'interface',
                        $node instanceof Trait_ => 'trait',
                        $node instanceof Enum_ => 'enum',
                        default => 'class',
                    };
                    $startByte = $node->name->getStartFilePos();
                } elseif ($node instanceof Function_) {
                    $short = $node->name->toString();
                    $kind = 'function';
                    $startByte = $node->name->getStartFilePos();
                }
                if ($short !== null && $startByte !== null && $startByte >= 0) {
                    $fqn = $this->currentNamespace !== ''
                        ? $this->currentNamespace . '\\' . $short
                        : $short;
                    $this->hits[] = [
                        'fqn' => $fqn,
                        'kind' => $kind,
                        'startByte' => $startByte,
                    ];
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->hits;
    }

    private static function shortOf(string $fqn): string
    {
        $idx = strrpos($fqn, '\\');
        return $idx === false ? $fqn : substr($fqn, $idx + 1);
    }

    /**
     * Map a byte offset within `$source` to (line, character) in LSP
     * 0-based coordinates.  Simple O(byteOffset) scan -- we only call this
     * for the small set of declaration identifiers, so a full PositionMap
     * (which precomputes line starts up front) would be overkill.
     *
     * @return array{0: int, 1: int}
     */
    private static function byteToLineChar(string $source, int $byteOffset): array
    {
        if ($byteOffset <= 0) {
            return [0, 0];
        }
        $clamped = min($byteOffset, strlen($source));
        $line = 0;
        $lineStart = 0;
        for ($i = 0; $i < $clamped; $i++) {
            if ($source[$i] === "\n") {
                $line++;
                $lineStart = $i + 1;
            }
        }
        return [$line, $clamped - $lineStart];
    }

    /**
     * Walk an AST collecting `FQN => paramNames` for every generic ClassLike.
     * Non-generic classes are skipped.  Methods on generic classes don't
     * count (method-scoped generics use a separate attribute path that this
     * index doesn't surface).
     *
     * @param list<Node\Stmt> $ast
     * @return array<string, list<string>>
     */
    private static function collectGenericClasses(array $ast): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var array<string, list<string>> */
            public array $fqns = [];

            private string $currentNamespace = '';

            public function enterNode(Node $node): null
            {
                if ($node instanceof Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    return null;
                }
                if (!$node instanceof ClassLike || $node->name === null) {
                    return null;
                }
                $params = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
                if (!is_array($params) || $params === []) {
                    return null;
                }
                $paramNames = [];
                foreach ($params as $p) {
                    if ($p instanceof TypeParam) {
                        $paramNames[] = $p->name;
                    }
                }
                if ($paramNames === []) {
                    return null;
                }
                $fqn = $node->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
                if (!is_string($fqn)) {
                    // Generic classes always have ATTR_TEMPLATE_FQN stamped
                    // (set alongside ATTR_GENERIC_PARAMS), but fall back to
                    // namespace + short name just in case the parser ever
                    // diverges.
                    $short = $node->name->toString();
                    $fqn = $this->currentNamespace !== ''
                        ? $this->currentNamespace . '\\' . $short
                        : $short;
                }
                $this->fqns[$fqn] = $paramNames;
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
