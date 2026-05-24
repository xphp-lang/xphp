<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Resolver;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\ReflectorFactory;
use XPHP\Lsp\Resolver\PhpDefinitionResolver;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class PhpDefinitionResolverTest extends TestCase
{
    public function testJumpsFromUseStatementToClass(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', <<<'XPHP'
        <?php
        namespace App\Models;
        final class User {}
        XPHP);
        $useSource = "<?php\nuse App\\Models\\User;\nclass C {}";
        $this->open($workspace, '/Use.xphp', $useSource);

        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, 'use App\\Models\\User', strlen('use App\\Models\\'));
        $this->assertResolves($location, '/User.xphp', 'User');
    }

    public function testJumpsFromNewCtorToClass(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App; class User { public function __construct(public string \$name) {} }\n");
        $useSource = "<?php\nuse App\\User;\n\$u = new User('bob');\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, 'new User', strlen('new '));
        $this->assertResolves($location, '/User.xphp', 'User');
    }

    public function testJumpsFromTypeHintToClass(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App;\nclass User {}\n");
        $useSource = "<?php\nuse App\\User;\nfunction take(User \$u): void {}\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, '(User ', 1);
        $this->assertResolves($location, '/User.xphp', 'User');
    }

    public function testJumpsFromStaticCallToMethod(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/Util.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Util {
            public static function shout(string $s): string { return strtoupper($s); }
        }
        XPHP);
        $useSource = "<?php\nuse App\\Util;\n\$x = Util::shout('hi');\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, '::shout', 2);
        $this->assertResolves($location, '/Util.xphp', 'shout');
    }

    public function testJumpsFromInstanceMethodCallToMethod(): void
    {
        // Inference comes from worse-reflection: `new User()->name(...)`
        // -- the receiver type is inferred from the ctor call.
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', <<<'XPHP'
        <?php
        namespace App;
        class User {
            public function __construct(public string $name) {}
            public function shout(): string { return strtoupper($this->name); }
        }
        XPHP);
        $useSource = "<?php\nuse App\\User;\n\$u = new User('a');\n\$x = \$u->shout();\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, '->shout', 2);
        $this->assertResolves($location, '/User.xphp', 'shout');
    }

    public function testJumpsFromPropertyAccessToProperty(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', <<<'XPHP'
        <?php
        namespace App;
        class User {
            public string $name = 'x';
        }
        XPHP);
        $useSource = "<?php\nuse App\\User;\n\$u = new User();\necho \$u->name;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, '->name', 2);
        $this->assertResolves($location, '/User.xphp', 'name');
    }

    public function testJumpsFromUserFunctionCallToFunctionDeclaration(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/funcs.xphp', "<?php\nnamespace App;\nfunction greet(string \$n): string { return \$n; }\n");
        $useSource = "<?php\nuse function App\\greet;\necho greet('a');\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, 'echo greet', strlen('echo '));
        $this->assertResolves($location, '/funcs.xphp', 'greet');
    }

    public function testJumpsFromNativeFunctionCallToStub(): void
    {
        if (!is_dir(ReflectorFactory::defaultStubPath())) {
            self::markTestSkipped('jetbrains/phpstorm-stubs not installed at expected path');
        }
        $workspace = $this->workspace();
        $useSource = "<?php\n\$len = strlen('hello');\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, 'strlen', 1);

        self::assertNotNull($location, 'native function should resolve via stubs');
        self::assertStringContainsString('phpstorm-stubs', $location->uri);
    }

    public function testJumpsFromStaticConstantToDeclaration(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/Cfg.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Cfg {
            public const MAX = 10;
        }
        XPHP);
        $useSource = "<?php\nuse App\\Cfg;\n\$x = Cfg::MAX;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, '::MAX', 2);
        $this->assertResolves($location, '/Cfg.xphp', 'MAX');
    }

    public function testUnknownClassReturnsNull(): void
    {
        $workspace = $this->workspace();
        $useSource = "<?php\n\$u = new TotallyUnknownClass();\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, 'TotallyUnknownClass', 1);
        self::assertNull($location);
    }

    public function testUnknownDocumentReturnsNull(): void
    {
        $resolver = $this->resolver($this->workspace());
        self::assertNull($resolver->resolve('/never-opened.xphp', 0, 0));
    }

    public function testCursorOnVariableNameReturnsNull(): void
    {
        // Variables aren't part of the MVP GTD scope.  Worse-reflection
        // identifies the symbol as a `variable`, and our resolver's match
        // statement falls through to `default => null`.
        $workspace = $this->workspace();
        $useSource = "<?php\n\$x = 1;\necho \$x;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, 'echo $x', strlen('echo '));
        self::assertNull($location);
    }

    public function testResolvesAcrossXphpSourceWithGenericClause(): void
    {
        // A use-statement reference in an xphp file with generic clauses
        // elsewhere must still resolve. Validates that the strip pipeline
        // doesn't break worse-reflection's view of the document.
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App;\nclass User {}\n");
        $useSource = "<?php\nuse App\\User;\nclass Wrapper<T> { public T \$value; }\n\$u = new User();\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, 'new User', strlen('new '));
        $this->assertResolves($location, '/User.xphp', 'User');
    }

    private function resolveAt(
        PhpactorWorkspace $workspace,
        string $uri,
        string $source,
        string $needle,
        int $offsetInNeedle,
    ): ?Location {
        $byte = strpos($source, $needle);
        self::assertNotFalse($byte, "fixture needle '$needle' must exist");
        $byte += $offsetInNeedle;
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        return $this->resolver($workspace)->resolve($uri, $line, $character);
    }

    private function assertResolves(?Location $location, string $expectedUriSuffix, string $expectedSymbol): void
    {
        self::assertNotNull($location, "expected a location for '$expectedSymbol'");
        self::assertStringEndsWith($expectedUriSuffix, $location->uri);
        // Spot-check the range looks like a name target -- a few characters
        // wide, not the full body.  We can't assert exact line/col without
        // hard-coding fixture geometry, so check the basic shape.
        self::assertGreaterThanOrEqual(0, $location->range->start->line);
        self::assertGreaterThanOrEqual(0, $location->range->start->character);
        self::assertLessThanOrEqual(80, $location->range->end->character - $location->range->start->character);
    }

    private function resolver(PhpactorWorkspace $workspace): PhpDefinitionResolver
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
        return new PhpDefinitionResolver($workspace, $parser, $reflector);
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
