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

/**
 * Cycle G — `textDocument/codeLens` handler.
 *
 * Emits a "Show references" lens above every class, interface, trait,
 * enum, function, and method declaration in the active document.  Each
 * lens carries a `Command` whose name (`xphp.showReferences`) the
 * client can route to its native find-usages action; the arguments
 * pass the document URI and the LSP Position of the declaration's
 * identifier token, so the client (or a follow-up `codeLens/resolve`)
 * can dispatch to `textDocument/references` without re-walking the
 * AST.
 *
 * Lens placement: the lens range covers just the identifier token,
 * matching the convention IntelliJ / VS Code use to anchor a single-
 * line gutter clickable.
 *
 * V1 deliberately does NOT pre-compute reference counts -- that's a
 * workspace-wide walk per lens which would block the initial
 * codeLens response on large workspaces.  Count enrichment is a
 * follow-up (codeLens/resolve, currently not wired) -- the V1 title
 * is the static string "Show references".
 */
final class XphpCodeLensHandler implements Handler, CanRegisterCapabilities
{
    public const COMMAND_NAME = 'xphp.showReferences';

    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $cache,
    ) {
    }

    public function methods(): array
    {
        return [
            'textDocument/codeLens' => 'codeLens',
        ];
    }

    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        // resolveProvider is false until a follow-up wires reference-
        // count enrichment.  Setting it true today would imply the
        // server fills in a Command via codeLens/resolve, but the
        // initial response already carries one.
        $capabilities->codeLensProvider = new CodeLensOptions(resolveProvider: false);
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
        $lenses[] = new CodeLens(
            new Range(new Position($startLine, $startChar), new Position($endLine, $endChar)),
            new Command(
                title: 'Show references',
                command: self::COMMAND_NAME,
                arguments: [$uri, ['line' => $startLine, 'character' => $startChar]],
            ),
        );
    }
}
