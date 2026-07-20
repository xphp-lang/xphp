<?php

declare (strict_types=1);
namespace XPHP\Generated\App\NestedInstantiation\Containers\Box;

class T_b161c2856b3c6debba523ecb74e8cefe279a8707898b647ece965cdeb420deb2 implements \App\NestedInstantiation\Containers\Box
{
    public \XPHP\Generated\App\NestedInstantiation\Containers\Collection\T_72cd9917ee1636a336e65540a284a68071f850278ebd78f2f765b25f9e969418 $item;
    public function set(\XPHP\Generated\App\NestedInstantiation\Containers\Collection\T_72cd9917ee1636a336e65540a284a68071f850278ebd78f2f765b25f9e969418 $val): void
    {
        $this->item = $val;
    }
    public function get(): \XPHP\Generated\App\NestedInstantiation\Containers\Collection\T_72cd9917ee1636a336e65540a284a68071f850278ebd78f2f765b25f9e969418
    {
        return $this->item;
    }
}