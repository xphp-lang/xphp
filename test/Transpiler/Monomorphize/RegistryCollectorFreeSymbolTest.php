<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * The definitions pass records free functions and free constants defined in the compilation
 * unit (namespace-qualified) so the Specializer can re-qualify unqualified calls/fetches in a
 * relocated template body only when the target is known. Class methods and class constants are
 * different node types and must NOT be collected as free symbols.
 */
final class RegistryCollectorFreeSymbolTest extends TestCase
{
    public function testFreeFunctionsAndConstsAreCollectedButClassMembersAreNot(): void
    {
        $registry = new Registry();
        $collector = new RegistryCollector($registry);

        $ast = (new ParserFactory())->createForHostVersion()->parse(<<<'PHP'
            <?php
            namespace App;
            function helper(int $x): int { return $x; }
            const FACTOR = 10, SCALE = 2;
            class Box {
                const NOT_FREE = 1;
                public function method(): void {}
            }
            PHP);
        self::assertNotNull($ast);
        $collector->collectDefinitions($ast, 'test.xphp');

        self::assertTrue($registry->hasFunction('App\\helper'));
        self::assertTrue($registry->hasConst('App\\FACTOR'));
        self::assertTrue($registry->hasConst('App\\SCALE'), 'every const in a multi-const declaration');

        // A class method is not a free function; a class constant is not a free constant.
        self::assertFalse($registry->hasFunction('App\\method'));
        self::assertFalse($registry->hasConst('App\\NOT_FREE'));
        self::assertFalse($registry->hasConst('App\\Box\\NOT_FREE'));
    }

    public function testGlobalNamespaceFreeSymbolsAreCollectedWithoutPrefix(): void
    {
        $registry = new Registry();
        $collector = new RegistryCollector($registry);

        $ast = (new ParserFactory())->createForHostVersion()->parse(<<<'PHP'
            <?php
            function toplevel(): void {}
            const TOP = 1;
            PHP);
        self::assertNotNull($ast);
        $collector->collectDefinitions($ast, 'test.xphp');

        self::assertTrue($registry->hasFunction('toplevel'));
        self::assertTrue($registry->hasConst('TOP'));
        self::assertFalse($registry->hasFunction('App\\toplevel'));
    }
}
