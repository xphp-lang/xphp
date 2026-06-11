<?php

declare (strict_types=1);
namespace App\BrNested;

if (mt_rand(0, 1)) {
    if (mt_rand(0, 1)) {
        $x = new Foo();
    } else {
        $x = new Foo();
    }
} else {
    $x = new Foo();
}
$r = $x->fooId_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(16);
