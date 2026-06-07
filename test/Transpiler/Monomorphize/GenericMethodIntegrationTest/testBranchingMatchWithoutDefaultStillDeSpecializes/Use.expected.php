<?php

declare (strict_types=1);
namespace App\BrMtchNoD;

$x = new Foo();
$n = mt_rand(0, 5);
match (true) {
    $n === 1 => $x = new Foo(),
    $n === 2 => $x = new Foo(),
};
$r = $x->fooId(19);
