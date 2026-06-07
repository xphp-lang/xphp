<?php

declare (strict_types=1);
namespace XPHP\Generated\App\VarianceContravariantHappy\Containers\Consumer;

// Contravariant T -- T only appears in input position (method parameter).
// With Dog <: Animal, the emitter adds the FLIPPED edge:
// Consumer_Animal extends Consumer_Dog (so anywhere a Consumer<Dog> is
// expected, a Consumer<Animal> works -- it accepts a wider input).
class T_08cb07e08df2b92d4dd2782e87a46b413d0aecbf22cfa525b21e1ecbae4199b4 extends \XPHP\Generated\App\VarianceContravariantHappy\Containers\Consumer\T_7c7a56018a9ae48cac9a5e4175d8123f7f7a3b2ec60de16fd981da5d3a7e3d29 implements \App\VarianceContravariantHappy\Containers\Consumer
{
    public function consume(\App\VarianceContravariantHappy\Models\Animal $value): void
    {
    }
}
