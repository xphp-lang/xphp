<?php

declare (strict_types=1);
namespace XPHP\Generated\App\MultiType\Containers\Pair;

class T_a6d956ca85f7e18cb42724d568caa8fd07c28cd06644b51acfd72e024368f5d9 implements \App\MultiType\Containers\Pair
{
    public function __construct(public \App\MultiType\Models\Plastic $first, public \App\MultiType\Models\Plastic $second)
    {
    }
    public function swap(): \XPHP\Generated\App\MultiType\Containers\Pair\T_a6d956ca85f7e18cb42724d568caa8fd07c28cd06644b51acfd72e024368f5d9
    {
        return new \XPHP\Generated\App\MultiType\Containers\Pair\T_a6d956ca85f7e18cb42724d568caa8fd07c28cd06644b51acfd72e024368f5d9($this->second, $this->first);
    }
}
