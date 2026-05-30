<?php

declare(strict_types=1);

namespace XPHP\Lsp;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Adapter\Psr\AggregateEventDispatcher;
use Phpactor\LanguageServer\Core\Command\ClosureCommand;
use Phpactor\LanguageServer\Core\Command\CommandDispatcher;
use Phpactor\LanguageServer\Core\Dispatcher\ArgumentResolver\ChainArgumentResolver;
use Phpactor\LanguageServer\Core\Dispatcher\ArgumentResolver\LanguageSeverProtocolParamsResolver;
use Phpactor\LanguageServer\Core\Dispatcher\ArgumentResolver\PassThroughArgumentResolver;
use Phpactor\LanguageServer\Core\Dispatcher\Dispatcher;
use Phpactor\LanguageServer\Core\Dispatcher\Dispatcher\MiddlewareDispatcher;
use Phpactor\LanguageServer\Core\Dispatcher\DispatcherFactory;
use Phpactor\LanguageServer\Core\Diagnostics\DiagnosticsEngine;
use Phpactor\LanguageServer\Core\Handler\HandlerMethodRunner;
use Phpactor\LanguageServer\Core\Handler\Handlers;
use Phpactor\LanguageServer\Core\Server\ClientApi;
use Phpactor\LanguageServer\Core\Server\ResponseWatcher\DeferredResponseWatcher;
use Phpactor\LanguageServer\Core\Server\RpcClient\JsonRpcClient;
use Phpactor\LanguageServer\Core\Server\Transmitter\MessageTransmitter;
use Phpactor\LanguageServer\Core\Service\ServiceManager;
use Phpactor\LanguageServer\Core\Service\ServiceProviders;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServer\Handler\System\ExitHandler;
use Phpactor\LanguageServer\Handler\System\ServiceHandler;
use XPHP\Lsp\Handler\XphpTextDocumentHandler;
use Phpactor\LanguageServer\Handler\Workspace\CommandHandler;
use Phpactor\LanguageServer\Listener\DidChangeWatchedFilesListener;
use Phpactor\LanguageServer\Listener\ServiceListener;
use Phpactor\LanguageServer\Listener\WorkspaceListener;
use Phpactor\LanguageServer\Middleware\CancellationMiddleware;
use Phpactor\LanguageServer\Middleware\ErrorHandlingMiddleware;
use Phpactor\LanguageServer\Middleware\HandlerMiddleware;
use Phpactor\LanguageServer\Middleware\InitializeMiddleware;
use Phpactor\LanguageServer\Middleware\ResponseHandlingMiddleware;
use Phpactor\LanguageServer\Middleware\ShutdownMiddleware;
use Phpactor\LanguageServer\Service\DiagnosticsService;
use Phpactor\LanguageServerProtocol\InitializeParams;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Analyzer\WorkspaceAnalyzer;
use XPHP\Lsp\Diagnostics\XphpDiagnosticsProvider;
use XPHP\Lsp\Handler\WorkspaceSymbols;
use XPHP\Lsp\Handler\XphpCompletionHandler;
use XPHP\Lsp\Handler\XphpDefinitionHandler;
use XPHP\Lsp\Handler\XphpDocumentSymbolHandler;
use XPHP\Lsp\Handler\XphpCodeActionHandler;
use XPHP\Lsp\Handler\XphpCodeActionResolveHandler;
use XPHP\Lsp\Handler\XphpCompletionResolveHandler;
use XPHP\Lsp\Handler\XphpDocumentHighlightHandler;
use XPHP\Lsp\Handler\XphpCallHierarchyHandler;
use XPHP\Lsp\Handler\XphpCodeLensHandler;
use XPHP\Lsp\Handler\XphpFoldingRangeHandler;
use XPHP\Lsp\Handler\XphpInlayHintHandler;
use XPHP\Lsp\Handler\XphpSignatureHelpHandler;
use XPHP\Lsp\Handler\XphpTypeDefinitionHandler;
use XPHP\Lsp\Handler\XphpFileWatcherHandler;
use XPHP\Lsp\Handler\XphpHoverHandler;
use XPHP\Lsp\Handler\XphpReferencesHandler;
use XPHP\Lsp\Handler\XphpRenameHandler;
use XPHP\Lsp\Handler\XphpWillRenameFilesHandler;
use XPHP\Lsp\Handler\XphpImplementationHandler;
use XPHP\Lsp\Handler\XphpPullDiagnosticsHandler;
use XPHP\Lsp\Handler\XphpSemanticTokensHandler;
use XPHP\Lsp\Handler\XphpTypeHierarchyHandler;
use XPHP\Lsp\Handler\XphpWorkspaceSymbolHandler;
use XPHP\Lsp\Reflection\ReflectorFactory;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Resolver\CompletionIndex;
use XPHP\Lsp\Resolver\CompositeClassLikeLookup;
use XPHP\Lsp\Resolver\FilesystemClassLikeLookup;
use XPHP\Lsp\Resolver\GenericParamRegistry;
use XPHP\Lsp\Resolver\GenericResolver;
use XPHP\Lsp\Resolver\DiagnosticCodeActionProvider;
use XPHP\Lsp\Resolver\ImportCodeActionProvider;
use XPHP\Lsp\Resolver\OptimizeImportsCodeActionProvider;
use XPHP\Lsp\Resolver\PhpCompletionResolver;
use XPHP\Lsp\Resolver\ReferenceFinder;
use XPHP\Lsp\Resolver\RenameProvider;
use XPHP\Lsp\Resolver\WorkspaceClassLikeLookup;
use XPHP\Lsp\Resolver\PhpDefinitionResolver;
use XPHP\Lsp\Resolver\PhpHoverResolver;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Builds the LSP dispatcher with the standard phpactor middleware stack and
 * registers the xphp diagnostics provider. Closely mirrors the acme-ls example
 * shipped with phpactor/language-server (lib/example/server/acme-ls/) — every
 * piece here exists in that template; the only xphp-specific wiring is the
 * DiagnosticsProvider construction.
 *
 * Service announcement: the diagnostics service is enabled by default at
 * initialize (see InitializeMiddleware below — phpactor auto-starts services
 * named in initializationOptions.initializedServices, or all services if no
 * list is provided by the client).
 */
final class LspDispatcherFactory implements DispatcherFactory
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
        /**
         * Diagnostics debounce window, in milliseconds. 300 ms is the LSP-community
         * default — long enough to coalesce per-keystroke storms, short enough that
         * the user perceives diagnostics as "live."
         */
        private readonly int $diagnosticsDebounceMs = 300,
    ) {
    }

    public function create(MessageTransmitter $transmitter, InitializeParams $initializeParams): Dispatcher
    {
        $responseWatcher = new DeferredResponseWatcher();
        $clientApi = new ClientApi(new JsonRpcClient($transmitter, $responseWatcher));

        $workspace = new PhpactorWorkspace($this->logger);
        // Shared analyzer + version-keyed AST cache, scoped to this LSP session.
        // Every handler (hover, definition, completion, diagnostics) reads
        // through the cache so a workspace pass costs O(unchanged docs serves
        // from cache) rather than O(N parses per keystroke).
        $xphpParser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $analyzer = new Analyzer($xphpParser);
        $cache = new ParsedDocumentCache($analyzer);

        // Worse-reflection-backed engine for PHP-semantic GTD/hover/completion
        // -- everything beyond xphp generics.  Built once per LSP session and
        // shared across resolvers.  `rootPath` is what `InitializeParams`
        // hands us as the project workspace root; an empty string => no
        // filesystem walking (workspace + stubs only).
        $rootPath = $initializeParams->rootPath ?? '';
        // FqnIndex is the single workspace-wide FQN -> declaration map.
        // Replaces three parallel walks (FilesystemSourceLocator's private
        // map, WorkspaceSymbols' open-doc walk, WorkspaceClassLikeLookup's
        // open-doc walk).  Phase-0 of the LSP follow-up roadmap.
        $fqnIndex = new FqnIndex($workspace, $cache, $xphpParser, $rootPath);
        $reflector = (new ReflectorFactory(
            $workspace,
            $cache,
            $xphpParser,
            $rootPath,
            ReflectorFactory::defaultStubPath(),
            ReflectorFactory::defaultCacheDir(),
            $fqnIndex,
        ))->build();
        // Per-session registry of (namespace, paramName) pairs harvested from
        // generic ClassLike declarations in open documents.  Resolvers query
        // this when formatting type names so a post-strip placeholder
        // reference like `App\Containers\T` renders as `T` in hover/completion
        // detail, matching what the user wrote in the original `<T>` source.
        // Phase 0.5: GenericParamRegistry now consumes FqnIndex (open + filesystem)
        // so the prettify pass sees placeholder names from filesystem-only
        // classes too -- not just open-doc declarations.
        $genericParams = new GenericParamRegistry($fqnIndex);
        // GenericResolver is a stronger pass that does actual type-arg
        // substitution: `$user = $users->first()` where `$users = new
        // Collection<User>(...)` resolves to `?App\Models\User` rather than
        // the unresolved `?T` the prettify path can produce.  Consulted
        // first in renderVariable; falls back to prettify on misses.
        // Composite chain: workspace (live) -> filesystem (on-disk).  Open-doc
        // declarations win; closed-file declarations fall through to the
        // filesystem-backed FilesystemClassLikeLookup, which re-parses on
        // demand via FqnIndex and returns the ClassLike with xphp attributes
        // intact -- exactly what GenericResolver needs to substitute type-args
        // when Collection.xphp isn't open in the editor.
        $classLikeLookup = new CompositeClassLikeLookup(
            new WorkspaceClassLikeLookup($workspace, $cache),
            new FilesystemClassLikeLookup($fqnIndex),
        );
        $genericResolver = new GenericResolver($workspace, $cache, $classLikeLookup, $xphpParser, $fqnIndex);
        // PhpDefinitionResolver takes GenericResolver too (Phase 0.7) so GTD
        // on property access through a generic method's return type can
        // resolve via the substituted receiver class.
        $phpDefinitionResolver = new PhpDefinitionResolver($workspace, $xphpParser, $reflector, $cache, $genericResolver);
        $phpHoverResolver = new PhpHoverResolver($workspace, $xphpParser, $reflector, $genericParams, $genericResolver);

        $diagnosticsProvider = new XphpDiagnosticsProvider(
            $cache,
            new WorkspaceAnalyzer(),
            $workspace,
            $fqnIndex,
        );

        $diagnosticsEngine = new DiagnosticsEngine(
            $clientApi,
            $this->logger,
            [$diagnosticsProvider],
            $this->diagnosticsDebounceMs,
        );

        $diagnosticsService = new DiagnosticsService($diagnosticsEngine, workspace: $workspace);

        $serviceProviders = new ServiceProviders($diagnosticsService);
        $serviceManager = new ServiceManager($serviceProviders, $this->logger);

        // DiagnosticsService is both a ServiceProvider AND a ListenerProviderInterface —
        // registering it directly on the event dispatcher is what subscribes
        // provideDiagnostics() to didOpen / didChange / didSave events.
        //
        // Phase 2.4: DidChangeWatchedFilesListener (phpactor-shipped) listens
        // for the `initialized` event and sends `client/registerCapability`
        // back to the client to subscribe to fs-watch notifications for
        // **/*.xphp and **/*.php.  PhpStorm + VS Code both advertise
        // `dynamicRegistration: true` for this; on clients that don't, the
        // listener silently no-ops and the filesystem index stays
        // one-shot-at-first-query (the pre-2.4 behaviour).
        $eventDispatcher = new AggregateEventDispatcher(
            new ServiceListener($serviceManager),
            new WorkspaceListener($workspace),
            new DidChangeWatchedFilesListener(
                $clientApi,
                ['**/*.xphp', '**/*.php'],
                $initializeParams->capabilities,
            ),
            // Fix I: warm the FQN index off the `Initialized` event so
            // the first user-facing hover/definition/completion doesn't
            // pay the ~500ms filesystem-walk cost in-band.  Async via
            // Amp\asyncCall -- doesn't block the initialize handshake.
            new \XPHP\Lsp\Reflection\FqnIndexWarmer($fqnIndex),
            // Perf #1: warm ParsedDocumentCache with every filesystem-
            // indexed file so the cold first `textDocument/references`
            // (codeLens click, Alt+F7) skips the per-file parse step --
            // dominant cost in the prod 7.5s/click measurement.
            // Runs on the same Initialized event, independently of the
            // FQN warmer above; both are asyncCall-dispatched.
            new \XPHP\Lsp\Analyzer\ParsedDocumentCacheWarmer($fqnIndex, $cache, $workspace),
            $diagnosticsService,
        );

        // Single WorkspaceSymbols shared across the two handlers that need it
        // (completion + definition).  Both call the same in-memory AST cache,
        // so reusing the helper avoids constructing parallel collectors.
        $workspaceSymbols = new WorkspaceSymbols($workspace, $cache);

        // CompletionIndex unifies workspace + stubs FQNs.  Stubs are loaded
        // from the same path we already extracted to in `ReflectorFactory`,
        // so the index's stubs portion is a one-time JSON read on first use
        // (built on demand if missing).
        $completionIndex = new CompletionIndex($workspaceSymbols, ReflectorFactory::defaultStubPath());
        $phpCompletionResolver = new PhpCompletionResolver(
            $workspace,
            $xphpParser,
            $reflector,
            $completionIndex,
            $cache,
            $genericParams,
            $genericResolver,
        );

        $handlers = new Handlers(
            new XphpTextDocumentHandler($eventDispatcher),
            new ServiceHandler($serviceManager, $clientApi),
            new CommandHandler(new CommandDispatcher([
                // CodeLens emits `editor.action.showReferences` with
                // locations baked in -- VS Code, PhpStorm LSP4IJ, and
                // Helix all dispatch this name client-side and open
                // the Find Usages panel without round-tripping.
                // Register a server-side no-op as a safety net: any
                // client that doesn't recognize the convention will
                // fall back to `workspace/executeCommand`, and the
                // CommandDispatcher would throw on an unknown
                // command name -- phpactor's framework would surface
                // that as a JSON-RPC error toast.  Returning null
                // here makes the unhandled-by-client path silently
                // do nothing instead.
                XphpCodeLensHandler::COMMAND_NAME => new ClosureCommand(
                    static fn (...$args): \Amp\Promise => new \Amp\Success(null),
                ),
            ])),
            new ExitHandler(),
            new XphpHoverHandler($workspace, $cache, $phpHoverResolver),
            new XphpDefinitionHandler(
                $workspace,
                $cache,
                $workspaceSymbols,
                $fqnIndex,
                new ReferenceFinder($workspace, $cache, $fqnIndex, $xphpParser, $reflector, $genericResolver),
                $phpDefinitionResolver,
            ),
            new XphpTypeDefinitionHandler($phpDefinitionResolver),
            new XphpCompletionHandler($workspace, $workspaceSymbols, $phpCompletionResolver, $fqnIndex, $reflector),
            new XphpCompletionResolveHandler($reflector),
            new XphpSignatureHelpHandler($workspace, $cache, $xphpParser, $reflector),
            new XphpInlayHintHandler($workspace, $cache, $genericResolver),
            new XphpCodeActionHandler(
                $workspace,
                new ImportCodeActionProvider($fqnIndex, $cache),
                new DiagnosticCodeActionProvider(),
                new OptimizeImportsCodeActionProvider($cache),
            ),
            new XphpCodeActionResolveHandler(),
            new XphpDocumentSymbolHandler($workspace, $cache),
            new XphpCallHierarchyHandler($workspace, $cache, $fqnIndex, $xphpParser),
            new XphpCodeLensHandler(
                $workspace,
                $cache,
                new ReferenceFinder($workspace, $cache, $fqnIndex, $xphpParser, $reflector, $genericResolver),
            ),
            new XphpFoldingRangeHandler($workspace, $cache),
            new XphpWorkspaceSymbolHandler($fqnIndex),
            new XphpFileWatcherHandler($fqnIndex, $workspace, $cache),
            new XphpReferencesHandler(
                $workspace,
                new ReferenceFinder($workspace, $cache, $fqnIndex, $xphpParser, $reflector, $genericResolver),
            ),
            new XphpDocumentHighlightHandler(
                $workspace,
                new ReferenceFinder($workspace, $cache, $fqnIndex, $xphpParser, $reflector, $genericResolver),
            ),
            new XphpRenameHandler(
                $workspace,
                $renameProvider = new RenameProvider(
                    $workspace,
                    new ReferenceFinder($workspace, $cache, $fqnIndex, $xphpParser, $reflector, $genericResolver),
                    $fqnIndex,
                    self::clientSupportsRenameFileOp($initializeParams),
                ),
            ),
            // Cycle L Half B: workspace/willRenameFiles -- file-rename
            // -> class-rename text edits.  Pairs with the plugin's
            // AsyncFileListener which sends the request on .xphp/.php
            // file moves.  Shares the rename machinery with
            // textDocument/rename via the just-bound $renameProvider.
            new XphpWillRenameFilesHandler(
                $workspace,
                $cache,
                $xphpParser,
                $renameProvider,
                new \XPHP\Lsp\Resolver\NamespaceMoveProvider(
                    $workspace,
                    $cache,
                    $fqnIndex,
                    $xphpParser,
                ),
            ),
            new XphpSemanticTokensHandler($workspace, $cache),
            new XphpPullDiagnosticsHandler($workspace, $diagnosticsProvider),
            new XphpTypeHierarchyHandler($workspace, $cache, $xphpParser, $fqnIndex),
            new XphpImplementationHandler($workspace, $cache, $xphpParser, $fqnIndex),
        );

        $runner = new HandlerMethodRunner(
            $handlers,
            new ChainArgumentResolver(
                // LspObjectArgumentResolver runs BEFORE the framework's
                // `*Params$`-only resolver so handlers whose first
                // parameter is a non-Params LSP object (CompletionItem,
                // CodeAction) get a properly deserialised instance
                // instead of `array_values($params)` splatted scalars.
                // Backs textDocument/completionItem/resolve and
                // codeAction/resolve.
                new \XPHP\Lsp\Dispatcher\LspObjectArgumentResolver(),
                new LanguageSeverProtocolParamsResolver(),
                new PassThroughArgumentResolver(),
            ),
        );

        return new MiddlewareDispatcher(
            new ErrorHandlingMiddleware($this->logger),
            new InitializeMiddleware($handlers, $eventDispatcher, [
                'name' => 'xphp-lsp',
                'version' => '0.1.0',
            ]),
            new ShutdownMiddleware($eventDispatcher),
            new ResponseHandlingMiddleware($responseWatcher),
            new CancellationMiddleware($runner),
            new HandlerMiddleware($runner),
        );
    }

    /**
     * Per LSP spec: when the client advertises
     * `workspace.workspaceEdit.resourceOperations`, the server must
     * only emit ops in that list.  PhpStorm lists `["create"]` only
     * (no `rename`/`delete`), so any `RenameFile` we send is silently
     * dropped on the client side and the user sees a partial apply.
     *
     * **Cycle L attempt 1** added a plugin-side opt-in
     * (`initializationOptions.xphpAcceptsRenameFile`) so the server
     * could emit RenameFile ops regardless of the standard
     * advertisement.  Prod-test (xphp-20260530-161814 log id=50)
     * proved the opt-in self-defeating: PhpStorm advertises
     * `failureHandling: "abort"`, so when LSP4IJ's WorkspaceEdit
     * applier sees the unsupported RenameFile op, it aborts the
     * ENTIRE WorkspaceEdit including the text edits the user
     * actually wanted.  Reverted -- the flag is now read but no
     * longer fires the override; we honour the spec-standard
     * advertisement only.  The xphp-side flag stays on the wire so
     * the plugin can be told the server CAN emit the op (for a
     * future architecture where the plugin intercepts the rename
     * before LSP4IJ's abort-on-failure applier sees it).
     */
    private static function clientSupportsRenameFileOp(InitializeParams $initializeParams): bool
    {
        $ops = $initializeParams->capabilities?->workspace?->workspaceEdit?->resourceOperations ?? null;
        if (!is_array($ops)) {
            return false;
        }
        return in_array('rename', $ops, true);
    }
}
