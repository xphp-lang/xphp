<?php

declare (strict_types=1);
namespace App\BrPost;

$x = new Foo();
if (mt_rand(0, 1)) {
    $x = new Bar();
}
$r = $x->fooId(7);
