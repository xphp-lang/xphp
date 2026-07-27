<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_class_method_turbofish_discovery`: an instantiation that
 * only exists inside a grounding-appended member (`new Pair<int>` inside `twin_T_<hash>`)
 * was collected into the fixed point — the Pair<int> specialization exists, loads, and
 * carries the forwarded values.
 */

use PHPUnit\Framework\Assert;
use XPHP\Transpiler\Monomorphize\Registry;
use XPHP\Transpiler\Monomorphize\TypeRef;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    $pairIntFqn = Registry::generatedFqn(
        'App\\TurbofishDiscovery\\Pair',
        [new TypeRef('int', isScalar: true)],
    );
    Assert::assertInstanceOf($pairIntFqn, $p);
    Assert::assertSame(9, $p->first);
    Assert::assertSame(9, $p->second);
};
