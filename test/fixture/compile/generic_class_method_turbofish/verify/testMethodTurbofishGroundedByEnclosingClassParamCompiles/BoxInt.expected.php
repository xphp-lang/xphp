<?php

declare (strict_types=1);
namespace XPHP\Generated\App\MethodTurbofish\Box;

// Method turbofish grounded by an enclosing class type parameter: `self::gen::<T>`
// and `Maker::wrap::<T>` are abstract inside the `Box<T>` template and only become
// concrete when `Box<int>` / `Box<string>` specialize. Each specialization grounds and
// dispatches them: the own-template member `gen_T_<hash>` lands on the specialization
// itself (dispatched via `self::`, appended once per spec even with two call sites),
// and the shared non-generic target gets one `wrap_T_<hash>` per unique argument tuple
// regardless of how many generic classes forward to it.
class T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8 implements \App\MethodTurbofish\Box
{
    public function make(int $v): int
    {
        return self::gen_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8($v);
    }
    public function makeAgain(int $v): int
    {
        // Same target + same grounded args as make(): per-spec dedup must
        // append a single member, not one per call site.
        return self::gen_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8($v);
    }
    /** @return array<int, mixed> */
    public function viaMaker(int $v): array
    {
        return \App\MethodTurbofish\Maker::wrap_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8($v);
    }
    public static function gen_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(int $x): int
    {
        return $x;
    }
}
