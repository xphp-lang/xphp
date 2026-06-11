<?php

declare (strict_types=1);
namespace XPHP\Generated\App\MultiType\Containers\Pair;

class T_2416490cfe14dc42d55eafc53fd5a1b11bc4b818926a1367f98cddc3ec00f270 implements \App\MultiType\Containers\Pair
{
    public function __construct(public \App\MultiType\Models\User $first, public \App\MultiType\Models\Plastic $second)
    {
    }
    public function swap(): \XPHP\Generated\App\MultiType\Containers\Pair\T_1e99058ff225e3ae120b0290ab86c11f55d045b69371fe5e0c03134472215db5
    {
        return new \XPHP\Generated\App\MultiType\Containers\Pair\T_1e99058ff225e3ae120b0290ab86c11f55d045b69371fe5e0c03134472215db5($this->second, $this->first);
    }
}
