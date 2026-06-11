<?php

declare (strict_types=1);
namespace App\ClosureDispatcherUseClause;

// Generic closure with an explicit `use (...)` clause. By-ref captures
// (`&$counter`) survive the rewrite: mutations to `$counter` inside the
// body still write through to the outer scope. By-value captures
// (`$base`) behave as a snapshot. Two distinct type-args produce two
// distinct specialized functions, both sharing the same captured state.
$base = 10;
$counter = 0;
$f = function (string $__xphp_tag, mixed ...$__xphp_args) use ($base, &$counter): mixed {
    return match ($__xphp_tag) {
        'T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8' => \App\ClosureDispatcherUseClause\closure_f_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(...$__xphp_args, base: $base, counter: $counter),
        'T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8' => \App\ClosureDispatcherUseClause\closure_f_T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8(...$__xphp_args, base: $base, counter: $counter),
        default => throw new \RuntimeException('Unknown generic specialization tag: ' . $__xphp_tag),
    };
};
$callA = $f('T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8', 1);
// [1, 10, 1] -- $counter mutated to 1 in outer scope.
$callB = $f('T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8', 2);
// [2, 10, 2] -- $counter mutated to 2 in outer scope.
$callC = $f('T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8', 'hi');
// ['hi', 10, 3] -- new specialization, same captures.
function closure_f_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(int $x, mixed $base, mixed &$counter): array
{
    $counter++;
    return [$x, $base, $counter];
}
function closure_f_T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8(string $x, mixed $base, mixed &$counter): array
{
    $counter++;
    return [$x, $base, $counter];
}
