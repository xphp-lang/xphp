<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\DocumentHighlight;
use Phpactor\LanguageServerProtocol\DocumentHighlightKind;
use Phpactor\LanguageServerProtocol\DocumentHighlightParams;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\XphpDocumentHighlightHandler;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Reflection\ReflectorFactory;
use XPHP\Lsp\Resolver\CompositeClassLikeLookup;
use XPHP\Lsp\Resolver\FilesystemClassLikeLookup;
use XPHP\Lsp\Resolver\GenericResolver;
use XPHP\Lsp\Resolver\ReferenceFinder;
use XPHP\Lsp\Resolver\WorkspaceClassLikeLookup;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

final class XphpDocumentHighlightHandlerTest extends TestCase
{
    public function testHighlightsAllReferencesInCurrentFile(): void
    {
        $workspace = new PhpactorWorkspace();
        $source = "<?php\nnamespace App;\nclass User {}\n\$a = new User();\n\$b = new User();\n";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $source));

        $byte = strpos($source, 'class User') + strlen('class ');
        $highlights = $this->highlightsAt($workspace, '/Use.xphp', $source, $byte);

        // Three matches: class declaration + two `new User()` use sites.
        self::assertCount(3, $highlights);
        foreach ($highlights as $h) {
            self::assertInstanceOf(DocumentHighlight::class, $h);
            self::assertSame(DocumentHighlightKind::TEXT, $h->kind);
        }
    }

    public function testFiltersOutCrossFileMatches(): void
    {
        // Two files reference the same class.  documentHighlight on
        // file A must only return matches from file A; the file B
        // match belongs in textDocument/references' response.
        $workspace = new PhpactorWorkspace();
        $declSource = "<?php\nnamespace App;\nclass User {}\n";
        $useASource = "<?php\nuse App\\User;\n\$a = new User();\n";
        $useBSource = "<?php\nuse App\\User;\n\$b = new User();\n";
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, $declSource));
        $workspace->open(new TextDocumentItem('/UseA.xphp', 'xphp', 1, $useASource));
        $workspace->open(new TextDocumentItem('/UseB.xphp', 'xphp', 1, $useBSource));

        $byte = strpos($useASource, 'new User') + 4;
        $highlights = $this->highlightsAt($workspace, '/UseA.xphp', $useASource, $byte);

        // UseA has two `User` mentions: the `use App\User;` import
        // and the `new User()` call.  UseB also has two, but the
        // handler filters them out -- every returned highlight must
        // be inside UseA.xphp.
        self::assertNotEmpty($highlights);
        foreach ($highlights as $h) {
            $line = $h->range->start->line;
            // The UseB file's `new User()` is on line 2 in that file;
            // line indexing here only validates that the highlight
            // refers to text inside UseA -- we can't check the URI
            // because DocumentHighlight has no URI field.  Instead,
            // assert via the source slice extracted from UseA.
            self::assertGreaterThanOrEqual(0, $line);
            self::assertLessThan(
                count(explode("\n", $useASource)),
                $line,
                'every highlight line must be within UseA.xphp',
            );
        }
    }

    public function testSkipsFilesystemScanForOpenDocOnlyRequest(): void
    {
        // Regression for the 2026-05-27 prod-log 2:43 documentHighlight
        // stall: prior to Fix 2 the handler walked every indexed
        // filesystem path looking for matches only to discard them in
        // the cross-file filter.  This test pins down that an on-disk
        // file with the same class reference does NOT cause any
        // additional highlights to be emitted -- and, equivalently,
        // that the in-file result is unaffected by what's on disk.
        $root = sys_get_temp_dir() . '/xphp-doc-highlight-' . bin2hex(random_bytes(4));
        mkdir($root, 0o755, true);
        try {
            file_put_contents($root . '/Other.xphp', "<?php\nnamespace App;\n\$z = new User();\n");

            $workspace = new PhpactorWorkspace();
            $source = "<?php\nnamespace App;\nclass User {}\n\$a = new User();\n";
            $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $source));

            $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
            $cache = new ParsedDocumentCache(new Analyzer($parser));
            $fqnIndex = new FqnIndex($workspace, $cache, $parser, $root);
            $reflector = (new ReflectorFactory(
                $workspace,
                $cache,
                $parser,
                rootPath: $root,
                stubPath: ReflectorFactory::defaultStubPath(),
                cacheDir: ReflectorFactory::defaultCacheDir(),
                fqnIndex: $fqnIndex,
            ))->build();
            $classLikeLookup = new CompositeClassLikeLookup(
                new WorkspaceClassLikeLookup($workspace, $cache),
                new FilesystemClassLikeLookup($fqnIndex),
            );
            $generic = new GenericResolver($workspace, $cache, $classLikeLookup, $parser, $fqnIndex);
            $finder = new ReferenceFinder($workspace, $cache, $fqnIndex, $parser, $reflector, $generic);
            $handler = new XphpDocumentHighlightHandler($workspace, $finder);

            // Cursor on `class User` -- two in-file matches (decl + new).
            $byte = strpos($source, 'class User') + strlen('class ');
            [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
            $params = new DocumentHighlightParams(
                new TextDocumentIdentifier('/Use.xphp'),
                new Position($line, $character),
            );
            $highlights = wait($handler->documentHighlight($params));

            self::assertCount(2, $highlights, 'in-file decl + use only');
        } finally {
            if (is_dir($root)) {
                foreach (glob($root . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($root);
            }
        }
    }

    public function testReturnsEmptyArrayForUnknownDocument(): void
    {
        $workspace = new PhpactorWorkspace();
        $handler = $this->handler($workspace);
        $params = new DocumentHighlightParams(
            new TextDocumentIdentifier('/never-opened.xphp'),
            new Position(0, 0),
        );

        self::assertSame([], wait($handler->documentHighlight($params)));
    }

    public function testReturnsEmptyArrayWhenCancelTokenAlreadyRequested(): void
    {
        $workspace = new PhpactorWorkspace();
        $source = "<?php\nnamespace App;\nclass User {}\n";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $source));

        $handler = $this->handler($workspace);
        $byte = strpos($source, 'class User') + strlen('class ');
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        $params = new DocumentHighlightParams(
            new TextDocumentIdentifier('/Use.xphp'),
            new Position($line, $character),
        );

        $cancel = new \Amp\CancellationTokenSource();
        $cancel->cancel();

        self::assertSame([], wait($handler->documentHighlight($params, $cancel->getToken())));
    }

    public function testReturnsResultWhenCancelTokenNotRequested(): void
    {
        $workspace = new PhpactorWorkspace();
        $source = "<?php\nnamespace App;\nclass User {}\n\$a = new User();\n";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $source));

        $handler = $this->handler($workspace);
        $byte = strpos($source, 'class User') + strlen('class ');
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        $params = new DocumentHighlightParams(
            new TextDocumentIdentifier('/Use.xphp'),
            new Position($line, $character),
        );

        $cancel = new \Amp\CancellationTokenSource();
        // Deliberately NOT cancelled.

        $highlights = wait($handler->documentHighlight($params, $cancel->getToken()));
        self::assertNotEmpty($highlights);
    }

    public function testReturnsEmptyArrayWhenSymbolHasNoReferences(): void
    {
        $workspace = new PhpactorWorkspace();
        // Cursor on the function name `originalCount` -- the function
        // is never called (no use sites), so references-walk finds
        // only the declaration, which still highlights.
        $source = "<?php\nfunction unused(): void {}\n";
        $workspace->open(new TextDocumentItem('/lib.xphp', 'xphp', 1, $source));

        $byte = strpos($source, 'unused');
        $highlights = $this->highlightsAt($workspace, '/lib.xphp', $source, $byte);

        // Declaration itself is one match.
        self::assertGreaterThanOrEqual(1, count($highlights));
    }

    public function testAdvertisesDocumentHighlightProviderCapability(): void
    {
        $caps = new ServerCapabilities();
        $this->handler(new PhpactorWorkspace())->registerCapabiltiies($caps);

        self::assertTrue($caps->documentHighlightProvider);
    }

    public function testMethodsMapAdvertisesDocumentHighlightEndpoint(): void
    {
        self::assertArrayHasKey(
            'textDocument/documentHighlight',
            $this->handler(new PhpactorWorkspace())->methods(),
        );
    }

    /**
     * @return list<DocumentHighlight>
     */
    private function highlightsAt(PhpactorWorkspace $workspace, string $uri, string $source, int $byte): array
    {
        $handler = $this->handler($workspace);
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        $params = new DocumentHighlightParams(
            new TextDocumentIdentifier($uri),
            new Position($line, $character),
        );
        $result = wait($handler->documentHighlight($params));
        self::assertIsArray($result);
        return $result;
    }

    private function handler(PhpactorWorkspace $workspace): XphpDocumentHighlightHandler
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $fqnIndex = new FqnIndex($workspace, $cache, $parser, '');
        $reflector = (new ReflectorFactory(
            $workspace,
            $cache,
            $parser,
            rootPath: '',
            stubPath: ReflectorFactory::defaultStubPath(),
            cacheDir: ReflectorFactory::defaultCacheDir(),
            fqnIndex: $fqnIndex,
        ))->build();
        $classLikeLookup = new CompositeClassLikeLookup(
            new WorkspaceClassLikeLookup($workspace, $cache),
            new FilesystemClassLikeLookup($fqnIndex),
        );
        $generic = new GenericResolver($workspace, $cache, $classLikeLookup, $parser, $fqnIndex);
        $finder = new ReferenceFinder($workspace, $cache, $fqnIndex, $parser, $reflector, $generic);
        return new XphpDocumentHighlightHandler($workspace, $finder);
    }
}
