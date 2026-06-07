<?php

declare (strict_types=1);
namespace App\BrSwNoD;

$x = new Foo();
$n = mt_rand(0, 5);
switch ($n) {
    case 1:
        $x = new Foo();
        break;
    case 2:
        $x = new Foo();
        break;
}
$r = $x->fooId(15);
