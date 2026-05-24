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
use XPHP\Lsp\Resolver\GenericParamRegistry;
use XPHP\Lsp\Resolver\GenericResolver;
use XPHP\Lsp\Resolver\PhpHoverResolver;
use XPHP\Lsp\Resolver\WorkspaceClassLikeLookup;
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

    public function testHoversVariableWithInferredScalarType(): void
    {
        $workspace = $this->workspace();
        $useSource = "<?php\n\$x = 1;\necho \$x;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'echo $x', strlen('echo '));
        $markdown = $this->markdown($hover);
        self::assertStringContainsString('int', $markdown);
        self::assertStringContainsString('$x', $markdown);
    }

    public function testHoversVariableWithInferredClassType(): void
    {
        // The exact gap noted in xphp-20260524-204801-302.log id=11:
        // hover on `$users` showed null because we didn't dispatch
        // Symbol::VARIABLE.  Now we render the inferred type.
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App;\nclass User { public function __construct(public string \$name) {} }\n");
        $useSource = "<?php\nuse App\\User;\n\$u = new User('a');\necho \$u;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'echo $u', strlen('echo '));
        $markdown = $this->markdown($hover);
        self::assertStringContainsString('App\\User', $markdown);
        self::assertStringContainsString('$u', $markdown);
    }

    public function testVariableHoverSubstitutesGenericReturnType(): void
    {
        // The user's exact production case: `$user` is assigned from a
        // generic method whose return type involves the type-param `T`,
        // and the receiver was instantiated as `Collection<User>` --
        // so hovering `$user` must show `?App\Models\User $user`, NOT
        // `?T $user` (the unresolved placeholder).
        $workspace = $this->workspace();
        $this->open($workspace, '/Collection.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Collection<T> {
            public function first(): ?T { return null; }
        }
        XPHP);
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $useSource = "<?php\nuse App\\Containers\\Collection;\nuse App\\Models\\User;\n\$users = new Collection<User>();\n\$user = \$users->first();\necho \$user;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'echo $user', strlen('echo '));
        $markdown = $this->markdown($hover);

        // Substituted form is present.
        self::assertStringContainsString('?App\\Models\\User $user', $markdown);
        // Neither the placeholder nor its qualified form leaked through.
        self::assertStringNotContainsString('?T $user', $markdown);
        self::assertStringNotContainsString('App\\Containers\\T', $markdown);
    }

    public function testMethodHoverSubstitutesReturnTypeAtCallSite(): void
    {
        // Cursor on the `first` token in `$users->first()` -- the method
        // hover signature should reflect `Collection<User>`'s binding and
        // show `?App\Models\User` rather than `?T`.  Parallel to the
        // variable-hover fix but applied one statement earlier in the chain.
        $workspace = $this->workspace();
        $this->open($workspace, '/Collection.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Collection<T> {
            public function first(): ?T { return null; }
        }
        XPHP);
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $useSource = "<?php\nuse App\\Containers\\Collection;\nuse App\\Models\\User;\n\$users = new Collection<User>();\n\$user = \$users->first();\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, '$users->first', strlen('$users->first'));
        $markdown = $this->markdown($hover);

        self::assertStringContainsString('): ?App\\Models\\User', $markdown);
        self::assertStringNotContainsString('): ?T', $markdown);
        self::assertStringNotContainsString('App\\Containers\\T', $markdown);
    }

    public function testVariableHoverFallsBackToPrettifyForUnmodeledShapes(): void
    {
        // GenericResolver only handles same-file `new Generic<...>()` +
        // `$var = $other->method()` chains.  For shapes it doesn't
        // model (here: a bare variable whose worse-reflection-inferred
        // type still carries a generic placeholder, with NO `new`
        // assignment in scope to bind against), the fallback path
        // through GenericParamRegistry::prettify continues to strip the
        // namespace and produce `?T`.
        $workspace = $this->workspace();
        $this->open($workspace, '/Collection.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Collection<T> {
            public function first(): ?T { return null; }
            public function getMaybeFirst(?T $fallback): ?T { return $fallback; }
        }
        XPHP);
        // No `new Collection<User>(...)` in this snippet: `$x` is a
        // closure parameter we can't trace.  GenericResolver returns null,
        // worse-reflection surfaces `?App\Containers\T`, prettify strips
        // the namespace.
        $useSource = "<?php\nuse App\\Containers\\Collection;\n\$fn = function (Collection \$c) { \$x = \$c->first(); echo \$x; };\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'echo $x', strlen('echo '));
        $markdown = $this->markdown($hover);

        self::assertStringNotContainsString('App\\Containers\\T', $markdown);
        self::assertStringContainsString('?T $x', $markdown);
    }

    public function testReturnsNullOnVariableWithNoInferableType(): void
    {
        // Undeclared variable referenced bare -- worse-reflection has
        // nothing to infer, so we suppress the hover rather than show
        // an empty tooltip.
        $workspace = $this->workspace();
        $useSource = "<?php\necho \$undeclared;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'echo $undeclared', strlen('echo '));
        self::assertNull($hover);
    }

    public function testReturnsNullForUnknownDocument(): void
    {
        $resolver = $this->resolver($this->workspace());
        self::assertNull($resolver->resolve('/never-opened.xphp', 0, 0));
    }

    public function testPropertyHoverOnInferenceFailureReturnsNullNotCrash(): void
    {
        // Parallel to PhpDefinitionResolverTest::testPropertyAccessOnInferenceFailureReturnsNullNotCrash --
        // hovering `$asUser->name` after `$asUser = Util::identity<User>(...)`
        // sees containerType=MissingType.  Pre-hotfix would have called
        // `MissingType::name()` on the dispatch line and crashed.
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

        // Must not throw.
        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, '$asUser->name', strlen('$asUser->'));
        self::assertNull($hover);
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
        $classLikeLookup = new WorkspaceClassLikeLookup($workspace, $cache);
        $generic = new GenericResolver($workspace, $cache, $classLikeLookup, $parser);
        return new PhpHoverResolver(
            $workspace,
            $parser,
            $reflector,
            new GenericParamRegistry($workspace, $cache),
            $generic,
        );
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
