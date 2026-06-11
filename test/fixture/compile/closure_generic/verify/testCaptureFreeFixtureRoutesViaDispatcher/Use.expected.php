<?php

declare (strict_types=1);
namespace App\ClosureGeneric;

// Capture-free generic closure. Each unique type-arg tuple produces
// one specialized top-level function (`closure_pair_T_<hash>`); the
// original closure variable is rewritten to a dispatcher that routes
// runtime calls to the right specialization. Duplicate arg tuples
// across call sites share a single specialization.
$pair = function (string $__xphp_tag, mixed ...$__xphp_args): mixed {
    return match ($__xphp_tag) {
        'T_ddc02d7f9d24d965819939d5b07227efa95da520d87d6728a4c41906ecce5bca' => \App\ClosureGeneric\closure_pair_T_ddc02d7f9d24d965819939d5b07227efa95da520d87d6728a4c41906ecce5bca(...$__xphp_args),
        default => throw new \RuntimeException('Unknown generic specialization tag: ' . $__xphp_tag),
    };
};
$pair('T_ddc02d7f9d24d965819939d5b07227efa95da520d87d6728a4c41906ecce5bca', 'age', 42);
$pair('T_ddc02d7f9d24d965819939d5b07227efa95da520d87d6728a4c41906ecce5bca', 'count', 7);
// same specialization -- de-duped via the tag set.
function closure_pair_T_ddc02d7f9d24d965819939d5b07227efa95da520d87d6728a4c41906ecce5bca(string $key, int $value): array
{
    return [$key, $value];
}
