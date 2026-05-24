<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Resolver;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Hover;
use Phpactor\LanguageServerProtocol\MarkupContent;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\ReflectorFactory;
use XPHP\Lsp\Resolver\PhpHoverResolver;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class PhpHoverResolverTest extends TestCase
{
    public function testHoversClassWithSignature(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App;\n/**\n * A user.\n */\nclass User {}\n");
        $useSource = "<?php\nuse App\\User;\n\$u = new User();\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'new User', 4);

        $markdown = $this->markdown($hover);
        self::assertStringContainsString('class App\\User', $markdown);
        self::assertStringContainsString('A user.', $markdown);
    }

    public function testHoversUserFunctionWithSignature(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/fn.xphp', "<?php\nnamespace App;\n/**\n * Greet someone.\n */\nfunction greet(string \$n): string { return \$n; }\n");
        $useSource = "<?php\nuse function App\\greet;\necho greet('a');\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'echo greet', strlen('echo '));

        $markdown = $this->markdown($hover);
        self::assertStringContainsString('function App\\greet', $markdown);
        self::assertStringContainsString('Greet someone', $markdown);
    }

    public function testHoversMethodWithReceiverContext(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', <<<'XPHP'
        <?php
        namespace App;
        class User {
            public function __construct(public string $name) {}
            /** Shout the name. */
            public function shout(): string { return strtoupper($this->name); }
        }
        XPHP);
        $useSource = "<?php\nuse App\\User;\n\$u = new User('a');\necho \$u->shout();\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, '->shout', 2);

        $markdown = $this->markdown($hover);
        self::assertStringContainsString('function shout', $markdown);
        // The class FQN appears as context above the signature.
        self::assertStringContainsString('App\\User', $markdown);
    }

    public function testHoversPropertyWithReceiverContext(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', <<<'XPHP'
        <?php
        namespace App;
        class User {
            /** The displayed name. */
            public string $name = '';
        }
        XPHP);
        $useSource = "<?php\nuse App\\User;\n\$u = new User();\necho \$u->name;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, '->name', 2);

        $markdown = $this->markdown($hover);
        self::assertStringContainsString('$name', $markdown);
    }

    public function testHoversNativeFunctionFromStubs(): void
    {
        if (!is_dir(ReflectorFactory::defaultStubPath())) {
            self::markTestSkipped('jetbrains/phpstorm-stubs not installed');
        }
        $workspace = $this->workspace();
        $useSource = "<?php\n\$x = strlen('hello');\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'strlen', 1);

        $markdown = $this->markdown($hover);
        self::assertStringContainsString('function strlen', $markdown);
    }

    public function testReturnsNullOnVariableCursor(): void
    {
        $workspace = $this->workspace();
        $useSource = "<?php\n\$x = 1;\necho \$x;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'echo $x', strlen('echo '));
        self::assertNull($hover);
    }

    public function testReturnsNullForUnknownDocument(): void
    {
        $resolver = $this->resolver($this->workspace());
        self::assertNull($resolver->resolve('/never-opened.xphp', 0, 0));
    }

    private function hoverAt(
        PhpactorWorkspace $workspace,
        string $uri,
        string $source,
        string $needle,
        int $offsetInNeedle,
    ): ?Hover {
        $byte = strpos($source, $needle);
        self::assertNotFalse($byte, "fixture needle '$needle' must exist");
        $byte += $offsetInNeedle;
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        return $this->resolver($workspace)->resolve($uri, $line, $character);
    }

    private function markdown(?Hover $hover): string
    {
        self::assertNotNull($hover);
        $content = $hover->contents;
        self::assertInstanceOf(MarkupContent::class, $content);
        return $content->value;
    }

    private function resolver(PhpactorWorkspace $workspace): PhpHoverResolver
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $reflector = (new ReflectorFactory(
            $workspace,
            $cache,
            $parser,
            rootPath: '',
            stubPath: ReflectorFactory::defaultStubPath(),
            cacheDir: ReflectorFactory::defaultCacheDir(),
        ))->build();
        return new PhpHoverResolver($workspace, $parser, $reflector);
    }

    private function workspace(): PhpactorWorkspace
    {
        return new PhpactorWorkspace();
    }

    private function open(PhpactorWorkspace $workspace, string $uri, string $source): void
    {
        $workspace->open(new TextDocumentItem($uri, 'xphp', 1, $source));
    }
}
