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

        self::assertSame('?App\\Models\\User', $resolver->resolveVariable('/Use.xphp', 'user'));
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
            $resolver->resolveVariable('/Use.xphp', 'users'),
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

        self::assertSame('int', $resolver->resolveVariable('/Use.xphp', 'v'));
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

        self::assertSame('string', $resolver->resolveVariable('/Use.xphp', 'k'));
        self::assertSame('App\\Models\\User', $resolver->resolveVariable('/Use.xphp', 'v'));
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

        self::assertNull($resolver->resolveVariable('/Use.xphp', 'x'));
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

        self::assertNull($resolver->resolveVariable('/Use.xphp', 'x'));
    }

    public function testStaticMethodCallIsOutOfScopeAndReturnsNull(): void
    {
        // `Util::identity<T>(...)` is the method-scoped-generic call shape;
        // resolver explicitly doesn't model it.
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

        self::assertNull($resolver->resolveVariable('/Use.xphp', 'u'));
    }

    public function testGenericFunctionCallIsOutOfScopeAndReturnsNull(): void
    {
        // Free-function generic `identity<T>(...)` -- not a MethodCall on
        // a tracked variable; not a `new`.  Resolver yields.
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

        self::assertNull($resolver->resolveVariable('/Use.xphp', 'u'));
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
        self::assertSame('?App\\Models\\User', $resolver->resolveVariable('/Use.xphp', 'user'));

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

        self::assertSame('App\\Models\\User', $resolver->resolveVariable('/Use.xphp', 'user'));
    }

    private function resolver(PhpactorWorkspace $workspace): GenericResolver
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $lookup = new WorkspaceClassLikeLookup($workspace, $cache);
        return new GenericResolver($workspace, $cache, $lookup, $parser);
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
}
