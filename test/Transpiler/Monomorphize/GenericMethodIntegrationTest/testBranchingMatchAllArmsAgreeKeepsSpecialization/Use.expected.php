<?php

declare (strict_types=1);
namespace App\BrMtch;

$n = mt_rand(0, 5);
match (true) {
    $n === 1 => $x = new Foo(),
    $n === 2 => $x = new Foo(),
    default => $x = new Foo(),
};
$r = $x->fooId_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(18);
