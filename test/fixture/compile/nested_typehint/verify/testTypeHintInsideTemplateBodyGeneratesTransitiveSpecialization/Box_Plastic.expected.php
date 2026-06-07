<?php

declare (strict_types=1);
namespace XPHP\Generated\App\NestedTypehint\Containers\Box;

class T_b36506c8bdfa2dfbb76653613afe34e9a2f2a3adbc24340423607338693be70f implements \App\NestedTypehint\Containers\Box
{
    public \App\NestedTypehint\Models\Plastic $item;
    public function set(\App\NestedTypehint\Models\Plastic $val): void
    {
        $this->item = $val;
    }
    public function get(): \App\NestedTypehint\Models\Plastic
    {
        return $this->item;
    }
}
