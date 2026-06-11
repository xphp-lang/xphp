<?php

declare (strict_types=1);
namespace App\ArrowReservedArgsCapture;

// Capture name `__xphp_args` collides with the dispatcher's variadic
// param. The dispatcher's args param auto-renames to a collision-free
// alternative; the user's `$__xphp_args` keeps its name. Body adds 200
// to the typed argument, yielding 203.
$__xphp_args = 200;
$f = function (string $__xphp_tag_9a72c24f, mixed ...$__xphp_args_9a72c24f) use ($__xphp_args): mixed {
    return match ($__xphp_tag_9a72c24f) {
        'T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8' => \App\ArrowReservedArgsCapture\closure_f_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(...$__xphp_args_9a72c24f, __xphp_args: $__xphp_args),
        default => throw new \RuntimeException('Unknown generic specialization tag: ' . $__xphp_tag_9a72c24f),
    };
};
$result = $f('T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8', 3);
function closure_f_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(int $x, mixed $__xphp_args): int
{
    return $x + $__xphp_args;
}
