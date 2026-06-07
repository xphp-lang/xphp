<?php

declare (strict_types=1);
namespace App\BrUnt;

if (mt_rand(0, 1)) {
    $x = new Foo();
} else {
    $x = computeFoo();
}
$r = $x->fooId(17);
