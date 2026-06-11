<?php

declare (strict_types=1);
namespace App\ArrowMultipleArgTuples;

// Two distinct turbofish call sites produce two specializations.
$f = function (string $__xphp_tag, mixed ...$__xphp_args): mixed {
    return match ($__xphp_tag) {
        'T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8' => \App\ArrowMultipleArgTuples\closure_f_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(...$__xphp_args),
        'T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8' => \App\ArrowMultipleArgTuples\closure_f_T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8(...$__xphp_args),
        default => throw new \RuntimeException('Unknown generic specialization tag: ' . $__xphp_tag),
    };
};
$a = $f('T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8', 10);
$b = $f('T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8', 'hi');
function closure_f_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(int $x): int
{
    return $x;
}
function closure_f_T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8(string $x): string
{
    return $x;
}
