<?php

declare (strict_types=1);
namespace XPHP\Generated\App\VarianceCovariantHappy\Containers\Producer;

// Covariant T -- T only appears in output position (method return type).
// PHP enforces invariant property types across `extends` chains, so a
// property of type T (even readonly) would PHP-fatal at autoload when the
// variance edge lands. Users who need a "covariant field" use a
// bound-typed backing field + a method `get(): T`.
//
// With Banana <: Fruit, the emitter adds
// `Producer_Banana extends Producer_Fruit` (Class_ specializations get a
// single `extends`, since PHP allows only one class inheritance).
class T_80ebeafc8c50bc80984d05a96eccc168304b57c269f69fc1ead6d213dd140a55 extends \XPHP\Generated\App\VarianceCovariantHappy\Containers\Producer\T_731ca7691b0cb658b13f14147a49e4b8ceb18a8dffd87d1bf154ced4eca7b208 implements \App\VarianceCovariantHappy\Containers\Producer
{
    private mixed $item = null;
    public function get(): \App\VarianceCovariantHappy\Models\Banana
    {
        return $this->item;
    }
}
