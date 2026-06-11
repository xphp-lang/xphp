<?php

declare (strict_types=1);
namespace XPHP\Generated\App\NestedTypehint\Containers\Wrapper;

class T_b36506c8bdfa2dfbb76653613afe34e9a2f2a3adbc24340423607338693be70f implements \App\NestedTypehint\Containers\Wrapper
{
    public \XPHP\Generated\App\NestedTypehint\Containers\Box\T_b36506c8bdfa2dfbb76653613afe34e9a2f2a3adbc24340423607338693be70f $box;
    public function __construct()
    {
        $this->box = new \XPHP\Generated\App\NestedTypehint\Containers\Box\T_b36506c8bdfa2dfbb76653613afe34e9a2f2a3adbc24340423607338693be70f();
    }
    public function setBoxed(\App\NestedTypehint\Models\Plastic $val): void
    {
        $this->box->set($val);
    }
    public function getBoxed(): \App\NestedTypehint\Models\Plastic
    {
        return $this->box->get();
    }
}
