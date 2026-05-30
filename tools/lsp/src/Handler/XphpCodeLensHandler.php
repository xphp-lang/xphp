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
 * `textDocument/codeLens` handler.
 *
 * Emits a "Show references" lens above every class, interface, trait,
 * enum, function, and method declaration in the active document.
 * Each lens carries an `editor.action.showReferences` Command -- the
 * de-facto LSP client-side convention (VS Code / LSP4IJ / Helix all
 * recognize the name) -- with pre-computed Location[] baked into the
 * arguments.  Clicking the lens opens the Find Usages panel directly
 * without round-tripping through `workspace/executeCommand`, which
 * the IDE plugin's LSP transport can't surface as a UI action.
 *
 * Command arguments shape: `[uri: string, position: Position,
 * locations: Location[]]`.  PhpStorm's LSP4IJ adapter (and VS
 * Code's built-in handler) interpret this triple natively.
 *
 * Lens placement: the lens range covers just the identifier token,
 * matching the convention IntelliJ / VS Code use to anchor a single-
 * line gutter clickable.
 *
 * Cost: one full ReferenceFinder walk per declaration at lens-
 * emission time.  Acceptable for small workspaces; a follow-up can
 * thread `codeLens/resolve` for lazy on-click resolution at large
 * scale.
 */
final class XphpCodeLensHandler implements Handler, CanRegisterCapabilities
{
    /**
     * Client-side command name -- recognized by VS Code, PhpStorm
     * LSP4IJ, Helix, and every other mainline LSP client.
     */
    public const COMMAND_NAME = 'editor.action.showReferences';

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
        ];
    }

    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        // Locations baked in upfront; no codeLens/resolve flow needed.
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
        return new Success($this->buildLenses($uri, $result->ast, $positionMap));
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
    private function buildLenses(string $uri, array $ast, PositionMap $positionMap): array
    {
        $lenses = [];
        $this->collectLenses($ast, $uri, $positionMap, $lenses);
        return $lenses;
    }

    /**
     * @param list<Node\Stmt>|array<Node\Stmt> $stmts
     * @param list<CodeLens>                   $lenses
     */
    private function collectLenses(array $stmts, string $uri, PositionMap $positionMap, array &$lenses): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_) {
                $this->collectLenses($stmt->stmts, $uri, $positionMap, $lenses);
                continue;
            }
            if ($stmt instanceof ClassLike) {
                $this->appendIdentifierLens($stmt->name, $uri, $positionMap, $lenses);
                foreach ($stmt->stmts as $member) {
                    if ($member instanceof ClassMethod) {
                        $this->appendIdentifierLens($member->name, $uri, $positionMap, $lenses);
                    }
                }
                continue;
            }
            if ($stmt instanceof Function_) {
                $this->appendIdentifierLens($stmt->name, $uri, $positionMap, $lenses);
            }
        }
    }

    /**
     * @param list<CodeLens> $lenses
     */
    private function appendIdentifierLens(
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
        $position = ['line' => $startLine, 'character' => $startChar];

        // Pre-compute references so the lens click opens Find Usages
        // immediately via client-side dispatch -- no executeCommand
        // round-trip required.  `includeDeclaration: false` so the
        // panel only lists actual call sites, not the declaration
        // itself.
        $locations = $this->finder->findReferences($uri, $start, false);

        $lenses[] = new CodeLens(
            new Range(new Position($startLine, $startChar), new Position($endLine, $endChar)),
            new Command(
                title: count($locations) . ' usage' . (count($locations) === 1 ? '' : 's'),
                command: self::COMMAND_NAME,
                arguments: [$uri, $position, $locations],
            ),
        );
    }
}
