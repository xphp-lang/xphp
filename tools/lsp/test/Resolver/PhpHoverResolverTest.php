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

    public function testMethodHoverSubstitutesParameterTypesAtCallSite(): void
    {
        // Phase 0.6: cursor on a method call whose receiver is a tracked
        // generic-instantiated variable -- the method's parameter types
        // get substituted with the receiver's type-arg bindings.
        // Before this commit, hover on `$users->save($user)` would show
        // `save(T $item): void`; after Phase 0.6, it shows
        // `save(App\Models\User $item): void`.
        $workspace = $this->workspace();
        $this->open($workspace, '/Collection.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Collection<T> {
            public function save(T $item): void {}
            public function first(): ?T { return null; }
        }
        XPHP);
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $useSource = "<?php\nuse App\\Containers\\Collection;\nuse App\\Models\\User;\n\$users = new Collection<User>();\n\$users->save(new User());\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, '$users->save', strlen('$users->save'));
        $markdown = $this->markdown($hover);

        self::assertStringContainsString('save(App\\Models\\User $item)', $markdown);
        self::assertStringNotContainsString('save(T $item)', $markdown);
    }

    public function testMethodHoverSubstitutesMultipleParameters(): void
    {
        // Pair<K, V>::put(K, V): each param gets a different substituted type.
        $workspace = $this->workspace();
        $this->open($workspace, '/Pair.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Pair<K, V> {
            public function put(K $key, V $value): void {}
        }
        XPHP);
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $useSource = "<?php\nuse App\\Containers\\Pair;\nuse App\\Models\\User;\n\$p = new Pair<string, User>();\n\$p->put('x', new User());\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, '$p->put', strlen('$p->put'));
        $markdown = $this->markdown($hover);

        // Both params substituted.
        self::assertStringContainsString('put(string $key, App\\Models\\User $value)', $markdown);
        // Neither placeholder leaks through.
        self::assertStringNotContainsString('K $key', $markdown);
        self::assertStringNotContainsString('V $value', $markdown);
    }

    public function testMethodHoverParamsFallBackToPrettifyWhenNoBinding(): void
    {
        // Cursor on a method call where no generic-instantiation binding
        // is in scope (a closed-over receiver, say).  Substitution can't
        // help; renderMethod falls back to prettify, which strips the
        // namespace from the placeholder.  Result: `T $item` (NOT
        // `App\Containers\T $item`, NOT the substituted form either --
        // because there's no binding to substitute with).
        $workspace = $this->workspace();
        $this->open($workspace, '/Collection.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Collection<T> {
            public function save(T $item): void {}
        }
        XPHP);
        // No `new Collection<User>(...)` in scope -- just hovering a
        // method call on a param-typed-without-generics receiver.
        $useSource = "<?php\nuse App\\Containers\\Collection;\nfunction handle(Collection \$c): void {\n    \$c->save('x');\n}\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, '$c->save', strlen('$c->save'));
        $markdown = $this->markdown($hover);

        self::assertStringContainsString('save(T $item)', $markdown);
        self::assertStringNotContainsString('App\\Containers\\T', $markdown);
    }

    public function testPropertyHoverThroughChainedMethodCall(): void
    {
        // Phase 0.7 headline: `$repo->first()?->name` where
        // `Repository<T>::first(): ?T` and `$repo: Repository<User>`.
        // Property hover at `name` should resolve to User's `$name`
        // (not return null as it did before this phase).
        $workspace = $this->workspace();
        $this->open($workspace, '/Repository.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Repository<T> {
            public function first(): ?T { return null; }
        }
        XPHP);
        $this->open($workspace, '/User.xphp', <<<'XPHP'
        <?php
        namespace App\Models;
        class User {
            /** The displayed name. */
            public string $name = '';
        }
        XPHP);
        $useSource = "<?php\nuse App\\Containers\\Repository;\nuse App\\Models\\User;\n\$repo = new Repository<User>();\necho \$repo->first()?->name;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, '?->name', strlen('?->'));
        $markdown = $this->markdown($hover);

        self::assertStringContainsString('$name', $markdown);
        self::assertStringContainsString('App\\Models\\User', $markdown);
        self::assertStringContainsString('The displayed name.', $markdown);
    }

    public function testPropertyHoverThroughDirectVariableReceiver(): void
    {
        // Variant: `$user->name` where `$user` is a tracked variable
        // assigned from a chained method call.  The receiver is a
        // Variable, not a chained call -- ensures inferType handles
        // both shapes.
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

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, '?->name', strlen('?->'));
        $markdown = $this->markdown($hover);

        self::assertStringContainsString('$name', $markdown);
        self::assertStringContainsString('App\\Models\\User', $markdown);
    }

    public function testPropertyHoverFallsBackToWorseReflectionWhenNoBinding(): void
    {
        // Boundary lock: when no binding is in scope, the resolver
        // returns null and worse-reflection's containerType takes over.
        // For non-generic receivers worse-reflection already works, so
        // this should still render the property.
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', <<<'XPHP'
        <?php
        namespace App;
        class User {
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

    public function testParamTypedScopeEntrySubstitutesInFunctionBody(): void
    {
        // Phase 1.1 e2e: cursor on a variable assigned from a method call
        // INSIDE a function whose parameter is generic-typed -- the param
        // type seeds the binding, the method call substitutes through it.
        $workspace = $this->workspace();
        $this->open($workspace, '/Collection.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Collection<T> {
            public function first(): ?T { return null; }
        }
        XPHP);
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $useSource = "<?php\nuse App\\Containers\\Collection;\nuse App\\Models\\User;\nfunction handle(Collection<User> \$users) {\n    \$first = \$users->first();\n    echo \$first;\n}\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'echo $first', strlen('echo '));
        $markdown = $this->markdown($hover);

        self::assertStringContainsString('?App\\Models\\User $first', $markdown);
        self::assertStringNotContainsString('?T $first', $markdown);
    }

    public function testVariableHoverPrettifyWorksWithFilesystemOnlyGenericClass(): void
    {
        // Phase 0.5 e2e: Collection.xphp is closed (only on disk).
        // GenericResolver can't substitute the chain (no binding in scope),
        // so the fallback path goes through worse-reflection + prettify.
        // Before Phase 0.5, prettify saw no `Collection<T>` in open docs
        // and left `?App\Containers\T` un-stripped.  Now FqnIndex's
        // filesystem index contributes the placeholder pair.
        $root = sys_get_temp_dir() . '/xphp-hover-fs-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        try {
            file_put_contents($root . '/Collection.xphp', <<<'XPHP'
            <?php
            namespace App\Containers;
            class Collection<T> {
                public function first(): ?T { return null; }
            }
            XPHP);

            $workspace = $this->workspace();
            // Closure-captured variable so GenericResolver doesn't model
            // it (closure body sees an isolated scope without the outer
            // binding flowing through here -- mimicking the production
            // scenario where the resolver returned null and prettify
            // had to handle rendering).
            $useSource = "<?php\nnamespace App\\Demos;\nuse App\\Containers\\Collection;\nfunction example(Collection \$c): void {\n    \$x = \$c->first();\n    echo \$x;\n}\n";
            $this->open($workspace, '/Use.xphp', $useSource);

            $hover = $this->hoverAtWithRoot($workspace, '/Use.xphp', $useSource, 'echo $x', strlen('echo '), $root);
            $markdown = $this->markdown($hover);

            // The placeholder must be stripped to `?T`, not surface as
            // `?App\Containers\T` -- which is the un-prettified form that
            // production showed before Phase 0.5.
            self::assertStringNotContainsString(
                '?App\\Containers\\T',
                $markdown,
                'Phase 0.5 must strip placeholder namespace even when Collection.xphp is closed',
            );
            self::assertStringContainsString('?T $x', $markdown);
        } finally {
            $this->rmrf($root);
        }
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

    public function testPropertyHoverOnSubstitutedReceiverFromStaticCall(): void
    {
        // This test originally asserted null because pre-Phase-1.2 the
        // static call `Util::identity<User>(...)` couldn't substitute,
        // and pre-Phase-0.7 the property hover couldn't find User.  Now
        // both work in combination: the static call binds `$asUser` to
        // `App\User`, and the property hover at `$asUser->name`
        // consults GenericResolver to find User's `$name` property.
        //
        // The hover still doesn't crash on MissingType -- that
        // robustness check is now covered by the per-symbol catch in
        // resolveInner and the null-check in renderProperty.
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

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, '$asUser->name', strlen('$asUser->'));
        $markdown = $this->markdown($hover);

        self::assertStringContainsString('$name', $markdown);
        self::assertStringContainsString('App\\User', $markdown);
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

    private function hoverAtWithRoot(
        PhpactorWorkspace $workspace,
        string $uri,
        string $source,
        string $needle,
        int $offsetInNeedle,
        string $rootPath,
    ): ?Hover {
        $byte = strpos($source, $needle);
        self::assertNotFalse($byte, "fixture needle '$needle' must exist");
        $byte += $offsetInNeedle;
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        return $this->resolverWithRoot($workspace, $rootPath)->resolve($uri, $line, $character);
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

    private function markdown(?Hover $hover): string
    {
        self::assertNotNull($hover);
        $content = $hover->contents;
        self::assertInstanceOf(MarkupContent::class, $content);
        return $content->value;
    }

    private function resolver(PhpactorWorkspace $workspace): PhpHoverResolver
    {
        return $this->resolverWithRoot($workspace, '');
    }

    private function resolverWithRoot(PhpactorWorkspace $workspace, string $rootPath): PhpHoverResolver
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $reflector = (new ReflectorFactory(
            $workspace,
            $cache,
            $parser,
            rootPath: $rootPath,
            stubPath: ReflectorFactory::defaultStubPath(),
            cacheDir: ReflectorFactory::defaultCacheDir(),
            fqnIndex: $fqnIndex = new \XPHP\Lsp\Reflection\FqnIndex($workspace, $cache, $parser, $rootPath),
        ))->build();
        $classLikeLookup = new \XPHP\Lsp\Resolver\CompositeClassLikeLookup(
            new WorkspaceClassLikeLookup($workspace, $cache),
            new \XPHP\Lsp\Resolver\FilesystemClassLikeLookup($fqnIndex),
        );
        $generic = new GenericResolver($workspace, $cache, $classLikeLookup, $parser, $fqnIndex);
        return new PhpHoverResolver(
            $workspace,
            $parser,
            $reflector,
            new GenericParamRegistry($fqnIndex),
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
