<?php

declare (strict_types=1);
namespace App\ClosureLeak;

$x = new Foo();
$cb = function (): void {
    $x = new Bar();
    $inner = $x->barId_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(11);
};
$cb();
$outer = $x->fooId_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(22);
