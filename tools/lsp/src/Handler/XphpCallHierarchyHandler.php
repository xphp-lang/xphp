<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\Promise;
use Amp\Success;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\CallHierarchyIncomingCall;
use Phpactor\LanguageServerProtocol\CallHierarchyItem;
use Phpactor\LanguageServerProtocol\CallHierarchyOutgoingCall;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\SymbolKind;
use Phpactor\LanguageServerProtocol\TextDocumentPositionParams;
use Throwable;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Cycle H — Call hierarchy.
 *
 * Implements `textDocument/prepareCallHierarchy`,
 * `callHierarchy/incomingCalls`, and `callHierarchy/outgoingCalls`.
 *
 * - **Prepare**: from the cursor position, locate the enclosing
 *   `ClassMethod` or `Function_`.  Emit a single CallHierarchyItem
 *   carrying the FQN (`Class::method` or bare function name) in
 *   `data['fqn']` so subsequent incoming / outgoing calls don't have
 *   to re-resolve.
 *
 * - **Incoming**: walk every open document AND filesystem-indexed
 *   document and collect call sites whose `name === $targetName`.
 *   Group sites by their enclosing method / function; emit one
 *   IncomingCall per group with the matched ranges.  Receiver-type
 *   resolution is best-effort (we accept some false positives in
 *   exchange for predictable behaviour without worse-reflection
 *   round-trips per call site).
 *
 * - **Outgoing**: locate the target method/function's body and walk
 *   it for MethodCall / NullsafeMethodCall / StaticCall / FuncCall
 *   nodes.  Emit one OutgoingCall per distinct callee identifier
 *   with the call-site ranges grouped.
 *
 * Type-receiver disambiguation (method-call false positives across
 * unrelated classes that share a method name) is intentionally
 * out of scope -- the references infrastructure (Cycle A / K.1) is
 * for the rename + reference flows that demand precision; call
 * hierarchy is a navigation aid where over-reporting is the
 * conventional trade-off (matches IntelliJ Java's behaviour).
 */
final class XphpCallHierarchyHandler implements Handler, CanRegisterCapabilities
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $cache,
        private readonly FqnIndex $fqnIndex,
        private readonly XphpSourceParser $parser,
    ) {
    }

    public function methods(): array
    {
        return [
            'textDocument/prepareCallHierarchy' => 'prepare',
            'callHierarchy/incomingCalls' => 'incomingCalls',
            'callHierarchy/outgoingCalls' => 'outgoingCalls',
        ];
    }

    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        $capabilities->callHierarchyProvider = true;
    }

    /**
     * `prepareCallHierarchy` params are `{textDocument, position}`,
     * matching `TextDocumentPositionParams`.  Typed so phpactor's
     * `LanguageSeverProtocolParamsResolver` deserializes the JSON
     * into a real Params object -- the framework's
     * PassThroughArgumentResolver splats untyped `array $params`
     * into positional args and the handler would silently receive
     * only the textDocument value, never the full params.
     *
     * @return Promise<list<CallHierarchyItem>>
     */
    public function prepare(TextDocumentPositionParams $params): Promise
    {
        $uri = $params->textDocument->uri;
        if (!$this->workspace->has($uri)) {
            return new Success([]);
        }
        $item = $this->workspace->get($uri);
        $result = $this->cache->getOrParse($uri, $item->version, $item->text);
        if ($result->ast === null || $result->ast === []) {
            return new Success([]);
        }
        $positionMap = new PositionMap($item->text);
        $offset = $positionMap->positionToOffset(
            $params->position->line,
            $params->position->character,
        );

        $located = self::findEnclosingCallable($result->ast, $offset);
        if ($located === null) {
            return new Success([]);
        }
        [$classFqn, $node, $name, $namespace] = $located;
        return new Success([
            self::buildItem($uri, $classFqn, $node, $name, $positionMap, $namespace),
        ]);
    }

    /**
     * `callHierarchy/incomingCalls` params are `{item}`.  The
     * framework splats the params object into positional args, so
     * the first positional argument is the inner `item` dict --
     * NOT a wrapper.  Signature reflects that splat order.
     *
     * @param array<string, mixed> $item the inner CallHierarchyItem dict
     * @return Promise<list<CallHierarchyIncomingCall>>
     */
    public function incomingCalls(array $item): Promise
    {
        $targetName = $item['data']['name'] ?? null;
        if (!is_string($targetName) || $targetName === '') {
            return new Success([]);
        }
        $hits = $this->collectCallSites($targetName);
        $grouped = [];
        foreach ($hits as $hit) {
            $key = sprintf('%s|%s|%s', $hit['uri'], $hit['enclosingFqn'] ?? '', $hit['enclosingName']);
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'item' => $hit['enclosingItem'],
                    'ranges' => [],
                ];
            }
            $grouped[$key]['ranges'][] = $hit['range'];
        }
        $calls = [];
        foreach ($grouped as $group) {
            $calls[] = new CallHierarchyIncomingCall($group['item'], $group['ranges']);
        }
        return new Success($calls);
    }

    /**
     * `callHierarchy/outgoingCalls` -- same splat shape as incomingCalls.
     *
     * @param array<string, mixed> $item the inner CallHierarchyItem dict
     * @return Promise<list<CallHierarchyOutgoingCall>>
     */
    public function outgoingCalls(array $item): Promise
    {
        $uri = $item['uri'] ?? null;
        if (!is_string($uri) || !$this->workspace->has($uri)) {
            return new Success([]);
        }
        $classFqn = $item['data']['classFqn'] ?? '';
        $methodName = $item['data']['name'] ?? '';
        if (!is_string($classFqn) || !is_string($methodName) || $methodName === '') {
            return new Success([]);
        }
        $document = $this->workspace->get($uri);
        $result = $this->cache->getOrParse($uri, $document->version, $document->text);
        if ($result->ast === null || $result->ast === []) {
            return new Success([]);
        }
        // Top-level scope sentinel (`__topLevel`) -- walk the file's
        // script-mode statements instead of looking up a method body.
        // See `buildTopLevelItem` for where this sentinel is set.
        if ($methodName === '__topLevel') {
            $body = self::collectTopLevelStmts($result->ast);
        } else {
            $body = self::findMethodOrFunctionBody($result->ast, $classFqn, $methodName);
        }
        if ($body === null || $body === []) {
            return new Success([]);
        }
        $positionMap = new PositionMap($document->text);
        $calls = self::collectOutgoingFromBody($body, $uri, $positionMap);
        return new Success($calls);
    }

    /**
     * Collect every statement that's NOT inside a Function_ or
     * ClassLike across the whole AST (including stmts inside any
     * Namespace_ block).  Used by outgoingCalls when the caller
     * item is the synthetic top-level scope.
     *
     * @param list<Node\Stmt> $ast
     * @return list<Node\Stmt>
     */
    private static function collectTopLevelStmts(array $ast): array
    {
        $out = [];
        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_) {
                foreach ($stmt->stmts as $inner) {
                    if ($inner instanceof ClassLike || $inner instanceof Function_) {
                        continue;
                    }
                    $out[] = $inner;
                }
                continue;
            }
            if ($stmt instanceof ClassLike || $stmt instanceof Function_) {
                continue;
            }
            $out[] = $stmt;
        }
        return $out;
    }

    /**
     * Scan every open document AND every filesystem-indexed
     * .xphp / .php path for call sites whose call-target name
     * matches.  Returns an array of {uri, range, enclosingFqn,
     * enclosingName, enclosingItem}.
     *
     * Filesystem walk mirrors `ReferenceFinder::collectReferences`
     * -- in prod the user typically has only the *callee* file
     * open, so without the FS pass the Callers view would always
     * be empty for callers that live in closed files.
     *
     * @return list<array{uri: string, range: Range, enclosingFqn: ?string, enclosingName: string, enclosingItem: CallHierarchyItem}>
     */
    private function collectCallSites(string $targetName): array
    {
        $hits = [];
        $seenUris = [];
        foreach ($this->workspace as $uri => $document) {
            $uriStr = (string) $uri;
            $seenUris[$uriStr] = true;
            $result = $this->cache->getOrParse($uriStr, $document->version, $document->text);
            if ($result->ast === null || $result->ast === []) {
                continue;
            }
            $positionMap = new PositionMap($document->text);
            $localHits = self::collectCallSitesInAst($result->ast, $targetName, $uriStr, $positionMap);
            foreach ($localHits as $hit) {
                $hits[] = $hit;
            }
        }
        foreach ($this->fqnIndex->indexedFilesystemPaths() as $path) {
            $uri = 'file://' . $path;
            if (isset($seenUris[$uri])) {
                continue;
            }
            try {
                $source = file_get_contents($path);
            } catch (Throwable) {
                continue;
            }
            if ($source === false) {
                continue;
            }
            $ast = $this->parser->parseTolerant($source);
            if ($ast === null || $ast === []) {
                continue;
            }
            $positionMap = new PositionMap($source);
            $localHits = self::collectCallSitesInAst($ast, $targetName, $uri, $positionMap);
            foreach ($localHits as $hit) {
                $hits[] = $hit;
            }
        }
        return $hits;
    }

    /**
     * @param list<Node\Stmt> $ast
     * @return list<array{uri: string, range: Range, enclosingFqn: ?string, enclosingName: string, enclosingItem: CallHierarchyItem}>
     */
    private static function collectCallSitesInAst(array $ast, string $targetName, string $uri, PositionMap $positionMap): array
    {
        $hits = [];
        self::walkForCallSites($ast, '', null, $targetName, $uri, $positionMap, $hits);
        return $hits;
    }

    /**
     * @param list<Node\Stmt>|array<Node\Stmt> $stmts
     * @param array<int, mixed>                $hits
     */
    private static function walkForCallSites(
        array $stmts,
        string $namespace,
        ?ClassLike $enclosingClass,
        string $targetName,
        string $uri,
        PositionMap $positionMap,
        array &$hits,
    ): void {
        // Statements at the current scope level that don't belong to
        // a Function_, ClassMethod, or nested Namespace_ are *top-
        // level script code* (PHP allows arbitrary statements at file
        // root and inside `namespace … { … }` blocks).  Call sites
        // there have no enclosing callable -- collect them so a
        // synthetic "top-level scope" item can carry them in the
        // CallHierarchy result.
        $topLevelStmts = [];
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_) {
                $nextNs = $stmt->name === null ? '' : $stmt->name->toString();
                self::walkForCallSites($stmt->stmts, $nextNs, $enclosingClass, $targetName, $uri, $positionMap, $hits);
                continue;
            }
            if ($stmt instanceof ClassLike) {
                foreach ($stmt->stmts as $member) {
                    if ($member instanceof ClassMethod) {
                        self::scanCallableBody(
                            $member,
                            $namespace,
                            $stmt,
                            $targetName,
                            $uri,
                            $positionMap,
                            $hits,
                        );
                    }
                }
                continue;
            }
            if ($stmt instanceof Function_) {
                self::scanCallableBody($stmt, $namespace, null, $targetName, $uri, $positionMap, $hits);
                continue;
            }
            $topLevelStmts[] = $stmt;
        }
        if ($topLevelStmts !== []) {
            self::scanTopLevelBody($topLevelStmts, $targetName, $uri, $positionMap, $hits);
        }
    }

    /**
     * @param list<Node\Stmt>      $stmts top-level (non-callable, non-class) statements
     * @param array<int, mixed>    $hits
     */
    private static function scanTopLevelBody(
        array $stmts,
        string $targetName,
        string $uri,
        PositionMap $positionMap,
        array &$hits,
    ): void {
        $callRanges = [];
        self::walkForMatchingCalls($stmts, $targetName, $positionMap, $callRanges);
        if ($callRanges === []) {
            return;
        }
        $enclosingItem = self::buildTopLevelItem($uri, $stmts, $positionMap);
        foreach ($callRanges as $range) {
            $hits[] = [
                'uri' => $uri,
                'range' => $range,
                'enclosingFqn' => null,
                'enclosingName' => $enclosingItem->name,
                'enclosingItem' => $enclosingItem,
            ];
        }
    }

    /**
     * Synthesize a CallHierarchyItem representing the top-level
     * scope (script-mode region) of a file.  Used as the
     * `from` of incoming-call hits whose call site sits outside
     * any function/method, and as the receiver of
     * outgoingCalls when the user navigates into it from a
     * Callers view entry.  The `data.name` sentinel is
     * `__topLevel` (a name no userland symbol can collide
     * with -- PHP reserves `__`-prefixed names).
     *
     * @param list<Node\Stmt> $stmts the contiguous top-level statements
     */
    private static function buildTopLevelItem(
        string $uri,
        array $stmts,
        PositionMap $positionMap,
    ): CallHierarchyItem {
        $path = parse_url($uri, PHP_URL_PATH);
        $name = $path !== null && $path !== false ? basename($path) : basename($uri);
        if ($name === '') {
            $name = '<script>';
        }
        $startByte = $stmts[0]->getStartFilePos();
        $endByte = end($stmts)->getEndFilePos();
        if ($startByte < 0 || $endByte < 0 || $endByte < $startByte) {
            $rangeStart = new Position(0, 0);
            $rangeEnd = new Position(0, 0);
        } else {
            [$rsl, $rsc] = $positionMap->offsetToPosition($startByte);
            [$rel, $rec] = $positionMap->offsetToPosition($endByte + 1);
            $rangeStart = new Position($rsl, $rsc);
            $rangeEnd = new Position($rel, $rec);
        }
        return new CallHierarchyItem(
            name: $name,
            kind: SymbolKind::MODULE,
            uri: $uri,
            range: new Range($rangeStart, $rangeEnd),
            selectionRange: new Range(new Position(0, 0), new Position(0, strlen($name))),
            detail: null,
            data: ['classFqn' => '', 'name' => '__topLevel'],
        );
    }

    /**
     * @param array<int, mixed> $hits
     */
    private static function scanCallableBody(
        Function_|ClassMethod $callable,
        string $namespace,
        ?ClassLike $enclosingClass,
        string $targetName,
        string $uri,
        PositionMap $positionMap,
        array &$hits,
    ): void {
        if ($callable->stmts === null) {
            return;
        }
        $enclosingFqn = null;
        $enclosingName = $callable->name->toString();
        if ($callable instanceof ClassMethod) {
            $classShortName = $enclosingClass?->name?->toString() ?? '';
            $enclosingFqn = $namespace !== '' && $classShortName !== ''
                ? $namespace . '\\' . $classShortName
                : $classShortName;
            $enclosingFullName = $enclosingFqn !== '' ? $enclosingFqn . '::' . $enclosingName : $enclosingName;
        } else {
            $enclosingFullName = $namespace !== '' ? $namespace . '\\' . $enclosingName : $enclosingName;
        }
        $enclosingItem = self::buildItem($uri, $enclosingFqn, $callable, $callable->name, $positionMap, $namespace);
        $callRanges = [];
        self::walkForMatchingCalls($callable->stmts, $targetName, $positionMap, $callRanges);

        foreach ($callRanges as $range) {
            $hits[] = [
                'uri' => $uri,
                'range' => $range,
                'enclosingFqn' => $enclosingFqn,
                'enclosingName' => $enclosingFullName,
                'enclosingItem' => $enclosingItem,
            ];
        }
    }

    /**
     * Recursive walk over a callable body; collect Range objects for
     * every MethodCall/NullsafeMethodCall/StaticCall/FuncCall whose
     * called identifier matches the target name.
     *
     * @param iterable<Node>|null   $nodes
     * @param list<Range>           $callRanges
     */
    private static function walkForMatchingCalls(
        ?iterable $nodes,
        string $targetName,
        PositionMap $positionMap,
        array &$callRanges,
    ): void {
        if ($nodes === null) {
            return;
        }
        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                continue;
            }
            $name = self::extractCallIdentifier($node);
            if ($name !== null && $name->toString() === $targetName) {
                $start = $name->getStartFilePos();
                $end = $name->getEndFilePos();
                if ($start >= 0 && $end >= $start) {
                    [$sl, $sc] = $positionMap->offsetToPosition($start);
                    [$el, $ec] = $positionMap->offsetToPosition($end + 1);
                    $callRanges[] = new Range(new Position($sl, $sc), new Position($el, $ec));
                }
            }
            foreach ($node->getSubNodeNames() as $sub) {
                $value = $node->$sub;
                if (is_array($value)) {
                    self::walkForMatchingCalls($value, $targetName, $positionMap, $callRanges);
                } elseif ($value instanceof Node) {
                    self::walkForMatchingCalls([$value], $targetName, $positionMap, $callRanges);
                }
            }
        }
    }

    private static function extractCallIdentifier(Node $node): ?Identifier
    {
        if (($node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof StaticCall)
            && $node->name instanceof Identifier
        ) {
            return $node->name;
        }
        if ($node instanceof FuncCall && $node->name instanceof Node\Name) {
            $parts = $node->name->getParts();
            if ($parts !== []) {
                return new Identifier($parts[count($parts) - 1], $node->name->getAttributes());
            }
        }
        return null;
    }

    /**
     * @param list<Node\Stmt> $ast
     * @return ?array{0: ?string, 1: Function_|ClassMethod, 2: Identifier, 3: string}
     */
    private static function findEnclosingCallable(array $ast, int $offset): ?array
    {
        $found = null;
        $walker = static function (array $stmts, string $namespace, ?ClassLike $cls) use (&$walker, $offset, &$found): void {
            foreach ($stmts as $stmt) {
                if ($stmt instanceof Namespace_) {
                    $nextNs = $stmt->name === null ? '' : $stmt->name->toString();
                    $walker($stmt->stmts, $nextNs, null);
                    continue;
                }
                if ($stmt instanceof ClassLike) {
                    foreach ($stmt->stmts as $member) {
                        if ($member instanceof ClassMethod) {
                            $start = $member->getStartFilePos();
                            $end = $member->getEndFilePos();
                            if ($start >= 0 && $end >= 0 && $offset >= $start && $offset <= $end) {
                                $classShort = $stmt->name?->toString() ?? '';
                                $fqn = $namespace !== '' && $classShort !== ''
                                    ? $namespace . '\\' . $classShort
                                    : $classShort;
                                $found = [$fqn, $member, $member->name, $namespace];
                                return;
                            }
                        }
                    }
                    continue;
                }
                if ($stmt instanceof Function_) {
                    $start = $stmt->getStartFilePos();
                    $end = $stmt->getEndFilePos();
                    if ($start >= 0 && $end >= 0 && $offset >= $start && $offset <= $end) {
                        $found = [null, $stmt, $stmt->name, $namespace];
                        return;
                    }
                }
            }
        };
        $walker($ast, '', null);
        return $found;
    }

    /**
     * @param list<Node\Stmt> $ast
     * @return list<Node\Stmt>|null
     */
    private static function findMethodOrFunctionBody(array $ast, string $classFqn, string $methodName): ?array
    {
        $found = null;
        $walker = static function (array $stmts, string $namespace) use (&$walker, $classFqn, $methodName, &$found): void {
            foreach ($stmts as $stmt) {
                if ($stmt instanceof Namespace_) {
                    $nextNs = $stmt->name === null ? '' : $stmt->name->toString();
                    $walker($stmt->stmts, $nextNs);
                    continue;
                }
                if ($stmt instanceof ClassLike && $classFqn !== '') {
                    $classShort = $stmt->name?->toString() ?? '';
                    $thisFqn = $namespace !== '' && $classShort !== ''
                        ? $namespace . '\\' . $classShort
                        : $classShort;
                    if ($thisFqn !== ltrim($classFqn, '\\')) {
                        continue;
                    }
                    foreach ($stmt->stmts as $member) {
                        if ($member instanceof ClassMethod && $member->name->toString() === $methodName) {
                            $found = $member->stmts ?? [];
                            return;
                        }
                    }
                    continue;
                }
                if ($stmt instanceof Function_ && $classFqn === '') {
                    $localName = $stmt->name->toString();
                    $thisFqn = $namespace !== '' ? $namespace . '\\' . $localName : $localName;
                    if ($thisFqn === $methodName || $localName === $methodName) {
                        $found = $stmt->stmts ?? [];
                        return;
                    }
                }
            }
        };
        $walker($ast, '');
        return $found;
    }

    /**
     * @param list<Node\Stmt> $body
     * @return list<CallHierarchyOutgoingCall>
     */
    private static function collectOutgoingFromBody(array $body, string $uri, PositionMap $positionMap): array
    {
        /** @var array<string, array{name: string, kind: int, ranges: list<Range>}> $byCallee */
        $byCallee = [];
        self::walkForOutgoingCalls($body, $positionMap, $byCallee);

        $calls = [];
        foreach ($byCallee as $entry) {
            $item = new CallHierarchyItem(
                name: $entry['name'],
                kind: $entry['kind'],
                uri: $uri,
                range: $entry['ranges'][0],
                selectionRange: $entry['ranges'][0],
                detail: null,
                data: ['name' => $entry['name']],
            );
            $calls[] = new CallHierarchyOutgoingCall($item, $entry['ranges']);
        }
        return $calls;
    }

    /**
     * @param iterable<Node>|null $nodes
     * @param array<string, array{name: string, kind: int, ranges: list<Range>}> $byCallee
     */
    private static function walkForOutgoingCalls(?iterable $nodes, PositionMap $positionMap, array &$byCallee): void
    {
        if ($nodes === null) {
            return;
        }
        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                continue;
            }
            $info = self::extractOutgoingCallInfo($node);
            if ($info !== null) {
                [$name, $kind, $nameNode] = $info;
                $start = $nameNode->getStartFilePos();
                $end = $nameNode->getEndFilePos();
                if ($start >= 0 && $end >= $start) {
                    [$sl, $sc] = $positionMap->offsetToPosition($start);
                    [$el, $ec] = $positionMap->offsetToPosition($end + 1);
                    $range = new Range(new Position($sl, $sc), new Position($el, $ec));
                    if (!isset($byCallee[$name])) {
                        $byCallee[$name] = ['name' => $name, 'kind' => $kind, 'ranges' => []];
                    }
                    $byCallee[$name]['ranges'][] = $range;
                }
            }
            foreach ($node->getSubNodeNames() as $sub) {
                $value = $node->$sub;
                if (is_array($value)) {
                    self::walkForOutgoingCalls($value, $positionMap, $byCallee);
                } elseif ($value instanceof Node) {
                    self::walkForOutgoingCalls([$value], $positionMap, $byCallee);
                }
            }
        }
    }

    /**
     * @return ?array{0: string, 1: int, 2: Identifier|Node\Name}
     */
    private static function extractOutgoingCallInfo(Node $node): ?array
    {
        if (($node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof StaticCall)
            && $node->name instanceof Identifier
        ) {
            return [$node->name->toString(), SymbolKind::METHOD, $node->name];
        }
        if ($node instanceof FuncCall && $node->name instanceof Node\Name) {
            $parts = $node->name->getParts();
            if ($parts !== []) {
                return [$parts[count($parts) - 1], SymbolKind::FUNCTION, $node->name];
            }
        }
        return null;
    }

    private static function buildItem(
        string $uri,
        ?string $classFqn,
        Function_|ClassMethod $callable,
        Identifier $name,
        PositionMap $positionMap,
        string $namespace = '',
    ): CallHierarchyItem {
        $bodyStart = $callable->getStartFilePos();
        $bodyEnd = $callable->getEndFilePos();
        $nameStart = $name->getStartFilePos();
        $nameEnd = $name->getEndFilePos();
        [$rsl, $rsc] = $positionMap->offsetToPosition(max(0, $bodyStart));
        [$rel, $rec] = $positionMap->offsetToPosition(max(0, $bodyEnd) + 1);
        [$ssl, $ssc] = $positionMap->offsetToPosition(max(0, $nameStart));
        [$sel, $sec] = $positionMap->offsetToPosition(max(0, $nameEnd) + 1);
        $shortName = $name->toString();
        if ($classFqn !== null && $classFqn !== '') {
            $displayName = $classFqn . '::' . $shortName;
        } elseif ($namespace !== '') {
            $displayName = $namespace . '\\' . $shortName;
        } else {
            $displayName = $shortName;
        }
        return new CallHierarchyItem(
            name: $displayName,
            kind: $callable instanceof ClassMethod ? SymbolKind::METHOD : SymbolKind::FUNCTION,
            uri: $uri,
            range: new Range(new Position($rsl, $rsc), new Position($rel, $rec)),
            selectionRange: new Range(new Position($ssl, $ssc), new Position($sel, $sec)),
            detail: null,
            data: ['classFqn' => $classFqn ?? '', 'name' => $shortName],
        );
    }

}
