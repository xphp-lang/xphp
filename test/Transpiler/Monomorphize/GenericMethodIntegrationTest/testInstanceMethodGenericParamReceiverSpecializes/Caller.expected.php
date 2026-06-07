<?php

declare (strict_types=1);
namespace App\InstParam;

class Caller
{
    public function viaParam(Util $u): int
    {
        return $u->identity_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(7);
    }
    public function viaNullableParam(?Util $u): ?int
    {
        return $u?->identity_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(11);
    }
}
