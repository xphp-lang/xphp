<?php

declare(strict_types=1);

namespace XPHP\Lsp;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Adapter\Psr\AggregateEventDispatcher;
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
use Phpactor\LanguageServer\Handler\TextDocument\TextDocumentHandler;
use Phpactor\LanguageServer\Handler\Workspace\CommandHandler;
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
use XPHP\Lsp\Handler\XphpHoverHandler;
use XPHP\Lsp\Reflection\ReflectorFactory;
use XPHP\Lsp\Resolver\PhpCompletionResolver;
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
        $reflector = (new ReflectorFactory(
            $workspace,
            $cache,
            $xphpParser,
            $rootPath,
            ReflectorFactory::defaultStubPath(),
            ReflectorFactory::defaultCacheDir(),
        ))->build();
        $phpDefinitionResolver = new PhpDefinitionResolver($workspace, $xphpParser, $reflector, $cache);
        $phpHoverResolver = new PhpHoverResolver($workspace, $xphpParser, $reflector);
        $phpCompletionResolver = new PhpCompletionResolver($workspace, $xphpParser, $reflector);

        $diagnosticsProvider = new XphpDiagnosticsProvider(
            $cache,
            new WorkspaceAnalyzer(),
            $workspace,
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
        $eventDispatcher = new AggregateEventDispatcher(
            new ServiceListener($serviceManager),
            new WorkspaceListener($workspace),
            $diagnosticsService,
        );

        // Single WorkspaceSymbols shared across the two handlers that need it
        // (completion + definition).  Both call the same in-memory AST cache,
        // so reusing the helper avoids constructing parallel collectors.
        $workspaceSymbols = new WorkspaceSymbols($workspace, $cache);

        $handlers = new Handlers(
            new TextDocumentHandler($eventDispatcher),
            new ServiceHandler($serviceManager, $clientApi),
            new CommandHandler(new CommandDispatcher([])),
            new ExitHandler(),
            new XphpHoverHandler($workspace, $cache, $phpHoverResolver),
            new XphpDefinitionHandler($workspace, $cache, $workspaceSymbols, $phpDefinitionResolver),
            new XphpCompletionHandler($workspace, $workspaceSymbols, $phpCompletionResolver),
        );

        $runner = new HandlerMethodRunner(
            $handlers,
            new ChainArgumentResolver(
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
}
