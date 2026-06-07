<?php

declare(strict_types=1);

/**
 * Runtime verify for `nested_typehint`: the Wrapper specialization's
 * `box` property reflects the specialized Box<Plastic> as its concrete
 * type, and calling `setBoxed('not a plastic')` raises a TypeError on
 * the substituted parameter signature.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope, autoload
 * registered for `App\NestedTypehint\` + the generated namespace.
 */

use PHPUnit\Framework\Assert;
use XPHP\Transpiler\Monomorphize\Registry;
use XPHP\Transpiler\Monomorphize\TypeRef;

$plastic = new TypeRef('App\\NestedTypehint\\Models\\Plastic');
$boxFqn = Registry::generatedFqn('App\\NestedTypehint\\Containers\\Box', [$plastic]);
$wrapperFqn = Registry::generatedFqn('App\\NestedTypehint\\Containers\\Wrapper', [$plastic]);

// Reflection: Wrapper's $box property is typed against the specialized Box<Plastic>.
$propType = (new \ReflectionProperty($wrapperFqn, 'box'))->getType();
Assert::assertInstanceOf(\ReflectionNamedType::class, $propType);
Assert::assertSame($boxFqn, $propType->getName());

// Constructor must succeed independently -- otherwise the next
// catch block would falsely attribute its TypeError to setBoxed.
$w = new $wrapperFqn();
Assert::assertInstanceOf($wrapperFqn, $w);

// TypeError on substituted parameter: setBoxed expects the concrete Plastic,
// not an arbitrary string.
$caught = null;
try {
    $w->setBoxed('not a plastic');
} catch (\TypeError $e) {
    $caught = $e;
}
Assert::assertInstanceOf(\TypeError::class, $caught);
