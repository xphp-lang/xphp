<?php

declare (strict_types=1);
namespace App\ArrowReservedTagCapture;

// Capture name `__xphp_tag` collides with the dispatcher's tag param.
// The dispatcher auto-renames its tag param to a collision-free
// alternative; the user's `$__xphp_tag` keeps its name. Body adds 100
// to the typed argument, yielding 105.
$__xphp_tag = 100;
$f = function (string $__xphp_tag_02e6295d, mixed ...$__xphp_args_02e6295d) use ($__xphp_tag): mixed {
    return match ($__xphp_tag_02e6295d) {
        'T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8' => \App\ArrowReservedTagCapture\closure_f_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(...$__xphp_args_02e6295d, __xphp_tag: $__xphp_tag),
        default => throw new \RuntimeException('Unknown generic specialization tag: ' . $__xphp_tag_02e6295d),
    };
};
$result = $f('T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8', 5);
function closure_f_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(int $x, mixed $__xphp_tag): int
{
    return $x + $__xphp_tag;
}
