<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\CancellationToken;
use Amp\Promise;
use Amp\Success;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\CodeLens;
use Phpactor\LanguageServerProtocol\CodeLensOptions;
use Phpactor\LanguageServerProtocol\CodeLensParams;
use Phpactor\LanguageServerProtocol\Command;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Resolver\ReferenceFinder;

/**
 * `textDocument/codeLens` + `codeLens/resolve` handler.
 *
 * Emits a "Show references" lens above every class, interface, trait,
 * enum, function, and method declaration in the active document.
 * Each lens carries an `editor.action.showReferences` Command -- the
 * de-facto LSP client-side convention (VS Code / LSP4IJ / Helix all
 * recognize the name) -- with Location[] in the arguments.  Clicking
 * the lens opens the references popup via XphpShowReferencesCommandsSupport
 * (or the client's built-in handler for that command name).
 *
 * Two-phase emission (LSP 3.17 codeLens/resolve protocol):
 *
 *   1. textDocument/codeLens  -> emit lens with `range` + placeholder
 *      `command: {title: "Show references"}` (no `command.command`,
 *      no arguments) + `data: {uri, line, character}`.  Pure-AST work,
 *      no ReferenceFinder calls.  ~10ms per file regardless of
 *      workspace size.
 *
 *   2. codeLens/resolve       -> read `data`, run ReferenceFinder
 *      against the saved position, return the lens with full
 *      `command: {title: "N usages", command, arguments: [uri,
 *      position, locations]}` populated.
 *
 * Clients (PhpStorm LSP4IJ, VS Code) typically resolve only the
 * lenses currently visible in the viewport -- lenses below the fold
 * stay placeholders, so emitting D lenses is cheap regardless of how
 * many actually get resolved.  Worst case is the user views all D
 * declarations, at which point total work matches the pre-3.17 eager
 * pattern; common case is V << D so total work is `O(V * F * N)`
 * instead of `O(D * F * N)`.
 *
 * Lens placement: the lens range covers just the identifier token,
 * matching the convention IntelliJ / VS Code use to anchor a single-
 * line gutter clickable.
 */
final class XphpCodeLensHandler implements Handler, CanRegisterCapabilities
{
    /**
     * Client-side command name -- recognized by VS Code, PhpStorm
     * LSP4IJ, Helix, and every other mainline LSP client.
     */
    public const COMMAND_NAME = 'editor.action.showReferences';

    /**
     * Placeholder title shown until the lens is resolved.  Users
     * sometimes see this briefly while scrolling new lenses into
     * view; once `codeLens/resolve` returns, the title flips to
     * "N usages".
     */
    private const PLACEHOLDER_TITLE = 'Show references';

    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $cache,
        private readonly ReferenceFinder $finder,
    ) {
    }

    public function methods(): array
    {
        return [
            'textDocument/codeLens' => 'codeLens',
            'codeLens/resolve' => 'resolve',
        ];
    }

    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        // resolveProvider=true tells the client our initial codeLens
        // response carries unresolved lenses (command without the
        // `command` field set) and the client should call
        // codeLens/resolve to populate them.
        $capabilities->codeLensProvider = new CodeLensOptions(resolveProvider: true);
    }

    /**
     * @return Promise<list<CodeLens>>
     */
    public function codeLens(CodeLensParams $params, ?CancellationToken $cancel = null): Promise
    {
        if ($cancel !== null && $cancel->isRequested()) {
            return new Success([]);
        }
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
        return new Success(self::buildLenses($uri, $result->ast, $positionMap));
    }

    /**
     * `codeLens/resolve` -- fill in the command + arguments for one
     * unresolved lens.  Called by the client (lazily, typically when
     * the lens enters the editor viewport) for every lens we emitted
     * with a placeholder command in the initial textDocument/codeLens
     * response.
     *
     * The unresolved lens carries `data: {uri, line, character}`;
     * those let us re-run findReferences without depending on any
     * server-side state held between calls.
     */
    public function resolve(CodeLens $lens, ?CancellationToken $cancel = null): Promise
    {
        if ($cancel !== null && $cancel->isRequested()) {
            return new Success($lens);
        }
        $data = self::extractData($lens);
        if ($data === null) {
            return new Success($lens);
        }
        [$uri, $line, $character] = $data;
        if (!$this->workspace->has($uri)) {
            return new Success($lens);
        }
        $item = $this->workspace->get($uri);
        $positionMap = new PositionMap($item->text);
        $byteOffset = $positionMap->positionToOffset($line, $character);
        $locations = $this->finder->findReferences($uri, $byteOffset, false);
        $count = count($locations);
        $lens->command = new Command(
            title: $count . ' usage' . ($count === 1 ? '' : 's'),
            command: self::COMMAND_NAME,
            arguments: [$uri, ['line' => $line, 'character' => $character], $locations],
        );
        return new Success($lens);
    }

    /**
     * @return array{0: string, 1: int, 2: int}|null tuple of {uri, line, character}
     */
    private static function extractData(CodeLens $lens): ?array
    {
        $data = $lens->data;
        if (!is_array($data)) {
            return null;
        }
        $uri = $data['uri'] ?? null;
        $line = $data['line'] ?? null;
        $character = $data['character'] ?? null;
        if (!is_string($uri) || !is_int($line) || !is_int($character)) {
            return null;
        }
        return [$uri, $line, $character];
    }

    /**
     * Top-level walk: visit every top-level Stmt; for each
     * namespace declaration, recurse into its body.  ClassLike
     * bodies are walked manually for ClassMethods so we don't need
     * a generic NodeVisitor (which complicates mutation-test
     * matching of the anonymous-class methods inside it).
     *
     * @param list<Node\Stmt> $ast
     * @return list<CodeLens>
     */
    private static function buildLenses(string $uri, array $ast, PositionMap $positionMap): array
    {
        $lenses = [];
        self::collectLenses($ast, $uri, $positionMap, $lenses);
        return $lenses;
    }

    /**
     * @param list<Node\Stmt>|array<Node\Stmt> $stmts
     * @param list<CodeLens>                   $lenses
     */
    private static function collectLenses(array $stmts, string $uri, PositionMap $positionMap, array &$lenses): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_) {
                self::collectLenses($stmt->stmts, $uri, $positionMap, $lenses);
                continue;
            }
            if ($stmt instanceof ClassLike) {
                self::appendIdentifierLens($stmt->name, $uri, $positionMap, $lenses);
                foreach ($stmt->stmts as $member) {
                    if ($member instanceof ClassMethod) {
                        self::appendIdentifierLens($member->name, $uri, $positionMap, $lenses);
                    }
                }
                continue;
            }
            if ($stmt instanceof Function_) {
                self::appendIdentifierLens($stmt->name, $uri, $positionMap, $lenses);
            }
        }
    }

    /**
     * Emit one UNRESOLVED lens: range + placeholder title + 2-element
     * arguments + data.  The arguments shape is `[uri, position]`
     * (locations slot deliberately absent) so the client-side plugin
     * handler can fall back to a fresh `textDocument/references`
     * fetch when it sees the locations missing.
     *
     * Why not the LSP-canonical "omit command, let the client call
     * `codeLens/resolve` before render" pattern?  PhpStorm's LSP4IJ
     * adapter doesn't implement viewport-aware resolve -- it renders
     * unresolved lenses as-is and dispatches whatever `command.command`
     * happens to be set (including the empty string, which then errors
     * inside phpactor's CommandDispatcher).  Setting the command name
     * up front sidesteps that whole class of failure; clients that DO
     * implement resolve (VS Code) still get the count + baked
     * locations via the `resolve()` handler below.
     *
     * `data` carries `{uri, line, character}` so `resolve()` can
     * re-derive the byte offset and run findReferences without
     * depending on server-side state held between calls.
     *
     * @param list<CodeLens> $lenses
     */
    private static function appendIdentifierLens(
        ?Node\Identifier $identifier,
        string $uri,
        PositionMap $positionMap,
        array &$lenses,
    ): void {
        if ($identifier === null) {
            return;
        }
        $start = $identifier->getStartFilePos();
        $end = $identifier->getEndFilePos();
        if ($start < 0 || $end < $start) {
            return;
        }
        [$startLine, $startChar] = $positionMap->offsetToPosition($start);
        [$endLine, $endChar] = $positionMap->offsetToPosition($end + 1);

        $lens = new CodeLens(
            new Range(new Position($startLine, $startChar), new Position($endLine, $endChar)),
            new Command(
                title: self::PLACEHOLDER_TITLE,
                command: self::COMMAND_NAME,
                arguments: [$uri, ['line' => $startLine, 'character' => $startChar]],
            ),
        );
        $lens->data = [
            'uri' => $uri,
            'line' => $startLine,
            'character' => $startChar,
        ];
        $lenses[] = $lens;
    }
}
