<?php

declare (strict_types=1);
namespace App\ArrowLeak;

$x = new Foo();
// Arrow function with its own typed parameter `$x`. After the arrow
// body finishes evaluating, the outer `$x` must still be Foo.
$double = fn(int $x): int => $x * 2;
$r = $double(21);
$outer = $x->fooId_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(7);
