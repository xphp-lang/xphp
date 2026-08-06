<?php

declare(strict_types=1);

/**
 * Runtime verify for `inferred_new_arguments`: bare (turbofish-less) `new X(...)` whose type
 * arguments are determined by the constructor arguments are inferred and dispatch to real
 * specializations, and the emitted program executes end to end.
 *
 * Covers a scalar-literal argument, a `new` argument, a class-typed property (`$this->p`), and a
 * class-typed parameter.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`. The user
 * files aren't PSR-4, so require them in dependency order; the generated Box specializations are
 * autoloaded.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Models.php';
    require $fixture->targetDir . '/Lib.php';
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame(5, $intVal, 'new Box(5) inferred T=int');
    Assert::assertInstanceOf('App\\NewInference\\Plastic', $objVal, 'new Box(new Plastic()) inferred T=Plastic');
    Assert::assertInstanceOf('App\\NewInference\\Plastic', $fromProp, 'new Box($this->p) inferred T=Plastic from the property type');
    Assert::assertInstanceOf('App\\NewInference\\Plastic', $fromParam, 'new Box($q) inferred T=Plastic from the parameter type');
};
