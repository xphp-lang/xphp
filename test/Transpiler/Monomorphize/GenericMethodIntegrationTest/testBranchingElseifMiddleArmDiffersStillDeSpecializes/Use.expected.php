<?php

declare (strict_types=1);
namespace App\BrElsMid;

$n = mt_rand(0, 2);
if ($n === 0) {
    $x = new Foo();
} elseif ($n === 1) {
    $x = new Bar();
} else {
    $x = new Foo();
}
$r = $x->fooId(20);
