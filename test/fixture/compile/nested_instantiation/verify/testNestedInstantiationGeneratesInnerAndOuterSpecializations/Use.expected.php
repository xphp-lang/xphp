<?php

declare (strict_types=1);
namespace App\NestedInstantiation;

use App\NestedInstantiation\Containers\Box;
use App\NestedInstantiation\Containers\Collection;
use App\NestedInstantiation\Models\Plastic;
$boxOfList = new \XPHP\Generated\App\NestedInstantiation\Containers\Box\T_b161c2856b3c6debba523ecb74e8cefe279a8707898b647ece965cdeb420deb2();
$inner = new \XPHP\Generated\App\NestedInstantiation\Containers\Collection\T_72cd9917ee1636a336e65540a284a68071f850278ebd78f2f765b25f9e969418();
$inner->push(new Plastic('red'));
$boxOfList->set($inner);