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

    public function testJumpsFromStaticPropertyAccessToProperty(): void
    {
        // Follow-up item 4: GTD on `Foo::$prop` should jump to the
        // static property declaration, symmetric to instance-property
        // GTD.  worse-reflection's containerType on a StaticPropertyFetch
        // resolves to the LHS class, so locateProperty already works.
        $workspace = $this->workspace();
        $this->open($workspace, '/Counter.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Counter {
            public static int $count = 0;
        }
        XPHP);
        $useSource = "<?php\nuse App\\Counter;\necho Counter::\$count;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, '::$count', 2);
        $this->assertResolves($location, '/Counter.xphp', 'count');
    }

    public function testJumpsFromUseFunctionImportToFunctionDeclaration(): void
    {
        // Regression: cursor on the function name inside a
        // `use function App\foo;` statement should GTD to the function
        // declaration.  Worse-reflection misclassifies the imported
        // name as Symbol::CLASS_ -- a fallback in PhpDefinitionResolver
        // detects the AST context (Use_::TYPE_FUNCTION) and routes to
        // locateFunction() instead.
        $workspace = $this->workspace();
        $this->open($workspace, '/funcs.xphp', "<?php\nnamespace App;\nfunction greet(string \$n): string { return \$n; }\n");
        $useSource = "<?php\nuse function App\\greet;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        // Cursor on `greet` inside `use function App\greet`.
        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, 'App\\greet', strlen('App\\'));
        $this->assertResolves($location, '/funcs.xphp', 'greet');
    }

    public function testJumpsFromUseFunctionGroupImportToFunctionDeclaration(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/funcs.xphp', "<?php\nnamespace App;\nfunction greet() {}\nfunction wave() {}\n");
        $useSource = "<?php\nuse function App\\{greet, wave};\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        // Cursor on `greet` inside `use function App\{greet, wave}`.
        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, '{greet', strlen('{'));
        $this->assertResolves($location, '/funcs.xphp', 'greet');
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

    public function testJumpsFromNamespacedGlobalConstantToStub(): void
    {
        // Regression for the prod PHP_EOL bug.  A bare `PHP_EOL`
        // referenced inside `namespace App\Demos` name-resolves to
        // `App\Demos\PHP_EOL` -- never declared anywhere -- but PHP's
        // runtime falls back to the global `PHP_EOL` (stub-indexed).
        // Pre-fix, `locateConstant` only tried the namespaced form
        // and returned null; PhpStorm then showed "Cannot find
        // declaration to go to."
        if (!is_dir(ReflectorFactory::defaultStubPath())) {
            self::markTestSkipped('jetbrains/phpstorm-stubs not installed at expected path');
        }
        $workspace = $this->workspace();
        $useSource = "<?php\nnamespace App\\Demos;\necho PHP_EOL;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, 'echo PHP_EOL', strlen('echo '));

        self::assertNotNull($location, 'global-namespace fallback must resolve namespaced builtin constants');
        self::assertStringContainsString('phpstorm-stubs', $location->uri);
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

    public function testPropertyAccessOnSubstitutedReceiverFromStaticCall(): void
    {
        // Originally a crash-safety test: pre-hotfix code crashed when
        // dispatching with `containerType=MissingType` (from
        // `$asUser = Util::identity<User>(...)` whose return-type
        // resolved to a bare `T` worse-reflection couldn't find).
        // After Phase 1.2 (static-call substitution) + Phase 0.7
        // (property-receiver substitution), the chain now resolves
        // correctly and GTD jumps to User::$name.
        //
        // No-crash guarantee is still in place (resolveInner has a
        // top-level try/catch); this test now also asserts the
        // positive resolution.
        $workspace = $this->workspace();
        $this->open($workspace, '/Util.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Util {
            public static function identity<T>(T $x): T { return $x; }
        }
        XPHP);
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App;\nclass User { public string \$name = ''; }\n");
        $useSource = "<?php\nuse App\\Util;\nuse App\\User;\n\$asUser = Util::identity<User>(new User());\necho \$asUser->name;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, '$asUser->name', strlen('$asUser->'));

        self::assertNotNull($location, 'GTD must resolve to User::$name through Phase 1.2 + 0.7');
        self::assertStringEndsWith('/User.xphp', $location->uri);
    }

    public function testJumpsFromVariableUseToInitialAssignment(): void
    {
        // PhpStorm's native PHP GTD on a variable jumps to the variable's
        // first introduction in the enclosing scope.  We replicate that
        // by walking the AST for the first Param / Assign / Foreach / Use
        // node naming this variable.
        $workspace = $this->workspace();
        $useSource = "<?php\n\$x = 1;\necho \$x;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, 'echo $x', strlen('echo $'));

        self::assertNotNull($location);
        // The first `$x` lives on line 1 (0-indexed) at the start of the document.
        self::assertSame(1, $location->range->start->line);
        self::assertSame(0, $location->range->start->character);
    }

    public function testJumpsFromVariableUseToFunctionParameter(): void
    {
        $workspace = $this->workspace();
        $useSource = "<?php\nfunction greet(string \$name): string {\n    return \$name;\n}\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, 'return $name', strlen('return $'));

        self::assertNotNull($location);
        // `$name` parameter is on line 1 (`function greet(string $name): string {`).
        self::assertSame(1, $location->range->start->line);
    }

    public function testJumpsFromVariableUseToForeachLoopVariable(): void
    {
        $workspace = $this->workspace();
        $useSource = "<?php\nforeach (\$items as \$item) {\n    echo \$item;\n}\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, 'echo $item', strlen('echo $'));

        self::assertNotNull($location);
        // The loop's `$item` is on line 1, after `foreach ($items as `.
        self::assertSame(1, $location->range->start->line);
    }

    public function testJumpsFromVariableUseToClosureUseClause(): void
    {
        $workspace = $this->workspace();
        $useSource = "<?php\n\$captured = 1;\n\$fn = function () use (\$captured) {\n    return \$captured;\n};\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        // Inside the closure body, $captured refers to the use-imported
        // variable.  Our resolver currently isn't scope-aware so it
        // resolves to the FIRST introduction in document order -- the
        // top-level assignment on line 1.  That's still a useful jump
        // and matches PhpStorm's PHP GTD when both sites are visible
        // in the document.
        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, 'return $captured', strlen('return $'));

        self::assertNotNull($location);
        self::assertSame(1, $location->range->start->line);
    }

    public function testNeverIntroducedVariableReturnsNull(): void
    {
        // `$x` is used but never assigned / declared as a param.  No
        // introduction site -> null.
        $workspace = $this->workspace();
        $useSource = "<?php\necho \$x;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, 'echo $x', strlen('echo $'));
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

    public function testGotoDefinitionOnPropertyThroughChainedMethodCall(): void
    {
        // Phase 0.7: GTD on `$repo->first()?->name` jumps to User::$name
        // by way of substituting `?T` -> `?User` on the receiver.
        $workspace = $this->workspace();
        $this->open($workspace, '/Repository.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Repository<T> {
            public function first(): ?T { return null; }
        }
        XPHP);
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User { public string \$name = ''; }\n");
        $useSource = "<?php\nuse App\\Containers\\Repository;\nuse App\\Models\\User;\n\$repo = new Repository<User>();\necho \$repo->first()?->name;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, '?->name', strlen('?->'));

        self::assertNotNull($location);
        self::assertStringEndsWith('/User.xphp', $location->uri);
    }

    public function testGotoDefinitionOnPropertyThroughTrackedVariable(): void
    {
        // GTD on `$user?->name` where `$user` came from a chained
        // method call.  Hits the Variable branch of inferType.
        $workspace = $this->workspace();
        $this->open($workspace, '/Repository.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Repository<T> {
            public function first(): ?T { return null; }
        }
        XPHP);
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User { public string \$name = ''; }\n");
        $useSource = "<?php\nuse App\\Containers\\Repository;\nuse App\\Models\\User;\n\$repo = new Repository<User>();\n\$user = \$repo->first();\necho \$user?->name;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $location = $this->resolveAt($workspace, '/Use.xphp', $useSource, '?->name', strlen('?->'));

        self::assertNotNull($location);
        self::assertStringEndsWith('/User.xphp', $location->uri);
    }

    public function testUnionReceiverFanOutReturnsAllConstituentClassLocations(): void
    {
        // Cycle K: cursor on `$x->foo()` where `$x: A|B` should
        // return Locations for BOTH A::foo and B::foo so PhpStorm
        // renders a picker.  worse-reflection's containerType()
        // surfaces the union; the dispatch's fanOutLocate splits
        // it and merges per-constituent locations.
        $workspace = $this->workspace();
        $this->open($workspace, '/A.xphp', <<<'XPHP'
        <?php
        namespace App;
        class A {
            public function foo(): string { return 'a'; }
        }
        XPHP);
        $this->open($workspace, '/B.xphp', <<<'XPHP'
        <?php
        namespace App;
        class B {
            public function foo(): string { return 'b'; }
        }
        XPHP);
        $useSource = "<?php\nuse App\\A;\nuse App\\B;\n/** @return A|B */\nfunction pick() { return new A(); }\n\$x = pick();\n\$x->foo();\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $locations = $this->resolveAllAt($workspace, '/Use.xphp', $useSource, '->foo', strlen('->'));

        // Both A::foo and B::foo must appear in the result.  The
        // legacy `resolve()` returns the first; `resolveAll()` is
        // the fan-out used by the Cycle K handler.
        self::assertCount(2, $locations);
        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        $endsWithA = array_filter($uris, fn (string $u): bool => str_ends_with($u, '/A.xphp'));
        $endsWithB = array_filter($uris, fn (string $u): bool => str_ends_with($u, '/B.xphp'));
        self::assertNotEmpty($endsWithA, 'A::foo declaration is in the picker');
        self::assertNotEmpty($endsWithB, 'B::foo declaration is in the picker');
    }

    /**
     * @return list<Location>
     */
    private function resolveAllAt(
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
        return $this->resolver($workspace)->resolveAll($uri, $line, $character);
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

    /**
     * @dataProvider acceptedClassFqnProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('acceptedClassFqnProvider')]
    public function testIsClassFqnAcceptsPlausibleClassNames(string $typeName): void
    {
        self::assertTrue(PhpDefinitionResolver::isClassFqn($typeName), $typeName);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function acceptedClassFqnProvider(): iterable
    {
        yield 'simple name' => ['User'];
        yield 'namespaced' => ['App\\Models\\User'];
        yield 'leading backslash' => ['\\App\\Models\\User'];
        yield 'nullable' => ['?App\\Models\\User'];
        yield 'nullable leading backslash' => ['?\\App\\Models\\User'];
        yield 'underscore-prefix' => ['_internal'];
    }

    /**
     * @dataProvider rejectedClassFqnProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedClassFqnProvider')]
    public function testIsClassFqnRejectsNonClassTypeStrings(string $typeName): void
    {
        // worse-reflection emits these shapes for inferred non-class
        // types -- feeding them to `reflectClassLike` causes a
        // SourceNotFound after a wasted locator walk + a stderr miss
        // log line.  isClassFqn must catch every shape seen in prod.
        self::assertFalse(PhpDefinitionResolver::isClassFqn($typeName), $typeName);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function rejectedClassFqnProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'missing sentinel' => ['<missing>'];
        yield 'union' => ['App\\Foo|App\\Bar'];
        yield 'intersection' => ['App\\Foo&App\\Bar'];
        yield 'grouped union of intersections' => [
            '(PhpParser\\Node\\Stmt\\ClassLike&PhpParser\\Node\\Stmt\\Class_)|(PhpParser\\Node\\Stmt\\ClassLike&PhpParser\\Node\\Stmt\\Interface_)',
        ];
        yield 'grouped method-call union' => [
            '(PhpParser\\Node&PhpParser\\Node\\Expr\\MethodCall)|(PhpParser\\Node&PhpParser\\Node\\Expr\\NullsafeMethodCall)',
        ];
        yield 'integer literal zero' => ['0'];
        yield 'integer literal one' => ['1'];
        yield 'string literal' => ["'foo'"];
    }

    public function testReturnsNullWhenAlreadyCancelledAtEntry(): void
    {
        // Fix D: pre-cancelled token bails at the top of resolveInner,
        // before worse-reflection's reflectOffset runs.
        $workspace = new PhpactorWorkspace();
        $userSource = "<?php\nnamespace App;\nclass User {}\n";
        $workspace->open(new \Phpactor\LanguageServerProtocol\TextDocumentItem('/User.xphp', 'xphp', 1, $userSource));
        $useSource = "<?php\nuse App\\User;\n\$u = new User();\n";
        $workspace->open(new \Phpactor\LanguageServerProtocol\TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $cancel = new \Amp\CancellationTokenSource();
        $cancel->cancel();

        $byte = strpos($useSource, 'new User');
        self::assertNotFalse($byte);
        [$line, $character] = (new \XPHP\Lsp\PositionMap($useSource))->offsetToPosition($byte + 4);

        $location = $this->resolver($workspace)->resolve('/Use.xphp', $line, $character, $cancel->getToken());
        self::assertNull($location, 'cancelled token must produce no location even when symbol resolves');
    }

    private function resolver(PhpactorWorkspace $workspace): PhpDefinitionResolver
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $fqnIndex = new \XPHP\Lsp\Reflection\FqnIndex($workspace, $cache, $parser, '');
        $reflector = (new ReflectorFactory(
            $workspace,
            $cache,
            $parser,
            rootPath: '',
            stubPath: ReflectorFactory::defaultStubPath(),
            cacheDir: ReflectorFactory::defaultCacheDir(),
            fqnIndex: $fqnIndex,
        ))->build();
        $classLikeLookup = new \XPHP\Lsp\Resolver\WorkspaceClassLikeLookup($workspace, $cache);
        $generic = new \XPHP\Lsp\Resolver\GenericResolver($workspace, $cache, $classLikeLookup, $parser, $fqnIndex);
        return new PhpDefinitionResolver($workspace, $parser, $reflector, $cache, $generic);
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
