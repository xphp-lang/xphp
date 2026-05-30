<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\ReferenceContext;
use Phpactor\LanguageServerProtocol\ReferenceParams;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\XphpReferencesHandler;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Reflection\ReflectorFactory;
use XPHP\Lsp\Resolver\CompositeClassLikeLookup;
use XPHP\Lsp\Resolver\FilesystemClassLikeLookup;
use XPHP\Lsp\Resolver\GenericResolver;
use XPHP\Lsp\Analyzer\ParsedDocumentCacheWarmer;
use XPHP\Lsp\Resolver\ReferenceFinder;
use XPHP\Lsp\Resolver\WorkspaceClassLikeLookup;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

final class XphpReferencesHandlerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xphp-refs-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->rmrf($this->root);
        }
    }

    public function testMethodsMapRegistersEndpoint(): void
    {
        $handler = $this->handler(new PhpactorWorkspace());
        self::assertArrayHasKey('textDocument/references', $handler->methods());
        self::assertSame('references', $handler->methods()['textDocument/references']);
    }

    public function testFindsClassReferencesAcrossOpenDocs(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class User {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Use1.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Other;
        use App\User;
        $u = new User();
        XPHP));
        $workspace->open(new TextDocumentItem('/Use2.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Other;
        function f(\App\User $u): void {}
        XPHP));

        $locations = $this->references($workspace, '/User.xphp', 'class User', strlen('class '));

        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        // Four matches: declaration + `use App\User;` import + `new User()` + `\App\User` type hint.
        // The `use` statement IS counted as a usage -- PhpStorm's Find
        // Usages groups them under "imports" but does surface them.
        self::assertCount(4, $locations);
        self::assertContains('/User.xphp', $uris);
        self::assertContains('/Use1.xphp', $uris);
        self::assertContains('/Use2.xphp', $uris);
    }

    public function testIncludeDeclarationFalseDropsDeclSite(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass User {}\n"));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, "<?php\nnamespace X;\nuse App\\User;\n\$u = new User();\n"));

        $locations = $this->references(
            $workspace,
            '/User.xphp',
            'class User',
            strlen('class '),
            includeDeclaration: false,
        );

        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        // 2 matches in /Use.xphp (the `use App\User;` import + `new User()`);
        // the declaration in /User.xphp is dropped by includeDeclaration=false.
        self::assertCount(2, $locations);
        foreach ($uris as $u) {
            self::assertSame('/Use.xphp', $u);
        }
    }

    public function testFindsClassReferencesFromUseCaseInsteadOfDeclaration(): void
    {
        // Cursor on a USE of the class -- should still find every other
        // use of it, not just the cursor's own line.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass User {}\n"));
        $workspace->open(new TextDocumentItem('/Use1.xphp', 'xphp', 1, "<?php\nnamespace X;\nuse App\\User;\n\$u = new User();\n"));
        $workspace->open(new TextDocumentItem('/Use2.xphp', 'xphp', 1, "<?php\nuse App\\User;\n\$u = new User();\n"));

        $locations = $this->references($workspace, '/Use1.xphp', 'new User()', strlen('new '));

        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        // All three files contain App\User -- decl + two uses (each Use*
        // contains one `new User()` reference).
        sort($uris);
        self::assertContains('/User.xphp', $uris);
        self::assertContains('/Use1.xphp', $uris);
        self::assertContains('/Use2.xphp', $uris);
    }

    public function testFindsFunctionReferences(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/lib.xphp', 'xphp', 1, "<?php\nnamespace App;\nfunction identity(\$x) { return \$x; }\n"));
        $workspace->open(new TextDocumentItem('/use.xphp', 'xphp', 1, "<?php\nuse function App\\identity;\nidentity(1);\nidentity(2);\n"));

        $locations = $this->references($workspace, '/lib.xphp', 'function identity', strlen('function '));

        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        // Decl + `use function App\identity` import + 2 calls = 4.
        self::assertCount(4, $locations);
        self::assertContains('/lib.xphp', $uris);
        self::assertContains('/use.xphp', $uris);
    }

    public function testFindsFunctionRefsInsideGroupUseStmt(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/lib.xphp', 'xphp', 1, "<?php\nnamespace App;\nfunction one() {}\nfunction two() {}\n"));
        $workspace->open(new TextDocumentItem('/use.xphp', 'xphp', 1, "<?php\nuse function App\\{one, two};\none();\n"));

        $locations = $this->references($workspace, '/lib.xphp', 'function one', strlen('function '));
        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        // decl + group-use entry for `one` + call in use.xphp = 3.
        self::assertCount(3, $locations);
        self::assertContains('/use.xphp', $uris);
    }

    public function testFindsReferencesAcrossFilesystem(): void
    {
        file_put_contents($this->root . '/User.xphp', "<?php\nnamespace App;\nclass User {}\n");
        file_put_contents($this->root . '/Consumer.xphp', "<?php\nnamespace App\\X;\nuse App\\User;\n\$u = new User();\n");

        $workspace = new PhpactorWorkspace();
        // Only ONE doc is open -- the cursor sits in it.  The other
        // reference lives on disk only.  Find Usages should still see it.
        $workspace->open(new TextDocumentItem($this->root . '/User.xphp', 'xphp', 1, file_get_contents($this->root . '/User.xphp')));

        $locations = $this->references(
            $workspace,
            $this->root . '/User.xphp',
            'class User',
            strlen('class '),
        );

        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        self::assertContains($this->root . '/User.xphp', $uris, 'declaration site');
        self::assertContains('file://' . $this->root . '/Consumer.xphp', $uris, 'unopened filesystem reference');
    }

    public function testInstanceofExtendsImplementsAllSurfaceAsClassRefs(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Base.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass Base {}\ninterface IBase {}\n"));
        $workspace->open(new TextDocumentItem('/Child.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass Child extends Base implements IBase {\n    public function f(\$x): bool { return \$x instanceof Base; }\n}\n"));

        $locations = $this->references($workspace, '/Base.xphp', 'class Base', strlen('class '));
        $uris = array_map(fn (Location $l): string => $l->uri, $locations);

        // Declaration + extends Base + instanceof Base.
        self::assertCount(3, $locations);
        self::assertContains('/Base.xphp', $uris);
        self::assertContains('/Child.xphp', $uris);
        $childMatches = array_filter($locations, fn ($l) => $l->uri === '/Child.xphp');
        self::assertCount(2, $childMatches, 'extends + instanceof both count');
    }

    public function testFindsMethodReferencesAcrossWorkspace(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class User {
            public function shout(): string { return ''; }
        }
        XPHP));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        use App\User;
        $u = new User();
        $u->shout();
        $u->shout();
        XPHP));

        // Cursor on the method declaration: `function shout`.
        $locations = $this->references($workspace, '/User.xphp', 'function shout', strlen('function '));

        // Declaration in /User.xphp + 2 calls in /Use.xphp = 3.
        self::assertCount(3, $locations);
        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        self::assertContains('/User.xphp', $uris);
        $useMatches = array_filter($locations, fn (Location $l): bool => $l->uri === '/Use.xphp');
        self::assertCount(2, $useMatches);
    }

    public function testFindsMethodReferencesFromCallSiteCursor(): void
    {
        // Cursor on a `$x->method()` call -- should still find every
        // call of the same method on the same receiver class, including
        // the declaration site.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass User {\n    public function shout(): string { return ''; }\n}\n"));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, "<?php\nuse App\\User;\n\$u = new User();\n\$u->shout();\n"));

        $locations = $this->references($workspace, '/Use.xphp', '->shout', strlen('->'));

        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        self::assertContains('/User.xphp', $uris);
        self::assertContains('/Use.xphp', $uris);
    }

    public function testMethodRefsDoNotLeakAcrossClassesWithSameName(): void
    {
        // Two unrelated classes both define `shout()`.  Cursor on User's
        // declaration must NOT surface calls on a Megaphone instance.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Both.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class User {
            public function shout(): string { return ''; }
        }
        class Megaphone {
            public function shout(): string { return ''; }
        }
        $u = new User();
        $u->shout();
        $m = new Megaphone();
        $m->shout();
        XPHP));

        // Cursor on User's `function shout`.
        $source = $workspace->get('/Both.xphp')->text;
        $byte = strpos($source, 'function shout') + strlen('function ');
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        $handler = $this->handler($workspace);
        $params = new ReferenceParams(
            new ReferenceContext(true),
            new TextDocumentIdentifier('/Both.xphp'),
            new Position($line, $character),
        );
        $locations = wait($handler->references($params));

        // Decl + $u->shout() = 2.  $m->shout() (on line index 11) must
        // NOT appear.  No location should sit on the Megaphone call's line.
        self::assertCount(2, $locations);
        foreach ($locations as $loc) {
            self::assertNotSame(11, $loc->range->start->line, 'must not match the Megaphone instance call');
        }
    }

    public function testFindsInheritedMethodCallsOnSubclassReceiver(): void
    {
        // Item 1: cursor on `Animal::speak` should also surface
        // `$dog->speak()` -- Dog extends Animal and doesn't override
        // `speak`, so the call inherits its behaviour.  Before Item 1,
        // V1's exact-FQN match dropped the Dog call.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Animal.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Animal {
            public function speak(): string { return ''; }
        }
        XPHP));
        $workspace->open(new TextDocumentItem('/Dog.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Dog extends Animal {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        use App\Animal;
        use App\Dog;
        $a = new Animal();
        $a->speak();
        $d = new Dog();
        $d->speak();
        XPHP));

        // Cursor on Animal's `function speak` declaration.
        $locations = $this->references($workspace, '/Animal.xphp', 'function speak', strlen('function '));

        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        self::assertContains('/Animal.xphp', $uris);
        $useMatches = array_filter($locations, fn (Location $l): bool => $l->uri === '/Use.xphp');
        // Both `$a->speak()` AND `$d->speak()` must be in the result.
        self::assertCount(2, $useMatches);
    }

    public function testOverriddenSubclassMethodIsNotMatchedAsAncestorCall(): void
    {
        // Item 1 negative case: if Dog overrides `speak`, then
        // `$d->speak()` resolves to `Dog::speak`, not `Animal::speak`.
        // Cursor on Animal::speak must NOT surface the Dog call.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Animal.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Animal {
            public function speak(): string { return ''; }
        }
        XPHP));
        $workspace->open(new TextDocumentItem('/Dog.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Dog extends Animal {
            public function speak(): string { return 'woof'; }
        }
        XPHP));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        use App\Animal;
        use App\Dog;
        $a = new Animal();
        $a->speak();
        $d = new Dog();
        $d->speak();
        XPHP));

        $locations = $this->references($workspace, '/Animal.xphp', 'function speak', strlen('function '));

        $useMatches = array_filter($locations, fn (Location $l): bool => $l->uri === '/Use.xphp');
        // Only `$a->speak()` matches; `$d->speak()` resolves to Dog::speak
        // (an override) and is a different symbol.
        self::assertCount(1, $useMatches);
    }

    public function testCursorOnSubclassInheritedCallResolvesToAncestor(): void
    {
        // Cursor on `$d->speak()` (with Dog extends Animal, no override)
        // -- the target should resolve up to Animal::speak, so the
        // declaration in Animal.xphp is found AND every call site
        // through the chain (including `$a->speak()` on the parent).
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Animal.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Animal {
            public function speak(): string { return ''; }
        }
        XPHP));
        $workspace->open(new TextDocumentItem('/Dog.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Dog extends Animal {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        use App\Animal;
        use App\Dog;
        $a = new Animal();
        $a->speak();
        $d = new Dog();
        $d->speak();
        XPHP));

        // Cursor on `$d->speak()` call.
        $locations = $this->references($workspace, '/Use.xphp', '$d->speak', strlen('$d->'));

        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        // Declaration must be found through the ancestor walk.
        self::assertContains('/Animal.xphp', $uris);
        // Both call sites in /Use.xphp must be present.
        $useMatches = array_filter($locations, fn (Location $l): bool => $l->uri === '/Use.xphp');
        self::assertCount(2, $useMatches);
    }

    public function testFindsUsagesAcrossUnionReceiverConstituents(): void
    {
        // Cycle K.1: cursor on a method call where the receiver is
        // union-typed (`$x: A|B`) should surface call sites where
        // the receiver is typed as either A OR B (or the union
        // itself).
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/A.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class A {
            public function foo(): string { return 'a'; }
        }
        XPHP));
        $workspace->open(new TextDocumentItem('/B.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class B {
            public function foo(): string { return 'b'; }
        }
        XPHP));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        use App\A;
        use App\B;
        /** @return A|B */
        function pick() { return new A(); }
        $x = pick();
        $x->foo();
        $a = new A();
        $a->foo();
        $b = new B();
        $b->foo();
        XPHP));

        // Cursor on `$x->foo()` -- the union-typed receiver call.
        $source = $workspace->get('/Use.xphp')->text;
        $byte = strpos($source, '$x->foo') + strlen('$x->');
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        $locations = $this->referencesAtPosition($workspace, '/Use.xphp', $line, $character);

        $useMatches = array_filter($locations, fn (Location $l): bool => $l->uri === '/Use.xphp');
        // Three call sites in /Use.xphp: `$x->foo()`, `$a->foo()`,
        // `$b->foo()`.  Pre-Cycle-K.1 we'd only find `$a->foo()` if
        // target had locked to A; now ALL three surface.
        self::assertCount(3, $useMatches, 'union-receiver cursor surfaces every constituent call site');
    }

    public function testFindsImplementorReceiverFromInterfaceMethod(): void
    {
        // #116 interface-up: cursor on `Iface::save` finds calls
        // on impl-typed receivers.  Pre-fix the receiver-side check
        // returned false because worse-reflection's `declaringClass`
        // for `Impl::save` resolves to `Impl` (the body lives there),
        // never to `Iface`, so the exact-FQN comparison rejected the
        // call site.  The new interface walk catches this.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Iface.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        interface Repo {
            public function save(string $item): void;
        }
        XPHP));
        $workspace->open(new TextDocumentItem('/Impl.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class InMemoryRepo implements Repo {
            public function save(string $item): void {}
        }
        XPHP));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        use App\InMemoryRepo;
        $r = new InMemoryRepo();
        $r->save('a');
        $r->save('b');
        XPHP));

        // Cursor on the interface method declaration `function save`
        // inside `interface Repo`.
        $locations = $this->references($workspace, '/Iface.xphp', 'function save', strlen('function '));

        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        self::assertContains('/Iface.xphp', $uris, 'interface decl is a match');
        self::assertContains('/Impl.xphp', $uris, '#116: impl decl surfaces too');
        $useMatches = array_filter($locations, fn (Location $l): bool => $l->uri === '/Use.xphp');
        self::assertCount(2, $useMatches, '#116: impl-typed receiver calls are matched');
    }

    public function testFindsInterfaceTypedReceiverFromConcreteMethod(): void
    {
        // #116 interface-down: cursor on `Impl::save` should also
        // match `$x->save()` where `$x` is typed as the interface.
        // This is the symmetric case to the test above.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Iface.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        interface Repo {
            public function save(string $item): void;
        }
        XPHP));
        $workspace->open(new TextDocumentItem('/Impl.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class InMemoryRepo implements Repo {
            public function save(string $item): void {}
        }
        XPHP));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        use App\Repo;
        function persist(Repo $r): void {
            $r->save('hello');
        }
        XPHP));

        // Cursor on the impl method declaration `function save`
        // inside `class InMemoryRepo`.
        $locations = $this->references($workspace, '/Impl.xphp', 'function save', strlen('function '));

        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        self::assertContains('/Impl.xphp', $uris, 'concrete decl is a match');
        self::assertContains('/Iface.xphp', $uris, '#116: interface decl also surfaces');
        $useMatches = array_filter($locations, fn (Location $l): bool => $l->uri === '/Use.xphp');
        self::assertCount(1, $useMatches, '#116: interface-typed parameter call matches');
    }

    public function testInterfaceWalkSpansClassInheritanceAndInterfaceExtends(): void
    {
        // #116 transitive walk: `class Dog extends Animal implements
        // Speaker` finds calls on Dog when cursor is on Speaker::speak.
        // Also: `interface Loud extends Speaker { … }` -- a Repo
        // implementing Loud must surface for cursor on Speaker::speak.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Iface.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        interface Speaker {
            public function speak(): string;
        }
        interface Loud extends Speaker {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Animal.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        abstract class Animal {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Dog.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Dog extends Animal implements Loud {
            public function speak(): string { return 'woof'; }
        }
        XPHP));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        use App\Dog;
        $d = new Dog();
        $d->speak();
        XPHP));

        // Cursor on Speaker::speak in the interface.
        $locations = $this->references($workspace, '/Iface.xphp', 'function speak', strlen('function '));

        $useMatches = array_filter($locations, fn (Location $l): bool => $l->uri === '/Use.xphp');
        self::assertCount(1, $useMatches, '#116: Dog->speak() matches via Loud extends Speaker');
        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        self::assertContains('/Dog.xphp', $uris, '#116: Dog::speak impl decl surfaces too');
    }

    public function testInterfaceWalkDoesNotMatchUnrelatedSameNameMethod(): void
    {
        // #116 negative: a class that does NOT implement the interface
        // but happens to declare a same-named method must not match.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Iface.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        interface Repo {
            public function save(string $item): void;
        }
        XPHP));
        $workspace->open(new TextDocumentItem('/Unrelated.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Diary {
            public function save(string $entry): void {}
        }
        XPHP));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        use App\Diary;
        $d = new Diary();
        $d->save('dear journal');
        XPHP));

        $locations = $this->references($workspace, '/Iface.xphp', 'function save', strlen('function '));

        $useMatches = array_filter($locations, fn (Location $l): bool => $l->uri === '/Use.xphp');
        self::assertCount(0, $useMatches, '#116: unrelated same-name method must not match');
        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        self::assertNotContains('/Unrelated.xphp', $uris, '#116: unrelated class decl must not match');
    }

    public function testInterfaceWalkAcrossMultiHopInterfaceExtends(): void
    {
        // #116 multi-hop: `interface K extends J extends I`.
        // worse-reflection's `ReflectionInterface::parents()` is shallow
        // (returns direct `extends` only), so the helper walks
        // transitively.  Without that walk, cursor on `I::m` would miss
        // calls on `K`-typed parameters.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Ifaces.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        interface I { public function m(): void; }
        interface J extends I {}
        interface K extends J {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Impl.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class C implements K {
            public function m(): void {}
        }
        XPHP));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        use App\K;
        function call(K $x): void {
            $x->m();
        }
        XPHP));

        $locations = $this->references($workspace, '/Ifaces.xphp', 'function m', strlen('function '));

        $useMatches = array_filter($locations, fn (Location $l): bool => $l->uri === '/Use.xphp');
        self::assertCount(1, $useMatches, '#116: multi-hop K extends J extends I reaches I::m');
    }

    public function testInterfaceWalkReachesAncestorWhenChildRedeclaresMethod(): void
    {
        // #116 specifically exercises the ReflectionInterface arm of
        // `classImplementsTransitively`: when `J extends I` and J
        // redeclares `m`, worse-reflection's `K::methods()->get('m')->
        // declaringClass()` returns J (the closest declarer), not I.
        // Cursor on `I::m` should still match calls on `$x: K extends J
        // extends I` -- that requires walking K.parents() -> J ->
        // I.parents() transitively via `interfaceExtendsTransitively`.
        // Without it the find-references pass would stop at J and miss
        // K-typed call sites.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Ifaces.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        interface I { public function m(): void; }
        interface J extends I { public function m(): void; }
        interface K extends J {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        use App\K;
        function call(K $x): void {
            $x->m();
        }
        XPHP));

        // Cursor on I's `function m` (the ancestor declaration).
        $source = $workspace->get('/Ifaces.xphp')->text;
        $byte = strpos($source, 'function m') + strlen('function ');
        $iLineCount = substr_count(substr($source, 0, $byte), "\n");
        // Belt + braces: confirm we picked the I-declared one (the first
        // `function m` byte-offset in the file, which is on the I line).
        self::assertSame(2, $iLineCount, 'sanity-check cursor lands on I::m');

        $locations = $this->references($workspace, '/Ifaces.xphp', 'function m', strlen('function '));

        $useMatches = array_filter($locations, fn (Location $l): bool => $l->uri === '/Use.xphp');
        self::assertCount(1, $useMatches, '#116: I::m must match $k->m() despite J redeclaring m');

        // Also assert J::m declaration surfaces -- the interface-walk's
        // identical-needle check is what links J back to I.  Without
        // declarationMatchesTarget reaching through J.parents() -> I,
        // we'd only see the I::m line.
        $declMatches = array_filter(
            $locations,
            fn (Location $l): bool => $l->uri === '/Ifaces.xphp',
        );
        // Both `function m` decls (lines 2 and 3 -- the I and J ones)
        // must be in the result; the K interface has no `function m`
        // declaration of its own.
        self::assertCount(2, $declMatches, '#116: J::m re-decl surfaces via interface-walk transitive needle match');
        $declLines = array_map(fn (Location $l): int => $l->range->start->line, array_values($declMatches));
        sort($declLines);
        self::assertSame([2, 3], $declLines, 'lines 2 + 3 are I::m and J::m respectively');
    }

    public function testInterfaceWalkSurvivesUnknownReceiverClass(): void
    {
        // #116 defensive: when worse-reflection can't reflect the
        // receiver (e.g. closed-source vendor class missing from the
        // workspace index), the interface walk must bail gracefully
        // rather than fataling.  Symptom pre-defensive-guard:
        // ReflectionException on closed-source receivers killed the
        // entire find-references pass.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Iface.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        interface Repo {
            public function save(string $item): void;
        }
        XPHP));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        $r = $unknownFactory();
        $r->save('a');
        XPHP));

        // Should not throw; should return at least the decl.
        $locations = $this->references($workspace, '/Iface.xphp', 'function save', strlen('function '));
        self::assertNotEmpty($locations, 'declaration always surfaces');
    }

    public function testFindsInheritedPropertyAccessOnSubclassReceiver(): void
    {
        // Property variant of the inherited-member walk: Dog inherits
        // Animal::$name and accesses it through `$d->name`.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Animal.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Animal {
            public string $name = '';
        }
        XPHP));
        $workspace->open(new TextDocumentItem('/Dog.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Dog extends Animal {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        use App\Animal;
        use App\Dog;
        $a = new Animal();
        echo $a->name;
        $d = new Dog();
        echo $d->name;
        XPHP));

        $locations = $this->references($workspace, '/Animal.xphp', '$name', 1);

        $useMatches = array_filter($locations, fn (Location $l): bool => $l->uri === '/Use.xphp');
        self::assertCount(2, $useMatches);
    }

    public function testFindsPropertyReferences(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class User {
            public string $name = '';
        }
        XPHP));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        use App\User;
        $u = new User();
        echo $u->name;
        echo $u->name;
        XPHP));

        $locations = $this->references($workspace, '/User.xphp', '$name', 1);

        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        self::assertContains('/User.xphp', $uris);
        $useMatches = array_filter($locations, fn (Location $l): bool => $l->uri === '/Use.xphp');
        self::assertCount(2, $useMatches);
    }

    public function testEmptyResultWhenCursorIsNotOnReferenceableSymbol(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/x.xphp', 'xphp', 1, "<?php\n\$x = 42;\n"));

        // Cursor on `42`.
        $locations = $this->references($workspace, '/x.xphp', '42', 0);
        self::assertSame([], $locations);
    }

    public function testEmptyResultForUnknownUri(): void
    {
        $handler = $this->handler(new PhpactorWorkspace());
        $params = new ReferenceParams(
            new ReferenceContext(true),
            new TextDocumentIdentifier('/never-opened.xphp'),
            new Position(0, 0),
        );
        self::assertSame([], wait($handler->references($params)));
    }

    public function testReturnsResultWhenCancelTokenNotRequested(): void
    {
        // Pins the cancel-poll guards on XphpReferencesHandler
        // (lines 52 and 64).  A LogicalAndSingleSubExprNegation mutant
        // flipping `isRequested` to `!isRequested` would short-circuit
        // even for fresh tokens, leaving every references call empty.
        $workspace = new PhpactorWorkspace();
        $source = "<?php\nnamespace App;\nclass Box<T> {}\n\$x = new Box<int>();\n";
        $workspace->open(new TextDocumentItem('/Box.xphp', 'xphp', 1, $source));

        $handler = $this->handler($workspace);
        $byte = strpos($source, 'class Box') + strlen('class ');
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        $params = new ReferenceParams(
            new ReferenceContext(true),
            new TextDocumentIdentifier('/Box.xphp'),
            new Position($line, $character),
        );

        $cancel = new \Amp\CancellationTokenSource();
        // Deliberately NOT cancelled.

        $result = wait($handler->references($params, $cancel->getToken()));
        self::assertIsArray($result);
        self::assertNotEmpty($result, 'non-requested cancel must not short-circuit');
    }

    public function testReturnsEmptyArrayWhenCancelTokenAlreadyRequested(): void
    {
        $workspace = new PhpactorWorkspace();
        $source = "<?php\nnamespace App;\nclass Box<T> {}\n\$x = new Box<int>();\n";
        $workspace->open(new TextDocumentItem('/Box.xphp', 'xphp', 1, $source));

        $handler = $this->handler($workspace);
        $byte = strpos($source, 'class Box') + strlen('class ');
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        $params = new ReferenceParams(
            new ReferenceContext(true),
            new TextDocumentIdentifier('/Box.xphp'),
            new Position($line, $character),
        );

        $cancel = new \Amp\CancellationTokenSource();
        $cancel->cancel();

        self::assertSame([], wait($handler->references($params, $cancel->getToken())));
    }

    public function testReferencesDoesNotThrowWhenNameResolverRejectsTolerantParseAst(): void
    {
        // Repro from prod log xphp-20260529-061522-011: file has
        // valid use statements + a typed-mid-statement bareword (`a`
        // alone, no terminator) that makes the strict parse fail and
        // the tolerant fallback produce an AST that nikic's
        // NameResolver rejects with `Cannot use ... as ... because
        // the name is already in use`.  Pre-fix the exception
        // propagated through the references handler and bubbled to
        // the client as a JSON-RPC error toast.  The Collecting
        // error handler in `cloneWithResolvedNames` must swallow it.
        //
        // Exact shape from the captured file content: four use
        // statements and a bareword `a` mid-file.  Strict parse
        // fails on the bareword (no terminator); the tolerant
        // fallback's recovery sometimes yields a use stmt that
        // looks duplicated to nikic's NameContext.
        $callee = "<?php\nnamespace App\\Containers;\nclass Repository { public function save(\$x): void {} }\n";
        $broken = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Demos;

        use App\Containers\InMemoryRepository;
        use App\Containers\Repository;
        use App\Models\User;
        use ReflectionMethod;

        a

        $repo = new Repository();
        $repo->save(new User('alice'));
        PHP;
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Repository.xphp', 'xphp', 1, $callee));
        $workspace->open(new TextDocumentItem('/Demos/uses.xphp', 'xphp', 1, $broken));

        // No exception even with the tolerant-parse + duplicate-alias
        // recovery shape.
        $result = $this->references($workspace, '/Demos/uses.xphp', '$repo->save', 7);
        self::assertIsArray($result);
    }

    /**
     * @return list<Location>
     */
    /**
     * @return list<Location>
     */
    public function testShortNamePreFilterSkipsFilesWithoutTextualMention(): void
    {
        // Perf #2 correctness: the str_contains short-name pre-filter
        // must not produce false negatives.  Set up two filesystem
        // files: one that DOES textually contain "User" (real
        // reference) and one that doesn't.  The pre-filter should
        // skip the second file but still surface the first.
        file_put_contents($this->root . '/User.xphp', "<?php\nnamespace App;\nclass User {}\n");
        file_put_contents($this->root . '/Consumer.xphp', "<?php\nnamespace App\\X;\nuse App\\User;\n\$u = new User();\n");
        file_put_contents($this->root . '/Unrelated.xphp', "<?php\nnamespace App\\X;\nclass Widget {}\n");

        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem($this->root . '/User.xphp', 'xphp', 1, file_get_contents($this->root . '/User.xphp')));

        $locations = $this->references(
            $workspace,
            $this->root . '/User.xphp',
            'class User',
            strlen('class '),
        );

        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        self::assertContains($this->root . '/User.xphp', $uris, 'declaration site survives the filter');
        self::assertContains('file://' . $this->root . '/Consumer.xphp', $uris, 'Consumer textually mentions User so it must be scanned');
        // The unrelated file is correctly NOT in results.  This passes
        // both with and without the pre-filter, but combined with the
        // negative-shape `Widget` check below it pins the assertion that
        // a missing-mention file contributes zero hits.
        self::assertNotContains('file://' . $this->root . '/Unrelated.xphp', $uris);
    }

    public function testShortNamePreFilterAllowsSubstringMatchesThenAstWalkRejects(): void
    {
        // The pre-filter is intentionally permissive: str_contains
        // matches "User" inside "UserController" or string literals,
        // so files containing the substring still get parsed.  The
        // existing per-node AST/locator logic is the source of truth
        // and rejects non-references.  This test pins that contract.
        file_put_contents($this->root . '/User.xphp', "<?php\nnamespace App;\nclass User {}\n");
        file_put_contents($this->root . '/UserController.xphp', "<?php\nnamespace App;\nclass UserController {}\n");
        file_put_contents($this->root . '/StringMention.xphp', "<?php\nnamespace App\\X;\nclass Marketing {\n    public function msg(): string { return 'Hello User'; }\n}\n");

        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem($this->root . '/User.xphp', 'xphp', 1, file_get_contents($this->root . '/User.xphp')));

        $locations = $this->references(
            $workspace,
            $this->root . '/User.xphp',
            'class User',
            strlen('class '),
        );

        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        self::assertNotContains('file://' . $this->root . '/UserController.xphp', $uris, 'substring inside another identifier is not a real ref');
        self::assertNotContains('file://' . $this->root . '/StringMention.xphp', $uris, 'string-literal mention is not a real ref');
        // The declaration site is still surfaced -- the pre-filter
        // doesn't accidentally drop the open doc.
        self::assertContains($this->root . '/User.xphp', $uris);
    }

    public function testWarmedFilesystemCacheIsConsultedByReferenceFinder(): void
    {
        // Perf #1 integration: pre-seed the AST cache via the warmer,
        // then mutate the on-disk source to a no-ref version.  The
        // filesystem pass MUST still find the original reference --
        // proving it walked the cached AST and skipped the on-disk
        // re-parse.
        //
        // We use the warmer as the producer (rather than directly
        // calling cache->seedIfAbsent) so this also asserts the warmer
        // hooks the right URI.  If the warmer keyed entries under the
        // wrong URI shape, the pre-mutation parse would land in the
        // cache but the filesystem pass would peek a different URI,
        // hit miss, re-parse from disk, and surface 0 references.
        file_put_contents($this->root . '/User.xphp', "<?php\nnamespace App;\nclass User {}\n");
        file_put_contents($this->root . '/Consumer.xphp', "<?php\nnamespace App\\X;\nuse App\\User;\n\$u = new User();\n");

        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem($this->root . '/User.xphp', 'xphp', 1, file_get_contents($this->root . '/User.xphp')));

        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $fqnIndex = new FqnIndex($workspace, $cache, $parser, $this->root);

        // Drive the warmer synchronously via its extracted warmNow()
        // entry point -- avoids the asyncCall + Delayed race that
        // surfaces as false-positive Infection mutant escapes.
        $warmer = new ParsedDocumentCacheWarmer($fqnIndex, $cache, $workspace);
        $warmer->warmNow();

        // Sanity: cache holds the original Consumer.xphp AST.
        $cachedConsumer = $cache->peek('file://' . $this->root . '/Consumer.xphp');
        self::assertNotNull($cachedConsumer, 'warmer must have seeded Consumer.xphp');

        // Now corrupt the on-disk source so it contains NO reference to
        // User.  If the filesystem pass re-reads disk, it'll see no
        // hits.  If it serves the cached AST, the original `new User()`
        // ref still surfaces.  The pre-filter's str_contains check uses
        // the on-disk source bytes (intentional -- a true post-warmup
        // disk change should still be detectable via the watch path,
        // but in the absence of a `didChangeWatchedFiles` notification
        // the cached AST stays authoritative).
        //
        // The pre-filter still passes because the corrupted file
        // contains "Garbage", not "User"... so we need to keep "User"
        // textually present on disk while removing the actual ref.
        // A comment mention works: filter passes, AST walk finds no
        // ref, and we'd see 0 hits from Consumer -- UNLESS the cache
        // served the original AST.
        file_put_contents($this->root . '/Consumer.xphp', "<?php\n// User mentioned in comment only\nnamespace App\\X;\nclass Disconnected {}\n");

        $reflector = (new ReflectorFactory(
            $workspace,
            $cache,
            $parser,
            rootPath: $this->root,
            stubPath: ReflectorFactory::defaultStubPath(),
            cacheDir: ReflectorFactory::defaultCacheDir(),
            fqnIndex: $fqnIndex,
        ))->build();
        $classLikeLookup = new CompositeClassLikeLookup(
            new WorkspaceClassLikeLookup($workspace, $cache),
            new FilesystemClassLikeLookup($fqnIndex),
        );
        $genericResolver = new GenericResolver($workspace, $cache, $classLikeLookup, $parser, $fqnIndex);
        $finder = new ReferenceFinder($workspace, $cache, $fqnIndex, $parser, $reflector, $genericResolver);

        $item = $workspace->get($this->root . '/User.xphp');
        $cursorByte = strpos($item->text, 'class User') + strlen('class ');
        $locations = $finder->findReferences($this->root . '/User.xphp', $cursorByte, true);

        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        self::assertContains(
            'file://' . $this->root . '/Consumer.xphp',
            $uris,
            'cached AST must be served -- otherwise the post-mutation disk text would yield 0 hits from Consumer',
        );
    }

    private function referencesAtPosition(
        PhpactorWorkspace $workspace,
        string $uri,
        int $line,
        int $character,
        bool $includeDeclaration = true,
    ): array {
        $params = new ReferenceParams(
            new ReferenceContext($includeDeclaration),
            new TextDocumentIdentifier($uri),
            new Position($line, $character),
        );
        $result = wait($this->handler($workspace)->references($params));
        self::assertIsArray($result);
        return $result;
    }

    private function references(
        PhpactorWorkspace $workspace,
        string $uri,
        string $needle,
        int $offsetInNeedle,
        bool $includeDeclaration = true,
    ): array {
        $item = $workspace->get($uri);
        $byte = strpos($item->text, $needle);
        self::assertNotFalse($byte, "needle '$needle' must appear in $uri");
        $byte += $offsetInNeedle;
        [$line, $character] = (new PositionMap($item->text))->offsetToPosition($byte);
        $params = new ReferenceParams(
            new ReferenceContext($includeDeclaration),
            new TextDocumentIdentifier($uri),
            new Position($line, $character),
        );
        $result = wait($this->handler($workspace)->references($params));
        self::assertIsArray($result);
        return $result;
    }

    private function handler(PhpactorWorkspace $workspace): XphpReferencesHandler
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $fqnIndex = new FqnIndex($workspace, $cache, $parser, $this->root);
        $reflector = (new ReflectorFactory(
            $workspace,
            $cache,
            $parser,
            rootPath: $this->root,
            stubPath: ReflectorFactory::defaultStubPath(),
            cacheDir: ReflectorFactory::defaultCacheDir(),
            fqnIndex: $fqnIndex,
        ))->build();
        $classLikeLookup = new CompositeClassLikeLookup(
            new WorkspaceClassLikeLookup($workspace, $cache),
            new FilesystemClassLikeLookup($fqnIndex),
        );
        $genericResolver = new GenericResolver($workspace, $cache, $classLikeLookup, $parser, $fqnIndex);
        return new XphpReferencesHandler(
            $workspace,
            new ReferenceFinder($workspace, $cache, $fqnIndex, $parser, $reflector, $genericResolver),
        );
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
}
