<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\CodeLens;
use Phpactor\LanguageServerProtocol\CodeLensOptions;
use Phpactor\LanguageServerProtocol\CodeLensParams;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\XphpCodeLensHandler;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Reflection\ReflectorFactory;
use XPHP\Lsp\Resolver\CompositeClassLikeLookup;
use XPHP\Lsp\Resolver\FilesystemClassLikeLookup;
use XPHP\Lsp\Resolver\GenericResolver;
use XPHP\Lsp\Resolver\ReferenceFinder;
use XPHP\Lsp\Resolver\WorkspaceClassLikeLookup;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

final class XphpCodeLensHandlerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xphp-codelens-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->rmrf($this->root);
        }
    }

    public function testEmitsUnresolvedLensForClassDeclaration(): void
    {
        $source = "<?php\nnamespace App;\nclass Foo {}\n";
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Foo.xphp', 'xphp', 1, $source));
        $handler = $this->newHandler($workspace);

        $lenses = wait($handler->codeLens(new CodeLensParams(new TextDocumentIdentifier('/Foo.xphp'))));

        self::assertCount(1, $lenses);
        self::assertSame(2, $lenses[0]->range->start->line);
        // Initial emission carries a placeholder title and a
        // 2-element `arguments` slot (uri, position) with NO
        // locations.  The client-side plugin handler fetches
        // locations on demand via textDocument/references when it
        // sees args.size < 3.  Spec-compliant clients (VS Code)
        // can still call codeLens/resolve to get the count + baked
        // locations up front.
        self::assertSame('Show references', $lenses[0]->command?->title);
        self::assertSame('editor.action.showReferences', $lenses[0]->command?->command);
        self::assertCount(2, $lenses[0]->command?->arguments);
        self::assertSame('/Foo.xphp', $lenses[0]->command?->arguments[0]);
        self::assertSame(['line' => 2, 'character' => 6], $lenses[0]->command?->arguments[1]);
        // `data` carries the position so resolve() can re-run
        // findReferences without server-side state held between calls.
        self::assertIsArray($lenses[0]->data);
        self::assertSame('/Foo.xphp', $lenses[0]->data['uri']);
        self::assertSame(2, $lenses[0]->data['line']);
    }

    public function testEmitsLensForEachMethodInsideAClass(): void
    {
        $source = <<<'PHP'
        <?php
        namespace App;
        class Foo {
            public function bar(): void {}
            public function baz(): void {}
        }
        PHP;
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Foo.xphp', 'xphp', 1, $source));
        $handler = $this->newHandler($workspace);

        $lenses = wait($handler->codeLens(new CodeLensParams(new TextDocumentIdentifier('/Foo.xphp'))));

        // 1 class + 2 methods = 3 lenses.
        self::assertCount(3, $lenses);
    }

    public function testEmitsLensForFreeFunction(): void
    {
        $source = "<?php\nfunction greet(): string { return 'hi'; }\n";
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/greet.xphp', 'xphp', 1, $source));
        $handler = $this->newHandler($workspace);

        $lenses = wait($handler->codeLens(new CodeLensParams(new TextDocumentIdentifier('/greet.xphp'))));

        self::assertCount(1, $lenses);
        self::assertSame(1, $lenses[0]->range->start->line);
    }

    public function testEmptyResponseForUnknownDocument(): void
    {
        $handler = $this->newHandler(new PhpactorWorkspace());

        $lenses = wait($handler->codeLens(new CodeLensParams(new TextDocumentIdentifier('/never-opened.xphp'))));

        self::assertSame([], $lenses);
    }

    public function testEmptyResponseWhenCancelled(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/x.xphp', 'xphp', 1, "<?php\nclass Foo {}"));
        $handler = $this->newHandler($workspace);
        $cancel = new \Amp\CancellationTokenSource();
        $cancel->cancel();

        $lenses = wait($handler->codeLens(
            new CodeLensParams(new TextDocumentIdentifier('/x.xphp')),
            $cancel->getToken(),
        ));

        self::assertSame([], $lenses);
    }

    public function testAdvertisesCodeLensProviderWithResolve(): void
    {
        $handler = $this->newHandler(new PhpactorWorkspace());
        $caps = new ServerCapabilities();
        $handler->registerCapabiltiies($caps);

        self::assertInstanceOf(CodeLensOptions::class, $caps->codeLensProvider);
        // resolveProvider=true signals to the client that the initial
        // textDocument/codeLens response carries unresolved lenses
        // and the client should call codeLens/resolve to fill them in.
        self::assertTrue($caps->codeLensProvider->resolveProvider);
    }

    public function testMethodsMapAdvertisesBothEndpoints(): void
    {
        $methods = $this->newHandler(new PhpactorWorkspace())->methods();
        self::assertArrayHasKey('textDocument/codeLens', $methods);
        self::assertArrayHasKey('codeLens/resolve', $methods);
    }

    public function testResolveFillsInUsageCountAndLocations(): void
    {
        // The resolve handler runs ReferenceFinder against the
        // position the lens emission stored in `data`, and returns
        // the lens with `command: {title: "N usage(s)", command:
        // editor.action.showReferences, arguments: [uri, position,
        // locations]}` populated.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Foo.xphp', 'xphp', 1, <<<'PHP'
        <?php
        namespace App;
        class Foo {
            public function bar(): void {}
        }
        PHP));
        $workspace->open(new TextDocumentItem('/use.xphp', 'xphp', 1, <<<'PHP'
        <?php
        use App\Foo;
        $f = new Foo();
        $f->bar();
        PHP));
        $handler = $this->newHandler($workspace);

        // Construct the unresolved lens as the codeLens emission
        // would (line 3 = the `bar` method declaration).
        $unresolved = new CodeLens(
            new Range(new Position(3, 20), new Position(3, 23)),
        );
        $unresolved->data = ['uri' => '/Foo.xphp', 'line' => 3, 'character' => 20];

        $resolved = wait($handler->resolve($unresolved));

        self::assertSame('editor.action.showReferences', $resolved->command?->command);
        self::assertSame('1 usage', $resolved->command?->title);
        $args = $resolved->command?->arguments;
        self::assertIsArray($args);
        self::assertSame('/Foo.xphp', $args[0]);
        self::assertSame(['line' => 3, 'character' => 20], $args[1]);
        self::assertIsArray($args[2]);
        $uris = array_map(static fn (Location $l): string => $l->uri, $args[2]);
        self::assertContains('/use.xphp', $uris);
    }

    public function testResolveReturnsLensUnchangedForMissingData(): void
    {
        // Defensive guard: a lens without `data` (e.g. fabricated by
        // a misbehaving client, or replayed from disk after format
        // changes) must NOT crash the resolve path.  Return the lens
        // as-is so the client renders the placeholder.
        $handler = $this->newHandler(new PhpactorWorkspace());
        $lens = new CodeLens(new Range(new Position(0, 0), new Position(0, 0)));
        // No `data` set.
        $resolved = wait($handler->resolve($lens));
        self::assertSame($lens, $resolved);
    }

    public function testResolveReturnsLensUnchangedForUnknownUri(): void
    {
        // If the document the lens points at is no longer open
        // (closed between codeLens emission and resolve), short-
        // circuit -- workspace lookup would fail and findReferences
        // can't run.
        $handler = $this->newHandler(new PhpactorWorkspace());
        $lens = new CodeLens(new Range(new Position(0, 0), new Position(0, 0)));
        $lens->data = ['uri' => '/never-opened.xphp', 'line' => 0, 'character' => 0];
        $resolved = wait($handler->resolve($lens));
        // Command stays as it was (null in this fixture).
        self::assertNull($resolved->command);
    }

    public function testResolveShortCircuitsOnPreCancelledToken(): void
    {
        // Cancel-poll guard at the top of resolve(): a pre-cancelled
        // token must return the lens unchanged (no findReferences
        // call).  Same pattern every handler in this codebase
        // follows; locks the `$cancel !== null && $cancel->isRequested()`
        // check against mutator removal.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Foo.xphp', 'xphp', 1, "<?php\nclass Foo {}\n"));
        $handler = $this->newHandler($workspace);
        $cancel = new \Amp\CancellationTokenSource();
        $cancel->cancel();

        $lens = new CodeLens(new Range(new Position(1, 6), new Position(1, 9)));
        $lens->data = ['uri' => '/Foo.xphp', 'line' => 1, 'character' => 6];
        $resolved = wait($handler->resolve($lens, $cancel->getToken()));
        // Pre-cancelled returns the lens with its command untouched
        // -- never enters the findReferences path.
        self::assertNull($resolved->command);
    }

    public function testResolvePluralisesUsageCountCorrectly(): void
    {
        // Title rendering: "1 usage" singular, "2 usages" plural.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Foo.xphp', 'xphp', 1, <<<'PHP'
        <?php
        namespace App;
        class Foo {
            public function bar(): void {}
        }
        PHP));
        $workspace->open(new TextDocumentItem('/use.xphp', 'xphp', 1, <<<'PHP'
        <?php
        use App\Foo;
        $f = new Foo();
        $f->bar();
        $f->bar();
        PHP));
        $handler = $this->newHandler($workspace);
        $unresolved = new CodeLens(new Range(new Position(3, 20), new Position(3, 23)));
        $unresolved->data = ['uri' => '/Foo.xphp', 'line' => 3, 'character' => 20];
        $resolved = wait($handler->resolve($unresolved));
        self::assertSame('2 usages', $resolved->command?->title);
    }

    private function newHandler(PhpactorWorkspace $workspace): XphpCodeLensHandler
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $fqnIndex = new FqnIndex($workspace, $cache, $parser, $this->root);
        $reflector = (new ReflectorFactory(
            $workspace,
            $cache,
            $parser,
            rootPath: $this->root,
            stubPath: ReflectorFactory::defaultStubPath(),
            cacheDir: ReflectorFactory::defaultCacheDir(),
            fqnIndex: $fqnIndex,
        ))->build();
        $classLikeLookup = new CompositeClassLikeLookup(
            new WorkspaceClassLikeLookup($workspace, $cache),
            new FilesystemClassLikeLookup($fqnIndex),
        );
        $genericResolver = new GenericResolver($workspace, $cache, $classLikeLookup, $parser, $fqnIndex);
        $finder = new ReferenceFinder($workspace, $cache, $fqnIndex, $parser, $reflector, $genericResolver);
        return new XphpCodeLensHandler($workspace, $cache, $finder);
    }

    private function rmrf(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $p = $dir . '/' . $entry;
            if (is_dir($p)) {
                $this->rmrf($p);
            } else {
                unlink($p);
            }
        }
        rmdir($dir);
    }
}
