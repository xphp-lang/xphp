<?php

declare(strict_types=1);

/**
 * Runtime verify for `inferred_call_arguments`: bare (turbofish-less) generic calls whose type
 * arguments are determined by the call arguments are inferred and dispatch to real specializations,
 * and the emitted program executes end to end.
 *
 * Covers free-function, static-method, and instance-method inference, with arguments typed from a
 * scalar literal, a `new` expression, a class-typed property, and a class-typed parameter.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`. Free
 * functions aren't autoloadable in PHP, so require the emitted Use.php explicitly to bring the
 * specialized declarations into scope; its top-level driver statements run too.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    // The user files aren't PSR-4 (a file can hold several classes, and free functions aren't
    // autoloadable at all), so require them in dependency order before the driver. The generated
    // Box specialization is autoloaded.
    require $fixture->targetDir . '/Models.php';
    require $fixture->targetDir . '/Lib.php';
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame(5, $intId, 'identity(5) inferred T=int and returned the value');
    Assert::assertInstanceOf('App\\Inference\\Plastic', $objId, 'identity(new Plastic()) inferred T=Plastic');
    Assert::assertSame('hi', $made, 'Factory::make(\'hi\') inferred T=string');
    Assert::assertSame(9, $dupped, '$box->dup(9) inferred U=int');
    Assert::assertInstanceOf('App\\Inference\\Plastic', $viaProp, 'wrap($this->p) inferred T=Plastic from the property type');
    Assert::assertInstanceOf('App\\Inference\\Plastic', $viaParam, 'wrap($q) inferred T=Plastic from the parameter type');
};
