<?php

declare (strict_types=1);
namespace XPHP\Generated\App\GenericInterface\Containers\Box;

class T_fe07d34f5b171a2efb322ee140a97c55360d902257948f7d554e66caf2f257e3 implements \XPHP\Generated\App\GenericInterface\Containers\Container\T_fe07d34f5b171a2efb322ee140a97c55360d902257948f7d554e66caf2f257e3, \App\GenericInterface\Containers\Box
{
    public function __construct(public \App\GenericInterface\Models\Plastic $item)
    {
    }
    public function get(): \App\GenericInterface\Models\Plastic
    {
        return $this->item;
    }
}
