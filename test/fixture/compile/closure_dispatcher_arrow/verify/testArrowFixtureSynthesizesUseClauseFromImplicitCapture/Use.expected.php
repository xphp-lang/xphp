<?php

declare (strict_types=1);
namespace App\ClosureDispatcherArrow;

// Generic arrow with implicit capture. The arrow body references `$y`
// from the outer scope; xphp synthesizes the corresponding `use (...)`
// clause on the rewritten dispatcher closure so the capture happens
// by-value at the original assign site -- matching PHP's normal arrow
// semantics. The post-call mutation of $y proves the capture was a
// snapshot, not an alias.
$y = 1;
$id = function (string $__xphp_tag, mixed ...$__xphp_args) use ($y): mixed {
    return match ($__xphp_tag) {
        'T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8' => \App\ClosureDispatcherArrow\closure_id_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(...$__xphp_args, y: $y),
        default => throw new \RuntimeException('Unknown generic specialization tag: ' . $__xphp_tag),
    };
};
$y = 2;
$resultArrow = $id('T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8', 42);
// expect 43 (uses captured $y = 1).
function closure_id_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(int $x, mixed $y): int
{
    return $x + $y;
}
