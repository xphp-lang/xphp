<?php

declare (strict_types=1);
namespace XPHP\Generated\App\MultiType\Containers\Pair;

class T_499139514b505bda84f90b31f0cb21e7a7b36cd7a81d3ca08970f4e5a9e1c4b6 implements \App\MultiType\Containers\Pair
{
    public function __construct(public \XPHP\Generated\App\MultiType\Containers\Map\T_ddc02d7f9d24d965819939d5b07227efa95da520d87d6728a4c41906ecce5bca $first, public \XPHP\Generated\App\MultiType\Containers\Pair\T_1e99058ff225e3ae120b0290ab86c11f55d045b69371fe5e0c03134472215db5 $second)
    {
    }
    public function swap(): \XPHP\Generated\App\MultiType\Containers\Pair\T_925ebf92edf2a8ef8ebd04796484a3af7e5987ae3c7433f425be3e6302497a6d
    {
        return new \XPHP\Generated\App\MultiType\Containers\Pair\T_925ebf92edf2a8ef8ebd04796484a3af7e5987ae3c7433f425be3e6302497a6d($this->second, $this->first);
    }
}
