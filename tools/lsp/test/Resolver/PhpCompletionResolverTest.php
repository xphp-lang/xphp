<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Resolver;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\CompletionItem;
use Phpactor\LanguageServerProtocol\CompletionItemKind;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\ReflectorFactory;
use XPHP\Lsp\Resolver\PhpCompletionResolver;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class PhpCompletionResolverTest extends TestCase
{
    public function testCompletesPublicMethodsAfterArrow(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', <<<'XPHP'
        <?php
        namespace App;
        class User {
            public function __construct(public string $name) {}
            public function shout(): string { return ''; }
            public function whisper(): string { return ''; }
            private function _secret(): void {}
        }
        XPHP);
        $useSource = "<?php\nuse App\\User;\n\$u = new User('a');\n\$u->\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, '$u->', 4);

        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);
        self::assertContains('shout', $labels);
        self::assertContains('whisper', $labels);
        self::assertNotContains('_secret', $labels, 'private methods must be filtered out');
    }

    public function testCompletesPublicPropertiesAfterArrow(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', <<<'XPHP'
        <?php
        namespace App;
        class User {
            public string $name = '';
            private int $age = 0;
        }
        XPHP);
        $useSource = "<?php\nuse App\\User;\n\$u = new User();\n\$u->\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, '$u->', 4);
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('name', $labels);
        self::assertNotContains('age', $labels);
    }

    public function testFiltersMembersByPrefix(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', <<<'XPHP'
        <?php
        namespace App;
        class User {
            public function shout(): string { return ''; }
            public function whisper(): string { return ''; }
            public function murmur(): string { return ''; }
        }
        XPHP);
        $useSource = "<?php\nuse App\\User;\n\$u = new User();\n\$u->sh";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, '$u->sh', strlen('$u->sh'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('shout', $labels);
        self::assertNotContains('whisper', $labels);
        self::assertNotContains('murmur', $labels);
    }

    public function testCompletesStaticMethodsAfterDoubleColon(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/Util.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Util {
            public static function shout(string $s): string { return ''; }
            public function bark(): string { return ''; }
        }
        XPHP);
        $useSource = "<?php\nuse App\\Util;\nUtil::";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, 'Util::', strlen('Util::'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('shout', $labels, 'static methods must be included');
        self::assertNotContains('bark', $labels, 'instance methods must be excluded on static access');
    }

    public function testCompletesClassConstantsAfterDoubleColon(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/Cfg.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Cfg {
            public const MAX = 10;
            public const MIN = 0;
        }
        XPHP);
        $useSource = "<?php\nuse App\\Cfg;\nCfg::";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, 'Cfg::', strlen('Cfg::'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('MAX', $labels);
        self::assertContains('MIN', $labels);
    }

    public function testReturnsEmptyForNonMemberContext(): void
    {
        $workspace = $this->workspace();
        $useSource = "<?php\n\$x = 1;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, '$x = ', strlen('$x = '));
        self::assertSame([], $items);
    }

    public function testReturnsEmptyForUnknownReceiverType(): void
    {
        $workspace = $this->workspace();
        $useSource = "<?php\n\$mystery->";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, '$mystery->', strlen('$mystery->'));
        self::assertSame([], $items);
    }

    public function testReturnsEmptyForUnknownDocument(): void
    {
        $resolver = $this->resolver($this->workspace());
        self::assertSame([], $resolver->complete('/never-opened.xphp', 0, 0));
    }

    private function completeAt(
        PhpactorWorkspace $workspace,
        string $uri,
        string $source,
        string $needle,
        int $offsetInNeedle,
    ): array {
        $byte = strpos($source, $needle);
        self::assertNotFalse($byte, "fixture needle '$needle' must exist");
        $byte += $offsetInNeedle;
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        return $this->resolver($workspace)->complete($uri, $line, $character);
    }

    private function resolver(PhpactorWorkspace $workspace): PhpCompletionResolver
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
        return new PhpCompletionResolver($workspace, $parser, $reflector);
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
