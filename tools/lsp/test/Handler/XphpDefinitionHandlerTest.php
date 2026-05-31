<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\DefinitionParams;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\WorkspaceSymbols;
use XPHP\Lsp\Handler\XphpDefinitionHandler;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;
use function Amp\Promise\wait;

final class XphpDefinitionHandlerTest extends TestCase
{
    public function testJumpsFromInstantiationToTemplateInAnotherDocument(): void
    {
        $workspace = new PhpactorWorkspace();
        $boxSource = <<<'XPHP'
        <?php
        namespace App;
        class Box<T>
        {
            public T $item;
        }
        XPHP;
        $useSource = <<<'XPHP'
        <?php
        namespace App;
        $x = new Box<Plastic>();
        XPHP;
        $workspace->open(new TextDocumentItem('/Box.xphp', 'xphp', 1, $boxSource));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $handler = $this->newHandler($workspace);
        $location = $this->definitionAt($handler, '/Use.xphp', $useSource, 'Box<Plastic>');

        self::assertInstanceOf(Location::class, $location);
        self::assertSame('/Box.xphp', $location->uri);
        // The target range points at the `Box` identifier inside `class Box<T>`,
        // not the entire class body.
        [$expectedLine, $expectedChar] = (new PositionMap($boxSource))->offsetToPosition(strpos($boxSource, 'class Box') + strlen('class '));
        self::assertSame($expectedLine, $location->range->start->line);
        self::assertSame($expectedChar, $location->range->start->character);
    }

    public function testNonGenericNameReturnsNull(): void
    {
        $workspace = new PhpactorWorkspace();
        $source = <<<'XPHP'
        <?php
        namespace App;
        class Tag { public string $name; }
        $t = new Tag();
        XPHP;
        $workspace->open(new TextDocumentItem('/doc.xphp', 'xphp', 1, $source));

        $handler = $this->newHandler($workspace);
        // Cursor on `Tag` in `new Tag()` — but `Tag` here has no template
        // FQN attribute since it's not a generic instantiation. Returns null.
        $location = $this->definitionAt($handler, '/doc.xphp', $source, 'new Tag', offsetInSearch: strlen('new '));
        self::assertNull($location);
    }

    public function testTemplateNotInOpenWorkspaceReturnsNull(): void
    {
        // Only the Use.xphp is open; the Box template lives elsewhere (on disk).
        // Without a workspace index of unopened files (a follow-up), we return null.
        $workspace = new PhpactorWorkspace();
        $useSource = <<<'XPHP'
        <?php
        namespace App;
        $x = new Box<Plastic>();
        XPHP;
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $handler = $this->newHandler($workspace);
        $location = $this->definitionAt($handler, '/Use.xphp', $useSource, 'Box<Plastic>');

        self::assertNull($location);
    }

    public function testUnknownUriReturnsNull(): void
    {
        $handler = $this->newHandler(new PhpactorWorkspace());
        $params = new DefinitionParams(
            new TextDocumentIdentifier('/never-opened.xphp'),
            new Position(0, 0),
        );
        self::assertNull(wait($handler->definition($params)));
    }

    public function testMethodsMapRegistersDefinitionEndpoint(): void
    {
        $methods = $this->newHandler(new PhpactorWorkspace())->methods();
        self::assertArrayHasKey('textDocument/definition', $methods);
        self::assertSame('definition', $methods['textDocument/definition']);
    }

    public function testAdvertisesDefinitionProviderCapability(): void
    {
        $capabilities = new \Phpactor\LanguageServerProtocol\ServerCapabilities();
        $this->newHandler(new PhpactorWorkspace())->registerCapabiltiies($capabilities);
        self::assertTrue($capabilities->definitionProvider);
    }

    public function testTargetRangeCoversTheFullIdentifierLength(): void
    {
        // Locks `getEndFilePos() + 1` arithmetic. nikic's endFilePos is
        // inclusive of the last byte; LSP ranges are half-open, so the +1 is
        // required for the range to span the whole identifier. We assert the
        // character count of the returned range matches the identifier length.
        $workspace = new PhpactorWorkspace();
        $boxSource = <<<'XPHP'
        <?php
        namespace App;
        class Container<T> { public T $item; }
        XPHP;
        $useSource = "<?php\nnamespace App;\n\$x = new Container<Plastic>();";
        $workspace->open(new TextDocumentItem('/Container.xphp', 'xphp', 1, $boxSource));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $location = $this->definitionAt($this->newHandler($workspace), '/Use.xphp', $useSource, 'Container<Plastic>');

        self::assertNotNull($location);
        $charCount = $location->range->end->character - $location->range->start->character;
        // "Container" is 9 characters. With `+0` the range would span 8 chars.
        self::assertSame(9, $charCount, 'range must cover the whole "Container" identifier');
    }

    public function testWorkspaceScanContinuesPastFilesThatDoNotDefineTheTemplate(): void
    {
        // Locks the `continue` on line 104 of findDefinitionAcrossWorkspace.
        // With `break`, scanning would stop at the first file that lacks the
        // template instead of trying the rest. We insert an unrelated file
        // BEFORE the one with the definition (insertion order matters; the
        // workspace iterates in insertion order).
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Unrelated.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Helper {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Container.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Container<T> { public T $item; }
        XPHP));
        $useSource = "<?php\nnamespace App;\n\$x = new Container<Plastic>();";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $location = $this->definitionAt($this->newHandler($workspace), '/Use.xphp', $useSource, 'Container<Plastic>');

        self::assertNotNull($location);
        self::assertSame('/Container.xphp', $location->uri);
    }

    public function testJumpsFromTypeArgInsideGenericClauseToClassDeclaration(): void
    {
        // The xphp-specific case: Ctrl+click on `User` inside the `<>` of
        // `identity<User>(...)` should land on `class User`.  This relies on
        // the second code path in `definition()` -- the inner `User` doesn't
        // survive as a Name node in the AST (XphpSourceParser strips it into
        // a marker entry on the outer FuncCall), so the ATTR_TEMPLATE_FQN
        // lookup misses; the fall-through uses TypeArgPositionDetector +
        // WorkspaceSymbols to resolve.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class User { public function __construct(public string $name) {} }
        XPHP));
        $useSource = "<?php\nnamespace App;\n\$asUser = identity<User>(new User('bob'));";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        // Cursor points at the `User` INSIDE the angle brackets, not the
        // `User` in the `new User(...)` ctor.
        $genericClauseStart = strpos($useSource, 'identity<') + strlen('identity<');
        $location = $this->definitionAtOffset($this->newHandler($workspace), '/Use.xphp', $useSource, $genericClauseStart + 1);

        self::assertNotNull($location);
        self::assertSame('/User.xphp', $location->uri);
    }

    public function testTypeArgFallthroughReturnsNullWhenClassNotInWorkspace(): void
    {
        // No `class Unknown` declared anywhere in the workspace -> null.
        // Distinguishes "we tried Path 2 and didn't find anything" from a
        // wiring bug.
        $workspace = new PhpactorWorkspace();
        $useSource = "<?php\n\$x = identity<Unknown>(null);";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $offset = strpos($useSource, 'Unknown') + 1;
        $location = $this->definitionAtOffset($this->newHandler($workspace), '/Use.xphp', $useSource, $offset);

        self::assertNull($location);
    }

    public function testGtdOnGenericInstantiationJumpsToTemplateInUnopenedFile(): void
    {
        // Phase 2.3: template lives in an .xphp file on disk that the user
        // hasn't opened.  GTD must still resolve via the filesystem half of
        // FqnIndex; without it the jump returns null and PhpStorm shows
        // "Cannot find declaration to go to."
        $root = sys_get_temp_dir() . '/xphp-def-fs-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        try {
            file_put_contents($root . '/Box.xphp', <<<'XPHP'
            <?php
            namespace App\Containers;
            class Box<T>
            {
                public T $item;
            }
            XPHP);

            $workspace = new PhpactorWorkspace();
            $useSource = "<?php\nuse App\\Containers\\Box;\n\$b = new Box<int>();\n";
            $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

            // Cursor on `Box` of `new Box<int>()`.
            $byte = strpos($useSource, 'new Box') + strlen('new ');
            $location = $this->definitionAtOffset($this->newHandler($workspace, $root), '/Use.xphp', $useSource, $byte);

            self::assertInstanceOf(Location::class, $location);
            self::assertSame('file://' . $root . '/Box.xphp', $location->uri);
            // `class Box<T>` is on line 2 (0-indexed); `Box` starts at char 6
            // (after `class `).
            self::assertSame(2, $location->range->start->line);
            self::assertSame(6, $location->range->start->character);
            self::assertSame(2, $location->range->end->line);
            self::assertSame(6 + strlen('Box'), $location->range->end->character);
        } finally {
            $this->rmrfPath($root);
        }
    }

    public function testGtdOnTypeArgIdentifierJumpsToUnopenedFile(): void
    {
        // Phase 2.3: the short-name (Path 2) lookup also gets the
        // filesystem fallback.  `User` declaration lives on disk only.
        $root = sys_get_temp_dir() . '/xphp-def-fs-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        try {
            file_put_contents($root . '/User.xphp', <<<'XPHP'
            <?php
            namespace App\Models;
            class User {}
            XPHP);

            $workspace = new PhpactorWorkspace();
            // identity<User>(...) -- the `User` identifier is INSIDE the
            // generic clause, only reachable via TypeArgPositionDetector.
            $useSource = "<?php\nfunction identity<T>(T \$x): T { return \$x; }\n\$x = identity<User>(null);\n";
            $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

            // Cursor on `User`.
            $byte = strpos($useSource, '<User>') + 1;
            $location = $this->definitionAtOffset($this->newHandler($workspace, $root), '/Use.xphp', $useSource, $byte);

            self::assertInstanceOf(Location::class, $location);
            self::assertSame('file://' . $root . '/User.xphp', $location->uri);
            // `class User {}` is on line 2 (0-indexed); `User` is at char 6.
            self::assertSame(2, $location->range->start->line);
            self::assertSame(6, $location->range->start->character);
        } finally {
            $this->rmrfPath($root);
        }
    }

    public function testGtdPrefersOpenDocOverFilesystemOnCollision(): void
    {
        // Open-doc wins when the same FQN is declared in both places --
        // the editor's unsaved buffer beats the on-disk copy.  Matches
        // the cross-cutting precedence rule baked into FqnIndex.
        $root = sys_get_temp_dir() . '/xphp-def-fs-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        try {
            file_put_contents($root . '/Box.xphp', <<<'XPHP'
            <?php
            namespace App\Containers;
            class Box<T> {}
            XPHP);

            $workspace = new PhpactorWorkspace();
            $editedSource = "<?php\nnamespace App\\Containers;\n\nclass Box<T> {}\n";
            $workspace->open(new TextDocumentItem('/edit/Box.xphp', 'xphp', 1, $editedSource));

            $useSource = "<?php\nuse App\\Containers\\Box;\n\$b = new Box<int>();\n";
            $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

            $byte = strpos($useSource, 'new Box') + strlen('new ');
            $location = $this->definitionAtOffset($this->newHandler($workspace, $root), '/Use.xphp', $useSource, $byte);

            self::assertInstanceOf(Location::class, $location);
            // Must jump to the open-doc URI, not the file:// path.
            self::assertSame('/edit/Box.xphp', $location->uri);
        } finally {
            $this->rmrfPath($root);
        }
    }

    private function rmrfPath(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $p = $dir . '/' . $entry;
            if (is_dir($p)) {
                $this->rmrfPath($p);
            } else {
                unlink($p);
            }
        }
        rmdir($dir);
    }

    public function testGtdOnFunctionDeclarationReturnsCallSitesAsReferences(): void
    {
        // Ctrl+Click on the function's OWN declaration name -- standard
        // GTD is a no-op (cursor is already at the decl), so the handler
        // promotes the request to find-usages and returns the list of
        // call sites instead.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/funcs.xphp', 'xphp', 1, "<?php\nnamespace App;\nfunction greet(): void {}\n"));
        $workspace->open(new TextDocumentItem('/Use1.xphp', 'xphp', 1, "<?php\nuse function App\\greet;\ngreet();\n"));
        $workspace->open(new TextDocumentItem('/Use2.xphp', 'xphp', 1, "<?php\nApp\\greet();\n"));

        // Cursor on `greet` in the declaration.
        $source = $workspace->get('/funcs.xphp')->text;
        $result = $this->definitionAtNeedle($workspace, '/funcs.xphp', $source, 'function greet', strlen('function '));

        self::assertIsArray($result, 'GTD on a declaration must return an array of usage Locations');
        $uris = array_map(fn (Location $l): string => $l->uri, $result);
        self::assertContains('/Use1.xphp', $uris);
        self::assertContains('/Use2.xphp', $uris);
        self::assertNotContains('/funcs.xphp', $uris, 'the declaration itself must NOT appear in the references list');
    }

    public function testGtdOnClassDeclarationReturnsUsages(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass User {}\n"));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, "<?php\nuse App\\User;\n\$u = new User();\n"));

        $source = $workspace->get('/User.xphp')->text;
        $result = $this->definitionAtNeedle($workspace, '/User.xphp', $source, 'class User', strlen('class '));

        self::assertIsArray($result);
        $uris = array_map(fn (Location $l): string => $l->uri, $result);
        self::assertContains('/Use.xphp', $uris);
        self::assertNotContains('/User.xphp', $uris);
    }

    public function testGtdOnDeclarationWithNoUsagesFallsThroughToNormalGtd(): void
    {
        // No references exist anywhere -- the handler falls through to
        // normal GTD paths (which return null for a self-targeting decl).
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/lonely.xphp', 'xphp', 1, "<?php\nnamespace App;\nfunction lonely(): void {}\n"));

        $source = $workspace->get('/lonely.xphp')->text;
        $result = $this->definitionAtNeedle($workspace, '/lonely.xphp', $source, 'function lonely', strlen('function '));

        self::assertNull($result);
    }

    /**
     * @return Location|list<Location>|null
     */
    private function definitionAtNeedle(
        PhpactorWorkspace $workspace,
        string $uri,
        string $source,
        string $needle,
        int $offsetInNeedle,
    ): mixed {
        $byte = strpos($source, $needle);
        self::assertNotFalse($byte);
        $byte += $offsetInNeedle;
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        $params = new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position($line, $character),
        );
        return wait($this->newHandler($workspace)->definition($params));
    }

    private function definitionAtOffset(
        XphpDefinitionHandler $handler,
        string $uri,
        string $source,
        int $byte,
    ): ?Location {
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        $params = new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position($line, $character),
        );
        return wait($handler->definition($params));
    }

    private function definitionAt(
        XphpDefinitionHandler $handler,
        string $uri,
        string $source,
        string $search,
        int $offsetInSearch = 0,
    ): ?Location {
        $byte = strpos($source, $search);
        self::assertNotFalse($byte, "fixture search string '{$search}' must exist in source");
        $byte += $offsetInSearch;
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        $params = new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position($line, $character),
        );
        return wait($handler->definition($params));
    }

    private function newHandler(PhpactorWorkspace $workspace, ?string $rootPath = null): XphpDefinitionHandler
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $fqnIndex = new FqnIndex($workspace, $cache, $parser, $rootPath ?? '');
        $reflector = (new \XPHP\Lsp\Reflection\ReflectorFactory(
            $workspace,
            $cache,
            $parser,
            rootPath: $rootPath ?? '',
            stubPath: \XPHP\Lsp\Reflection\ReflectorFactory::defaultStubPath(),
            cacheDir: \XPHP\Lsp\Reflection\ReflectorFactory::defaultCacheDir(),
            fqnIndex: $fqnIndex,
        ))->build();
        $classLikeLookup = new \XPHP\Lsp\Resolver\CompositeClassLikeLookup(
            new \XPHP\Lsp\Resolver\WorkspaceClassLikeLookup($workspace, $cache),
            new \XPHP\Lsp\Resolver\FilesystemClassLikeLookup($fqnIndex),
        );
        $genericResolver = new \XPHP\Lsp\Resolver\GenericResolver($workspace, $cache, $classLikeLookup, $parser, $fqnIndex);
        $referenceFinder = new \XPHP\Lsp\Resolver\ReferenceFinder($workspace, $cache, $fqnIndex, $parser, $reflector, $genericResolver);
        return new XphpDefinitionHandler(
            $workspace,
            $cache,
            new WorkspaceSymbols($workspace, $cache),
            $fqnIndex,
            $referenceFinder,
        );
    }

    public function testReturnsResultWhenCancelTokenNotRequested(): void
    {
        // Pins the cancel-poll guard at line 80.
        // LogicalAndSingleSubExprNegation flipping `isRequested` would
        // short-circuit on a fresh token and break happy-path GTD.
        $workspace = new PhpactorWorkspace();
        $boxSource = "<?php\nnamespace App;\nclass Box<T> {}\n";
        $useSource = "<?php\nnamespace App;\n\$x = new Box<int>();\n";
        $workspace->open(new TextDocumentItem('/Box.xphp', 'xphp', 1, $boxSource));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $handler = $this->newHandler($workspace);
        $byte = strpos($useSource, 'Box<int>');
        self::assertNotFalse($byte);
        [$line, $character] = (new PositionMap($useSource))->offsetToPosition($byte);
        $params = new DefinitionParams(
            new TextDocumentIdentifier('/Use.xphp'),
            new Position($line, $character),
        );

        $cancel = new \Amp\CancellationTokenSource();
        // Deliberately NOT cancelled.

        $location = wait($handler->definition($params, $cancel->getToken()));
        self::assertInstanceOf(Location::class, $location);
        self::assertSame('/Box.xphp', $location->uri);
    }

    public function testReturnsNullWhenCancelTokenAlreadyRequested(): void
    {
        $workspace = new PhpactorWorkspace();
        $boxSource = "<?php\nnamespace App;\nclass Box<T> {}\n";
        $useSource = "<?php\nnamespace App;\n\$x = new Box<int>();\n";
        $workspace->open(new TextDocumentItem('/Box.xphp', 'xphp', 1, $boxSource));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $handler = $this->newHandler($workspace);
        $byte = strpos($useSource, 'Box<int>');
        self::assertNotFalse($byte);
        [$line, $character] = (new PositionMap($useSource))->offsetToPosition($byte);
        $params = new DefinitionParams(
            new TextDocumentIdentifier('/Use.xphp'),
            new Position($line, $character),
        );

        $cancel = new \Amp\CancellationTokenSource();
        $cancel->cancel();

        $location = wait($handler->definition($params, $cancel->getToken()));
        self::assertNull($location);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lastSegmentCases')]
    public function testLastSegmentExtractsFinalIdentifier(string $input, string $expected): void
    {
        // `lastSegment` is private; reach it via Reflection so the cases
        // below pin the exact `substr($identifier, $idx + 1)` index.
        // Without these, Infection escapes four mutants on line 227 --
        // IncrementInteger (`+ 2`), DecrementInteger (`+ 0`),
        // Plus->Minus (`- 1`), UnwrapSubstr (returns the whole string).
        // The leading-backslash case in particular only works with
        // `+ 1`; `+ 0` would yield `'\\Baz'`, `+ 2` would yield `'az'`.
        $reflection = new \ReflectionClass(XphpDefinitionHandler::class);
        $method = $reflection->getMethod('lastSegment');
        $method->setAccessible(true);

        self::assertSame($expected, $method->invoke(null, $input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function lastSegmentCases(): iterable
    {
        yield 'fully-qualified multi-segment' => ['App\\Containers\\Box', 'Box'];
        yield 'two-segment' => ['App\\Foo', 'Foo'];
        yield 'leading backslash, single segment' => ['\\Stringable', 'Stringable'];
        yield 'no namespace separator' => ['Box', 'Box'];
        yield 'empty string' => ['', ''];
    }
}
