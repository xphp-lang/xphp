<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Resolver;

use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use Phpactor\LanguageServerProtocol\VersionedTextDocumentIdentifier;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Resolver\CompositeClassLikeLookup;
use XPHP\Lsp\Resolver\FilesystemClassLikeLookup;
use XPHP\Lsp\Resolver\GenericResolver;
use XPHP\Lsp\Resolver\WorkspaceClassLikeLookup;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class GenericResolverTest extends TestCase
{
    public function testSubstitutesNullableTypeParamFromGenericMethodCall(): void
    {
        // The user's headline scenario: Collection<User>::first(): ?T
        // -> hover on `$user` resolves to `?App\Models\User`.
        $workspace = $this->workspace();
        $this->openCollection($workspace, returnType: '?T');
        $this->openUser($workspace);
        $this->open($workspace, '/Use.xphp', <<<'XPHP'
        <?php
        use App\Containers\Collection;
        use App\Models\User;
        $users = new Collection<User>();
        $user = $users->first();
        XPHP);

        $resolver = $this->resolver($workspace);

        self::assertSame('?App\\Models\\User', $resolver->resolveVariable('/Use.xphp', 'user', PHP_INT_MAX));
    }

    public function testRendersReceiverVariableWithTypeArgList(): void
    {
        // Hovering the receiver itself benefits too: `$users` of type
        // Collection<User> renders as the qualified form including
        // the type-arg list, more informative than worse-reflection's
        // base-class-only `App\Containers\Collection`.
        $workspace = $this->workspace();
        $this->openCollection($workspace, returnType: '?T');
        $this->openUser($workspace);
        $this->open($workspace, '/Use.xphp', <<<'XPHP'
        <?php
        use App\Containers\Collection;
        use App\Models\User;
        $users = new Collection<User>();
        XPHP);

        $resolver = $this->resolver($workspace);

        self::assertSame(
            'App\\Containers\\Collection<App\\Models\\User>',
            $resolver->resolveVariable('/Use.xphp', 'users', PHP_INT_MAX),
        );
    }

    public function testSubstitutesScalarBoundType(): void
    {
        // Wrapper<int>::value(): T -> `int`.
        $workspace = $this->workspace();
        $this->open($workspace, '/Wrapper.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Wrapper<T> {
            public function value(): T { return null; }
        }
        XPHP);
        $this->open($workspace, '/Use.xphp', <<<'XPHP'
        <?php
        use App\Containers\Wrapper;
        $w = new Wrapper<int>();
        $v = $w->value();
        XPHP);

        $resolver = $this->resolver($workspace);

        self::assertSame('int', $resolver->resolveVariable('/Use.xphp', 'v', PHP_INT_MAX));
    }

    public function testSubstitutesMultiParamBinding(): void
    {
        // Pair<K, V>: each method picks a different param.
        $workspace = $this->workspace();
        $this->open($workspace, '/Pair.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Pair<K, V> {
            public function key(): K { return null; }
            public function value(): V { return null; }
        }
        XPHP);
        $this->openUser($workspace);
        $this->open($workspace, '/Use.xphp', <<<'XPHP'
        <?php
        use App\Containers\Pair;
        use App\Models\User;
        $p = new Pair<string, User>();
        $k = $p->key();
        $v = $p->value();
        XPHP);

        $resolver = $this->resolver($workspace);

        self::assertSame('string', $resolver->resolveVariable('/Use.xphp', 'k', PHP_INT_MAX));
        self::assertSame('App\\Models\\User', $resolver->resolveVariable('/Use.xphp', 'v', PHP_INT_MAX));
    }

    public function testNonGenericInstantiationReturnsNull(): void
    {
        // `$x = new User()` (no type-args) -> resolver yields to fallback.
        $workspace = $this->workspace();
        $this->openUser($workspace);
        $this->open($workspace, '/Use.xphp', <<<'XPHP'
        <?php
        use App\Models\User;
        $x = new User();
        XPHP);

        $resolver = $this->resolver($workspace);

        self::assertNull($resolver->resolveVariable('/Use.xphp', 'x', PHP_INT_MAX));
    }

    public function testUnknownReceiverClassReturnsNull(): void
    {
        // `$x = new Mystery<int>()` where Mystery isn't declared in any
        // open document -- ClassLikeLookup misses, resolver yields.
        $workspace = $this->workspace();
        $this->open($workspace, '/Use.xphp', <<<'XPHP'
        <?php
        $x = new Mystery<int>();
        XPHP);

        $resolver = $this->resolver($workspace);

        self::assertNull($resolver->resolveVariable('/Use.xphp', 'x', PHP_INT_MAX));
    }

    public function testStaticMethodCallSubstitutesReturnTypeAtCallSite(): void
    {
        // Phase 1.2: `Util::identity<User>(new User())` -- the method's
        // type-param T is bound to User at the call site, so the
        // substituted return type is User.  The resolver previously
        // returned null for this shape; this test pins the new behavior.
        $workspace = $this->workspace();
        $this->open($workspace, '/Util.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Util {
            public static function identity<T>(T $x): T { return $x; }
        }
        XPHP);
        $this->openUser($workspace);
        $this->open($workspace, '/Use.xphp', <<<'XPHP'
        <?php
        use App\Util;
        use App\Models\User;
        $u = Util::identity<User>(new User());
        XPHP);

        $resolver = $this->resolver($workspace);

        self::assertSame(
            'App\\Models\\User',
            $resolver->resolveVariable('/Use.xphp', 'u', PHP_INT_MAX),
        );
    }

    public function testStaticMethodCallWithQualifiedClassName(): void
    {
        // `\App\Util::identity<User>(...)` -- already-qualified class
        // names bypass the use map.
        $workspace = $this->workspace();
        $this->open($workspace, '/Util.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Util {
            public static function identity<T>(T $x): T { return $x; }
        }
        XPHP);
        $this->openUser($workspace);
        $this->open($workspace, '/Use.xphp', <<<'XPHP'
        <?php
        $u = \App\Util::identity<\App\Models\User>(new \App\Models\User());
        XPHP);

        $resolver = $this->resolver($workspace);

        self::assertSame(
            'App\\Models\\User',
            $resolver->resolveVariable('/Use.xphp', 'u', PHP_INT_MAX),
        );
    }

    public function testStaticMethodCallWithoutGenericArgsReturnsNull(): void
    {
        // A static call without `<...>` (regular static method) is NOT
        // tracked -- the resolver only fires when type-args are present.
        $workspace = $this->workspace();
        $this->open($workspace, '/Util.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Util {
            public static function greet(): string { return ''; }
        }
        XPHP);
        $this->open($workspace, '/Use.xphp', <<<'XPHP'
        <?php
        use App\Util;
        $g = Util::greet();
        XPHP);

        $resolver = $this->resolver($workspace);

        self::assertNull($resolver->resolveVariable('/Use.xphp', 'g', PHP_INT_MAX));
    }

    public function testGenericFunctionCallSubstitutesReturnType(): void
    {
        // Phase 1.3: free-function generic `identity<User>(new User())`.
        // The FuncCall carries ATTR_TEMPLATE_FQN + ATTR_METHOD_GENERIC_ARGS;
        // resolver locates the function via FqnIndex and substitutes T -> User.
        $workspace = $this->workspace();
        $this->open($workspace, '/fn.xphp', <<<'XPHP'
        <?php
        namespace App;
        function identity<T>(T $x): T { return $x; }
        XPHP);
        $this->openUser($workspace);
        $this->open($workspace, '/Use.xphp', <<<'XPHP'
        <?php
        use function App\identity;
        use App\Models\User;
        $u = identity<User>(new User());
        XPHP);

        $resolver = $this->resolver($workspace);

        self::assertSame(
            'App\\Models\\User',
            $resolver->resolveVariable('/Use.xphp', 'u', PHP_INT_MAX),
        );
    }

    public function testGenericFunctionCallWithFilesystemOnlyDeclaration(): void
    {
        // Phase 1.3 + Phase 0: the generic function lives on disk, not in
        // the editor.  FqnIndex's filesystem fallback parses it on demand.
        $root = sys_get_temp_dir() . '/xphp-fn-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        try {
            file_put_contents($root . '/identity.xphp', <<<'XPHP'
            <?php
            namespace App;
            function identity<T>(T $x): T { return $x; }
            XPHP);
            $workspace = $this->workspace();
            $this->openUser($workspace);
            $this->open($workspace, '/Use.xphp', <<<'XPHP'
            <?php
            use App\Models\User;
            $u = \App\identity<User>(new User());
            XPHP);

            $resolver = $this->resolverWithFilesystem($workspace, $root);

            self::assertSame(
                'App\\Models\\User',
                $resolver->resolveVariable('/Use.xphp', 'u', PHP_INT_MAX),
            );
        } finally {
            $this->rmrf($root);
        }
    }

    public function testNonGenericFunctionCallReturnsNull(): void
    {
        // A regular function call (no `<...>` args) is NOT a generic-call
        // site -- the resolver yields and lets worse-reflection's own
        // inference handle it.
        $workspace = $this->workspace();
        $this->open($workspace, '/fn.xphp', <<<'XPHP'
        <?php
        namespace App;
        function greet(string $name): string { return $name; }
        XPHP);
        $this->open($workspace, '/Use.xphp', <<<'XPHP'
        <?php
        use function App\greet;
        $g = greet('a');
        XPHP);

        $resolver = $this->resolver($workspace);

        self::assertNull($resolver->resolveVariable('/Use.xphp', 'g', PHP_INT_MAX));
    }

    public function testSubstitutesTypeArgsWhenClassDeclarationIsFilesystemOnly(): void
    {
        // Phase-0 payoff: Collection.xphp is NOT open in the workspace --
        // it lives only on disk.  The new FilesystemClassLikeLookup +
        // FqnIndex pair should still resolve `$users->first()` to
        // `?App\Models\User` via on-demand parsing.
        $root = sys_get_temp_dir() . '/xphp-fs-gen-' . bin2hex(random_bytes(6));
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
            // User.xphp + Use.xphp open in editor; Collection.xphp NOT open.
            $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
            $this->open($workspace, '/Use.xphp', <<<'XPHP'
            <?php
            use App\Containers\Collection;
            use App\Models\User;
            $users = new Collection<User>();
            $user = $users->first();
            XPHP);

            $resolver = $this->resolverWithFilesystem($workspace, $root);

            self::assertSame(
                '?App\\Models\\User',
                $resolver->resolveVariable('/Use.xphp', 'user', PHP_INT_MAX),
                'GenericResolver must resolve via filesystem when Collection.xphp is closed',
            );
        } finally {
            $this->rmrf($root);
        }
    }

    public function testParamTypedScopeEntrySeedsBinding(): void
    {
        // Phase 1.1: `function f(Collection<User> $users) { ... }` --
        // inside the body, `$users` is bound to Collection<User>, and
        // `$users->first()` substitutes to ?App\Models\User.  No `new`
        // expression in scope: the binding comes from the parameter type.
        $workspace = $this->workspace();
        $this->openCollection($workspace, returnType: '?T');
        $this->openUser($workspace);
        $source = <<<'XPHP'
        <?php
        use App\Containers\Collection;
        use App\Models\User;
        function f(Collection<User> $users) {
            $first = $users->first();
        }
        XPHP;
        $this->open($workspace, '/Use.xphp', $source);

        $resolver = $this->resolver($workspace);
        $offset = strpos($source, '$first = $users->first();') + 1;
        self::assertIsInt($offset);

        self::assertSame(
            '?App\\Models\\User',
            $resolver->resolveVariable('/Use.xphp', 'first', $offset),
        );
        self::assertSame(
            'App\\Containers\\Collection<App\\Models\\User>',
            $resolver->resolveVariable('/Use.xphp', 'users', $offset),
        );
    }

    public function testParamTypedScopeEntryDoesNotLeakAcrossFunctions(): void
    {
        // Two functions with conflicting `$x` param types -- each scope
        // wins on lookup at its own offset.
        $workspace = $this->workspace();
        $this->openCollection($workspace, returnType: '?T');
        $this->openUser($workspace);
        $this->open($workspace, '/Other.xphp', <<<'XPHP'
        <?php
        namespace App\Models;
        class Post {}
        XPHP);
        $source = <<<'XPHP'
        <?php
        use App\Containers\Collection;
        use App\Models\User;
        use App\Models\Post;
        function withUsers(Collection<User> $items) {
            $a = $items->first();
        }
        function withPosts(Collection<Post> $items) {
            $b = $items->first();
        }
        XPHP;
        $this->open($workspace, '/Use.xphp', $source);

        $resolver = $this->resolver($workspace);
        $aOffset = strpos($source, '$a = $items') + 1;
        $bOffset = strpos($source, '$b = $items') + 1;

        // Inside withUsers, $items is Collection<User> -> $a: ?User.
        self::assertSame(
            '?App\\Models\\User',
            $resolver->resolveVariable('/Use.xphp', 'a', $aOffset),
        );
        // Inside withPosts, $items is Collection<Post> -> $b: ?Post.
        self::assertSame(
            '?App\\Models\\Post',
            $resolver->resolveVariable('/Use.xphp', 'b', $bOffset),
        );
    }

    public function testParamTypedScopeEntryOnClassMethod(): void
    {
        // Methods inside a class get the same Param treatment as free
        // functions.  Covers the constructor / method shape that real
        // user code hits more often than free functions.
        $workspace = $this->workspace();
        $this->openCollection($workspace, returnType: '?T');
        $this->openUser($workspace);
        $source = <<<'XPHP'
        <?php
        use App\Containers\Collection;
        use App\Models\User;
        class Service {
            public function handle(Collection<User> $users): void
            {
                $first = $users->first();
            }
        }
        XPHP;
        $this->open($workspace, '/Use.xphp', $source);

        $resolver = $this->resolver($workspace);
        $offset = strpos($source, '$first = $users') + 1;

        self::assertSame(
            '?App\\Models\\User',
            $resolver->resolveVariable('/Use.xphp', 'first', $offset),
        );
    }

    public function testNullableParamTypeStillSeedsBinding(): void
    {
        // `?Collection<User> $users` -- the leading `?` doesn't change
        // the receiver class.  The seed should still establish the
        // Collection<User> binding for method calls inside the body.
        $workspace = $this->workspace();
        $this->openCollection($workspace, returnType: '?T');
        $this->openUser($workspace);
        $source = <<<'XPHP'
        <?php
        use App\Containers\Collection;
        use App\Models\User;
        function f(?Collection<User> $users) {
            $first = $users->first();
        }
        XPHP;
        $this->open($workspace, '/Use.xphp', $source);

        $resolver = $this->resolver($workspace);
        $offset = strpos($source, '$first = $users') + 1;

        self::assertSame(
            '?App\\Models\\User',
            $resolver->resolveVariable('/Use.xphp', 'first', $offset),
        );
    }

    public function testNonGenericParamSeedsNothing(): void
    {
        // `function f(User $u)` -- User isn't a generic class, so the
        // resolver has nothing to seed.  worse-reflection covers this
        // through its own inference path; the resolver yields.
        $workspace = $this->workspace();
        $this->openUser($workspace);
        $source = <<<'XPHP'
        <?php
        use App\Models\User;
        function f(User $u) {
            $x = $u;
        }
        XPHP;
        $this->open($workspace, '/Use.xphp', $source);

        $resolver = $this->resolver($workspace);
        $offset = strpos($source, '$x = $u') + 1;

        self::assertNull($resolver->resolveVariable('/Use.xphp', 'u', $offset));
        self::assertNull($resolver->resolveVariable('/Use.xphp', 'x', $offset));
    }

    public function testParamScopeDoesNotLeakIntoTopLevel(): void
    {
        // A `$users` parameter inside a function MUST NOT shadow a
        // top-level usage of the same variable name.  Cursor outside the
        // function body resolves only against the top-level scope (which
        // here has nothing for `$users`).
        $workspace = $this->workspace();
        $this->openCollection($workspace, returnType: '?T');
        $this->openUser($workspace);
        $source = <<<'XPHP'
        <?php
        use App\Containers\Collection;
        use App\Models\User;
        function f(Collection<User> $users) {
            $a = $users->first();
        }
        echo $users;
        XPHP;
        $this->open($workspace, '/Use.xphp', $source);

        $resolver = $this->resolver($workspace);
        $topLevelOffset = strpos($source, 'echo $users') + strlen('echo ');

        self::assertNull(
            $resolver->resolveVariable('/Use.xphp', 'users', $topLevelOffset),
            'top-level cursor must not see the inner function param',
        );
    }

    public function testTwoStepChainSubstitutesThroughIntermediate(): void
    {
        // Phase 1.4: `$repo->items()->first()` where Repository<User>::items()
        // returns Collection<T> and Collection<T>::first() returns ?T.
        // The intermediate `items()` produces Collection<User>; the outer
        // `first()` then substitutes T -> User against that.
        $workspace = $this->workspace();
        $this->open($workspace, '/Collection.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Collection<T> {
            public function first(): ?T { return null; }
        }
        XPHP);
        $this->open($workspace, '/Repository.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Repository<T> {
            public function items(): Collection<T> { return new Collection<T>(); }
        }
        XPHP);
        $this->openUser($workspace);
        $this->open($workspace, '/Use.xphp', <<<'XPHP'
        <?php
        use App\Containers\Repository;
        use App\Models\User;
        $repo = new Repository<User>();
        $user = $repo->items()->first();
        XPHP);

        $resolver = $this->resolver($workspace);

        self::assertSame(
            '?App\\Models\\User',
            $resolver->resolveVariable('/Use.xphp', 'user', PHP_INT_MAX),
        );
    }

    public function testThreeStepChainStillSubstitutes(): void
    {
        // Phase 1.4 stress test: three method calls in one statement.
        // n-step recursion through inferType should compose cleanly.
        $workspace = $this->workspace();
        $this->open($workspace, '/Wrap.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Wrap<T> {
            public function inner(): Inner<T> { return new Inner<T>(); }
        }
        class Inner<T> {
            public function items(): Collection<T> { return new Collection<T>(); }
        }
        class Collection<T> {
            public function first(): ?T { return null; }
        }
        XPHP);
        $this->openUser($workspace);
        $this->open($workspace, '/Use.xphp', <<<'XPHP'
        <?php
        use App\Containers\Wrap;
        use App\Models\User;
        $w = new Wrap<User>();
        $u = $w->inner()->items()->first();
        XPHP);

        $resolver = $this->resolver($workspace);

        self::assertSame(
            '?App\\Models\\User',
            $resolver->resolveVariable('/Use.xphp', 'u', PHP_INT_MAX),
        );
    }

    public function testClosureCapturesOuterBinding(): void
    {
        // Phase 1.5: `function () use ($users) { $first = $users->first(); }`
        // -- the closure's `use ($users)` propagates the outer binding
        // into the closure's own scope.  Without this propagation the
        // closure body would see an empty scope.
        $workspace = $this->workspace();
        $this->openCollection($workspace, returnType: '?T');
        $this->openUser($workspace);
        $source = <<<'XPHP'
        <?php
        use App\Containers\Collection;
        use App\Models\User;
        $users = new Collection<User>();
        $fn = function () use ($users) {
            $first = $users->first();
        };
        XPHP;
        $this->open($workspace, '/Use.xphp', $source);

        $resolver = $this->resolver($workspace);
        $offset = strpos($source, '$first = $users') + 1;

        self::assertSame(
            '?App\\Models\\User',
            $resolver->resolveVariable('/Use.xphp', 'first', $offset),
        );
    }

    public function testClosureWithoutUseClauseCannotSeeOuterBinding(): void
    {
        // Without `use ($users)`, the closure's scope is isolated from
        // the outer scope -- accessing `$users` inside is undefined.
        // The resolver yields, matching PHP's runtime behaviour where
        // the variable is unbound.
        $workspace = $this->workspace();
        $this->openCollection($workspace, returnType: '?T');
        $this->openUser($workspace);
        $source = <<<'XPHP'
        <?php
        use App\Containers\Collection;
        use App\Models\User;
        $users = new Collection<User>();
        $fn = function () {
            $first = $users->first();
        };
        XPHP;
        $this->open($workspace, '/Use.xphp', $source);

        $resolver = $this->resolver($workspace);
        $offset = strpos($source, '$first = $users') + 1;

        self::assertNull(
            $resolver->resolveVariable('/Use.xphp', 'first', $offset),
            'closure without use clause must not see outer bindings',
        );
    }

    public function testClosureParamSeededAlongsideCapture(): void
    {
        // Params + captured uses both seed the closure scope.  Mixed
        // usage: a generic-typed param plus a captured outer variable.
        $workspace = $this->workspace();
        $this->openCollection($workspace, returnType: '?T');
        $this->openUser($workspace);
        $source = <<<'XPHP'
        <?php
        use App\Containers\Collection;
        use App\Models\User;
        $outer = new Collection<User>();
        $fn = function (Collection<User> $param) use ($outer) {
            $a = $param->first();
            $b = $outer->first();
        };
        XPHP;
        $this->open($workspace, '/Use.xphp', $source);

        $resolver = $this->resolver($workspace);
        $aOffset = strpos($source, '$a = $param') + 1;
        $bOffset = strpos($source, '$b = $outer') + 1;

        self::assertSame(
            '?App\\Models\\User',
            $resolver->resolveVariable('/Use.xphp', 'a', $aOffset),
        );
        self::assertSame(
            '?App\\Models\\User',
            $resolver->resolveVariable('/Use.xphp', 'b', $bOffset),
        );
    }

    public function testRebuildsBindingsOnDocumentVersionBump(): void
    {
        // Cache is version-keyed -- a `didChange` on the file declaring
        // the generic class must surface in the next hover.
        $workspace = $this->workspace();
        $this->openCollection($workspace, returnType: '?T', uri: '/Collection.xphp', version: 1);
        $this->openUser($workspace);
        $this->open($workspace, '/Use.xphp', <<<'XPHP'
        <?php
        use App\Containers\Collection;
        use App\Models\User;
        $users = new Collection<User>();
        $user = $users->first();
        XPHP);

        $resolver = $this->resolver($workspace);
        self::assertSame('?App\\Models\\User', $resolver->resolveVariable('/Use.xphp', 'user', PHP_INT_MAX));

        // Re-publish Collection.xphp at v2 with first() now returning T
        // (no `?`).  Bindings live on /Use.xphp's cache; since the
        // ClassLikeLookup walks current workspace state every call, the
        // method-return read picks up the new shape on the next request.
        $newCollection = <<<'XPHP'
        <?php
        namespace App\Containers;
        class Collection<T> {
            public function first(): T { return null; }
        }
        XPHP;
        $workspace->update(
            new VersionedTextDocumentIdentifier(2, '/Collection.xphp'),
            $newCollection,
        );
        // Force /Use.xphp to rebuild too -- otherwise the resolver's
        // own cache returns the v1 result.  In production a didChange
        // on /Use.xphp would bump its version; tests can do the same
        // manually.
        $useSource = $workspace->get('/Use.xphp')->text;
        $workspace->update(new VersionedTextDocumentIdentifier(2, '/Use.xphp'), $useSource);

        self::assertSame('App\\Models\\User', $resolver->resolveVariable('/Use.xphp', 'user', PHP_INT_MAX));
    }

    private function resolver(PhpactorWorkspace $workspace): GenericResolver
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $lookup = new WorkspaceClassLikeLookup($workspace, $cache);
        $index = new FqnIndex($workspace, $cache, $parser, '');
        return new GenericResolver($workspace, $cache, $lookup, $parser, $index);
    }

    private function resolverWithFilesystem(PhpactorWorkspace $workspace, string $rootPath): GenericResolver
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $index = new FqnIndex($workspace, $cache, $parser, $rootPath);
        $lookup = new CompositeClassLikeLookup(
            new WorkspaceClassLikeLookup($workspace, $cache),
            new FilesystemClassLikeLookup($index),
        );
        return new GenericResolver($workspace, $cache, $lookup, $parser, $index);
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

    private function workspace(): PhpactorWorkspace
    {
        return new PhpactorWorkspace();
    }

    private function open(PhpactorWorkspace $workspace, string $uri, string $source): void
    {
        $workspace->open(new TextDocumentItem($uri, 'xphp', 1, $source));
    }

    private function openCollection(
        PhpactorWorkspace $workspace,
        string $returnType,
        string $uri = '/Collection.xphp',
        int $version = 1,
    ): void {
        $workspace->open(new TextDocumentItem($uri, 'xphp', $version, <<<XPHP
        <?php
        namespace App\Containers;
        class Collection<T> {
            public function first(): {$returnType} { return null; }
        }
        XPHP));
    }

    private function openUser(PhpactorWorkspace $workspace): void
    {
        $workspace->open(new TextDocumentItem(
            '/User.xphp',
            'xphp',
            1,
            "<?php\nnamespace App\\Models;\nclass User {}\n",
        ));
    }

    public function testSubstitutesPromotedPropertyFetchFromVarBinding(): void
    {
        // Prod scenario: `class StringableBox<T> { public function
        // __construct(public T $item) {} }`.  Hovering `$item` after
        // `$item = $v->item` should show `Tag`, not `T`.
        $workspace = $this->workspace();
        $workspace->open(new TextDocumentItem('/Box.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Containers;
        class StringableBox<T> {
            public function __construct(public T $item) {}
        }
        XPHP));
        $workspace->open(new TextDocumentItem('/Tag.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Models;
        class Tag {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        use App\Containers\StringableBox;
        use App\Models\Tag;
        $v = new StringableBox<Tag>(new Tag());
        $item = $v->item;
        XPHP));

        $resolved = $this->resolver($workspace)->resolveVariable('/Use.xphp', 'item', PHP_INT_MAX);
        self::assertSame('App\\Models\\Tag', $resolved);
    }

    public function testSubstitutesRegularPropertyFetchFromVarBinding(): void
    {
        // Regular (non-promoted) property declaration variant of the
        // above -- covers the `Property` branch of `findPropertyType`.
        $workspace = $this->workspace();
        $workspace->open(new TextDocumentItem('/Box.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Containers;
        class Box<T> {
            public T $item;
        }
        XPHP));
        $workspace->open(new TextDocumentItem('/Tag.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Models;
        class Tag {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        use App\Containers\Box;
        use App\Models\Tag;
        $b = new Box<Tag>();
        $item = $b->item;
        XPHP));

        $resolved = $this->resolver($workspace)->resolveVariable('/Use.xphp', 'item', PHP_INT_MAX);
        self::assertSame('App\\Models\\Tag', $resolved);
    }

    public function testReturnsNullForPropertyFetchOnNonGenericReceiver(): void
    {
        // Defensive: receiver isn't a tracked generic instantiation
        // -- the property-fetch path must bail null so the caller
        // falls back to worse-reflection.
        $workspace = $this->workspace();
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        $item = $opaque->item;
        XPHP));

        self::assertNull($this->resolver($workspace)->resolveVariable('/Use.xphp', 'item', PHP_INT_MAX));
    }
}
