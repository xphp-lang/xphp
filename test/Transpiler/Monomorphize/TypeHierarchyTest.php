<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class TypeHierarchyTest extends TestCase
{
    public function testEqualTypesAreSubtypes(): void
    {
        $hierarchy = new TypeHierarchy([]);
        self::assertTrue($hierarchy->isSubtype('App\\Foo', 'App\\Foo'));
    }

    public function testBuiltinInterfaceIsItsOwnSubtype(): void
    {
        $hierarchy = new TypeHierarchy([]);
        self::assertTrue($hierarchy->isSubtype('Stringable', 'Stringable'));
    }

    public function testUnknownConcreteTypeReturnsNull(): void
    {
        // Reject-by-uncertainty: caller can't prove SomeRandomClass satisfies anything because
        // it's not in the hierarchy and not a known PHP built-in.
        $hierarchy = new TypeHierarchy([]);
        self::assertNull($hierarchy->isSubtype('SomeRandomClass', 'Stringable'));
    }

    public function testScalarConcreteReturnsFalseAgainstAnyClassBound(): void
    {
        $hierarchy = new TypeHierarchy([]);
        self::assertFalse($hierarchy->isSubtype('int', 'Stringable'));
    }

    public function testTransitiveImplementsIsRecognised(): void
    {
        // App\User implements App\Speakable; App\Speakable extends \Stringable.
        $hierarchy = new TypeHierarchy([
            'App\\User' => ['App\\Speakable'],
            'App\\Speakable' => ['Stringable'],
        ]);
        self::assertTrue($hierarchy->isSubtype('App\\User', 'Stringable'));
    }

    public function testKnownConcreteWithoutMatchingAncestorReturnsFalse(): void
    {
        $hierarchy = new TypeHierarchy([
            'App\\User' => ['App\\Mortal'],
        ]);
        self::assertFalse(
            $hierarchy->isSubtype('App\\User', 'Stringable'),
            'User extends Mortal only — does NOT implement Stringable',
        );
    }

    public function testCycleInHierarchyDoesNotInfiniteLoop(): void
    {
        // Pathological inputs shouldn't hang the BFS.
        $hierarchy = new TypeHierarchy([
            'A' => ['B'],
            'B' => ['A'],
        ]);
        // Walk visits {A, B}. Neither matches 'Stringable' so we return false.
        self::assertFalse($hierarchy->isSubtype('A', 'Stringable'));
    }

    public function testBfsContinuesPastAlreadyVisitedSiblingsToReachTheBound(): void
    {
        // Locks `continue` (vs `break`) inside the visited-check inside the BFS:
        //   A -> [B, C]; B -> [A]; C -> [Stringable]
        // BFS: visit A, push B+C. Visit B, push A. Visit C, push Stringable.
        // Queue is now [A, Stringable]. A is already visited — the loop must
        // continue (skip A) so it can later visit Stringable and report success.
        // Under a `break` mutation the loop would exit and we'd return false.
        $hierarchy = new TypeHierarchy([
            'A' => ['B', 'C'],
            'B' => ['A'],
            'C' => ['Stringable'],
        ]);
        self::assertTrue($hierarchy->isSubtype('A', 'Stringable'));
    }

    public function testBfsTraversesIntermediateNodesNotInAncestorMap(): void
    {
        // Locks the `$this->ancestors[$cur] ?? []` coalesce inside the BFS body.
        // App\\User points at ExternalClass — which the hierarchy doesn't have a row
        // for. Without the `?? []` guard, dereferencing the missing key during the
        // ancestor fan-out would emit an "undefined array key" warning (and phpunit's
        // failOnWarning would catch the regression).
        $hierarchy = new TypeHierarchy([
            'App\\User' => ['ExternalClass'],
        ]);
        self::assertFalse(
            $hierarchy->isSubtype('App\\User', 'Stringable'),
            'BFS must traverse ExternalClass cleanly even though it has no ancestors row',
        );
    }

    public function testLeadingBackslashIsNormalised(): void
    {
        $hierarchy = new TypeHierarchy([
            'App\\Bar' => ['Stringable'],
        ]);
        self::assertTrue($hierarchy->isSubtype('\\App\\Bar', '\\Stringable'));
    }

    public function testFromAstPerFileCollectsClassExtendsImplements(): void
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $src = <<<'PHP'
<?php
namespace App;

use App\Contracts\Speakable;

class User extends Person implements Speakable, \Stringable
{
}

class Person
{
}
PHP;
        $ast = $parser->parse($src);
        self::assertNotNull($ast);

        $hierarchy = TypeHierarchy::fromAstPerFile(['/x.php' => $ast]);

        self::assertTrue($hierarchy->isSubtype('App\\User', 'App\\Person'), 'extends must be picked up');
        self::assertTrue($hierarchy->isSubtype('App\\User', 'App\\Contracts\\Speakable'), 'use-aliased implements must resolve to the FQN');
        self::assertTrue($hierarchy->isSubtype('App\\User', 'Stringable'), 'fully-qualified built-in implements must skip the namespace prefix');
    }

    public function testFromAstPerFileCollectsInterfaceExtends(): void
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $src = <<<'PHP'
<?php
namespace App;

interface Speakable extends \Stringable
{
}
PHP;
        $ast = $parser->parse($src);
        $hierarchy = TypeHierarchy::fromAstPerFile(['/x.php' => $ast]);

        self::assertTrue($hierarchy->isSubtype('App\\Speakable', 'Stringable'));
    }

    public function testUseStatementWithExplicitAliasResolvesAncestorsViaTheAliasKey(): void
    {
        // Locks the Coalesce on `$u->alias?->toString() ?? self::lastSegment($fqn)` inside
        // the AST visitor's indexUses. Without using the alias as the useMap key, the resolver
        // would look up by `Speakable` (last segment) and miss when the source uses `Talker`.
        $parser = (new ParserFactory())->createForHostVersion();
        $src = <<<'PHP'
<?php
namespace App;

use App\Contracts\Speakable as Talker;

class User implements Talker
{
}
PHP;
        $ast = $parser->parse($src);
        $hierarchy = TypeHierarchy::fromAstPerFile(['/x.php' => $ast]);

        self::assertTrue($hierarchy->isSubtype('App\\User', 'App\\Contracts\\Speakable'));
    }

    public function testBuiltinInterfaceImplementedWithoutLeadingBackslashStillResolvesToGlobal(): void
    {
        // Locks the "is this a known built-in?" branch of resolveName inside the AST collector —
        // without it, `implements Stringable` (no leading \) would qualify to `App\Stringable`
        // and the subtype check against the real `Stringable` would miss.
        $parser = (new ParserFactory())->createForHostVersion();
        $src = <<<'PHP'
<?php
namespace App;

class Tag implements Stringable
{
}
PHP;
        $ast = $parser->parse($src);
        $hierarchy = TypeHierarchy::fromAstPerFile(['/x.php' => $ast]);

        self::assertTrue($hierarchy->isSubtype('App\\Tag', 'Stringable'));
    }
}
