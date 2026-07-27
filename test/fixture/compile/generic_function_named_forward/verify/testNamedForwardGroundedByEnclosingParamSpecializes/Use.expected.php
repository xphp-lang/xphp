<?php

declare (strict_types=1);
namespace App\NamedForward;

$i = \App\NamedForward\wrap_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(3);
$s = \App\NamedForward\wrap_T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8('hi');
function wrap_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(int $v): int
{
    return \App\NamedForward\identity_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8($v);
}
function wrap_T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8(string $v): string
{
    return \App\NamedForward\identity_T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8($v);
}
// A named generic free function forwarding a type argument grounded by the enclosing
// function's type parameter: `identity::<T>($v)` is abstract inside the `wrap<T>`
// template, becomes concrete when `wrap` specializes (`wrap::<int>` substitutes
// `identity::<int>`), and the append-drain then grounds and dispatches it into a real
// `identity_T_<hash>` specialization.
function identity_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(int $x): int
{
    return $x;
}
// A named generic free function forwarding a type argument grounded by the enclosing
// function's type parameter: `identity::<T>($v)` is abstract inside the `wrap<T>`
// template, becomes concrete when `wrap` specializes (`wrap::<int>` substitutes
// `identity::<int>`), and the append-drain then grounds and dispatches it into a real
// `identity_T_<hash>` specialization.
function identity_T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8(string $x): string
{
    return $x;
}
