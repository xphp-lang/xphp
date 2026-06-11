<?php

namespace App;

$pair = function (string $__xphp_tag, mixed ...$__xphp_args): mixed {
    return match ($__xphp_tag) {
        'T_ddc02d7f9d24d965819939d5b07227efa95da520d87d6728a4c41906ecce5bca' => \App\closure_pair_T_ddc02d7f9d24d965819939d5b07227efa95da520d87d6728a4c41906ecce5bca(...$__xphp_args),
        default => throw new \RuntimeException('Unknown generic specialization tag: ' . $__xphp_tag),
    };
};
$pair('T_ddc02d7f9d24d965819939d5b07227efa95da520d87d6728a4c41906ecce5bca', 'age', 42);
function closure_pair_T_ddc02d7f9d24d965819939d5b07227efa95da520d87d6728a4c41906ecce5bca(string $key, int $value): array
{
    return [$key, $value];
}
