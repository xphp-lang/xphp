<?php

declare (strict_types=1);
namespace XPHP\Generated\App\BoxGeneric\Containers\Box;

class T_a999dea8b8a7aff22813b285998be1bb329f1e3e7679044afc7c2707c6585b7d implements \App\BoxGeneric\Containers\Box
{
    public \App\BoxGeneric\Models\Plastic $item;
    public function set(\App\BoxGeneric\Models\Plastic $val): void
    {
        $this->item = $val;
    }
    public function get(): \App\BoxGeneric\Models\Plastic
    {
        return $this->item;
    }
}
