<?php

declare (strict_types=1);
namespace App\ClosureDispatcherDefaults;

// Default type arguments on generic closures and arrows. Anonymous
// closures and arrows accept `T = Default`; the empty-turbofish
// `$f::<>()` shape pads missing trailing args from the default. Mixes
// freely with explicit `use ()` captures and with explicit arg tuples
// (`$f::<string>('hi')`).
//
// Note: `static function<T = ...>` is not supported (defaults on static
// closures stay rejected at parse time).
$prefix = '#';
$f = function (string $__xphp_tag, mixed ...$__xphp_args) use ($prefix): mixed {
    return match ($__xphp_tag) {
        'T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8' => \App\ClosureDispatcherDefaults\closure_f_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(...$__xphp_args, prefix: $prefix),
        'T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8' => \App\ClosureDispatcherDefaults\closure_f_T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8(...$__xphp_args, prefix: $prefix),
        default => throw new \RuntimeException('Unknown generic specialization tag: ' . $__xphp_tag),
    };
};
$g = function (string $__xphp_tag, mixed ...$__xphp_args): mixed {
    return match ($__xphp_tag) {
        'T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8' => \App\ClosureDispatcherDefaults\closure_g_T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8(...$__xphp_args),
        default => throw new \RuntimeException('Unknown generic specialization tag: ' . $__xphp_tag),
    };
};
$resultPaddedClosure = $f('T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8', 42);
// pads T -> int.
$resultExplicitClosure = $f('T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8', 'hi');
// explicit T = string.
$resultPaddedArrow = $g('T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8', 'world');
// pads T -> string.
function closure_f_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(int $x, mixed $prefix): string
{
    return $prefix . $x;
}
function closure_f_T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8(string $x, mixed $prefix): string
{
    return $prefix . $x;
}
function closure_g_T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8(string $x): string
{
    return $x;
}
