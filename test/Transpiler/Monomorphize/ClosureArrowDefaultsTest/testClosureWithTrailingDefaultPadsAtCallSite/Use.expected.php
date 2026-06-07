<?php

namespace App\CDefTrail;

$f = function (string $__xphp_tag, mixed ...$__xphp_args): mixed {
    return match ($__xphp_tag) {
        'T_a69c69291a42ebaa09d84aab3b442cf9e3ed3205b8ca72589de93727b12c95f8' => \App\CDefTrail\closure_f_T_a69c69291a42ebaa09d84aab3b442cf9e3ed3205b8ca72589de93727b12c95f8(...$__xphp_args),
        default => throw new \RuntimeException('Unknown generic specialization tag: ' . $__xphp_tag),
    };
};
$r = $f('T_a69c69291a42ebaa09d84aab3b442cf9e3ed3205b8ca72589de93727b12c95f8', 10, 'hi');
function closure_f_T_a69c69291a42ebaa09d84aab3b442cf9e3ed3205b8ca72589de93727b12c95f8(int $a, string $b): array
{
    return [$a, $b];
}
