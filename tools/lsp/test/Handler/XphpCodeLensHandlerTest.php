<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\CodeLensOptions;
use Phpactor\LanguageServerProtocol\CodeLensParams;
use Phpactor\LanguageServerProtocol\Location;
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

    public function testEmitsLensForClassDeclaration(): void
    {
        $source = "<?php\nnamespace App;\nclass Foo {}\n";
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Foo.xphp', 'xphp', 1, $source));
        $handler = $this->newHandler($workspace);

        $lenses = wait($handler->codeLens(new CodeLensParams(new TextDocumentIdentifier('/Foo.xphp'))));

        self::assertCount(1, $lenses);
        self::assertSame(2, $lenses[0]->range->start->line);
        // Title carries the usage count rather than a static string so
        // PhpStorm renders e.g. "0 usages" / "3 usages" inline.
        self::assertStringContainsString('usage', $lenses[0]->command?->title);
        // Client-side LSP convention -- VS Code, LSP4IJ, and Helix all
        // dispatch this command name natively to open the references
        // panel without round-tripping to the server.
        self::assertSame('editor.action.showReferences', $lenses[0]->command?->command);
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

    public function testAdvertisesCodeLensProvider(): void
    {
        $handler = $this->newHandler(new PhpactorWorkspace());
        $caps = new ServerCapabilities();
        $handler->registerCapabiltiies($caps);

        self::assertInstanceOf(CodeLensOptions::class, $caps->codeLensProvider);
        self::assertFalse($caps->codeLensProvider->resolveProvider);
    }

    public function testMethodsMapAdvertisesEndpoint(): void
    {
        self::assertArrayHasKey('textDocument/codeLens', $this->newHandler(new PhpactorWorkspace())->methods());
    }

    public function testCommandArgumentsCarryUriPositionAndLocations(): void
    {
        $source = "<?php\nnamespace App;\nclass Foo {}\n";
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Foo.xphp', 'xphp', 1, $source));
        $handler = $this->newHandler($workspace);

        $lenses = wait($handler->codeLens(new CodeLensParams(new TextDocumentIdentifier('/Foo.xphp'))));

        $args = $lenses[0]->command?->arguments;
        self::assertIsArray($args);
        // [uri, position, locations] -- the `editor.action.showReferences`
        // shape every mainline LSP client recognizes.
        self::assertSame('/Foo.xphp', $args[0]);
        self::assertSame(2, $args[1]['line']);
        self::assertIsArray($args[2]);
    }

    public function testLocationsBakedInForMethodCalledAcrossWorkspace(): void
    {
        // The lens for `App\Foo::bar` must pre-compute the location
        // of `$foo->bar()` in the caller file so clicking it opens
        // Find Usages with that location pre-loaded -- no
        // executeCommand round-trip.
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

        $lenses = wait($handler->codeLens(new CodeLensParams(new TextDocumentIdentifier('/Foo.xphp'))));

        // 1 class lens + 1 method lens = 2.  Locate the method lens
        // (it's on a line below the class lens) and assert the call
        // site is in its baked-in locations.
        self::assertCount(2, $lenses);
        $methodLens = $lenses[1];  // class first, method second
        $args = $methodLens->command?->arguments;
        self::assertIsArray($args);
        self::assertIsArray($args[2]);
        $uris = array_map(static fn (Location $l): string => $l->uri, $args[2]);
        self::assertContains('/use.xphp', $uris, 'cross-file call site must surface in the baked locations');
        // Title must reflect the usage count.
        self::assertSame('1 usage', $methodLens->command?->title);
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
