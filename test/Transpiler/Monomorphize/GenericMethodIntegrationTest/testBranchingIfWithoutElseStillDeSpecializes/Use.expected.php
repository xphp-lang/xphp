<?php

declare (strict_types=1);
namespace App\BrNoElse;

$x = new Foo();
if (mt_rand(0, 1)) {
    $x = new Foo();
}
$r = $x->fooId(12);
