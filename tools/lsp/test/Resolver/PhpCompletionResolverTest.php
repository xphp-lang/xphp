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

    public function testCompletesVariablesInScopeAfterDollar(): void
    {
        // Use an already-syntactically-valid completion site (inside an `if`
        // condition) so the source parses cleanly -- a cursor in the middle
        // of an unterminated `echo $re` would have nikic refuse the document
        // and our resolver fall back to empty.
        $workspace = $this->workspace();
        $source = "<?php\n\$repo = 1;\n\$report = 2;\nforeach (\$items as \$item) {}\nif (\$re) {}\n";
        $this->open($workspace, '/doc.xphp', $source);

        $items = $this->completeAt($workspace, '/doc.xphp', $source, 'if ($re', strlen('if ($re'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('$repo', $labels);
        self::assertContains('$report', $labels);
        // `re` doesn't prefix-match `items` or `item`, so they're excluded.
        self::assertNotContains('$items', $labels);
        self::assertNotContains('$item', $labels);
    }

    public function testCompletesVariablesAfterBareDollarSign(): void
    {
        // Cursor immediately after `$` -- we seek inside an existing
        // `$alpha` reference so the source still parses.  The detector
        // sees prefix="" and char-before-prefix="$" -> variable context.
        $workspace = $this->workspace();
        $source = "<?php\n\$alpha = 1;\n\$beta = 2;\necho \$alpha;\n";
        $this->open($workspace, '/doc.xphp', $source);

        $items = $this->completeAt($workspace, '/doc.xphp', $source, 'echo $', strlen('echo $'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('$alpha', $labels);
        self::assertContains('$beta', $labels);
    }

    public function testCompletesUserClassesInExpressionPosition(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $useSource = "<?php\n\$x = new Use";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, 'new Use', strlen('new Use'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        // Short-name match `User` against prefix `Use`.
        self::assertContains('User', $labels);

        // After `new`, only classes -- no functions.
        $kinds = array_map(static fn (CompletionItem $i): int => $i->kind ?? -1, $items);
        self::assertNotContains(\Phpactor\LanguageServerProtocol\CompletionItemKind::FUNCTION, $kinds);
    }

    public function testNewWithEmptyPrefixReturnsEmpty(): void
    {
        // Empty prefix in `new ` would otherwise dump every class FQN in
        // the workspace + stubs into the popup.  Resolver guards against
        // it; user has to type at least one char.
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App;\nclass User {}\n");
        $useSource = "<?php\n\$x = new ";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, 'new ', strlen('new '));
        self::assertSame([], $items);
    }

    public function testCompletesWorkspaceFunctionsByPrefix(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/funcs.xphp', <<<'XPHP'
        <?php
        namespace App;
        function greet(string $n): string { return $n; }
        function gravity(): float { return 9.8; }
        function unrelated(): void {}
        XPHP);
        $useSource = "<?php\nuse function App\\greet;\necho gr";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, 'echo gr', strlen('echo gr'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('greet', $labels);
        self::assertContains('gravity', $labels);
        self::assertNotContains('unrelated', $labels);
    }

    public function testCompletesNativeFunctionsFromStubsByPrefix(): void
    {
        if (!is_dir(ReflectorFactory::defaultStubPath())) {
            self::markTestSkipped('jetbrains/phpstorm-stubs not installed');
        }
        $workspace = $this->workspace();
        $source = "<?php\necho strl";
        $this->open($workspace, '/doc.xphp', $source);

        $items = $this->completeAt($workspace, '/doc.xphp', $source, 'echo strl', strlen('echo strl'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        // `strl` prefix matches `strlen` (and possibly nothing else in stubs).
        self::assertContains('strlen', $labels);
    }

    public function testExpressionPositionEmptyPrefixReturnsEmpty(): void
    {
        // Same guard as `new` -- no completion without a prefix at
        // expression position; the alternative is dumping ~6000 stub
        // FQNs on the user.
        $workspace = $this->workspace();
        $source = "<?php\necho ";
        $this->open($workspace, '/doc.xphp', $source);

        $items = $this->completeAt($workspace, '/doc.xphp', $source, 'echo ', strlen('echo '));
        self::assertSame([], $items);
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
        $workspaceSymbols = new \XPHP\Lsp\Handler\WorkspaceSymbols($workspace, $cache);
        $completionIndex = new \XPHP\Lsp\Resolver\CompletionIndex(
            $workspaceSymbols,
            ReflectorFactory::defaultStubPath(),
        );
        $reflector = (new ReflectorFactory(
            $workspace,
            $cache,
            $parser,
            rootPath: '',
            stubPath: ReflectorFactory::defaultStubPath(),
            cacheDir: ReflectorFactory::defaultCacheDir(),
        ))->build();
        return new PhpCompletionResolver($workspace, $parser, $reflector, $completionIndex, $cache);
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
