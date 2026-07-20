<?php

declare (strict_types=1);
namespace XPHP\Generated\App\NestedInstantiation\Containers\Collection;

class T_72cd9917ee1636a336e65540a284a68071f850278ebd78f2f765b25f9e969418 implements \App\NestedInstantiation\Containers\Collection
{
    /** @var array<int, T> */
    public array $items = [];
    public function push(\App\NestedInstantiation\Models\Plastic $val): void
    {
        $this->items[] = $val;
    }
    public function first(): \App\NestedInstantiation\Models\Plastic
    {
        return $this->items[0];
    }
}