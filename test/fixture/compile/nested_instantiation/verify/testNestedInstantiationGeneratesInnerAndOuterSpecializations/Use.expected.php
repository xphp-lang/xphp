<?php

declare (strict_types=1);
namespace App\NestedInstantiation;

use App\NestedInstantiation\Containers\Box;
use App\NestedInstantiation\Containers\Lst;
use App\NestedInstantiation\Models\Plastic;
$boxOfList = new \XPHP\Generated\App\NestedInstantiation\Containers\Box\T_97c6bd3a59f7a82e44580814227a9520bc2d6551991c8263366d2d9c44f7627f();
$inner = new \XPHP\Generated\App\NestedInstantiation\Containers\Lst\T_72cd9917ee1636a336e65540a284a68071f850278ebd78f2f765b25f9e969418();
$inner->push(new Plastic('red'));
$boxOfList->set($inner);
