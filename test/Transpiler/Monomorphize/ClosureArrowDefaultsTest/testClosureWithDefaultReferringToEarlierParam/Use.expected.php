<?php

namespace App\CDefRef;

$f = function (string $__xphp_tag, mixed ...$__xphp_args): mixed {
    return match ($__xphp_tag) {
        'T_e3f8dbfd55c37372b84ec55651fa922ebe8b9168bd3856eac16ee4e2d1f8600f' => \App\CDefRef\closure_f_T_e3f8dbfd55c37372b84ec55651fa922ebe8b9168bd3856eac16ee4e2d1f8600f(...$__xphp_args),
        default => throw new \RuntimeException('Unknown generic specialization tag: ' . $__xphp_tag),
    };
};
$r = $f('T_e3f8dbfd55c37372b84ec55651fa922ebe8b9168bd3856eac16ee4e2d1f8600f', 1, 2);
function closure_f_T_e3f8dbfd55c37372b84ec55651fa922ebe8b9168bd3856eac16ee4e2d1f8600f(int $a, int $b): array
{
    return [$a, $b];
}
