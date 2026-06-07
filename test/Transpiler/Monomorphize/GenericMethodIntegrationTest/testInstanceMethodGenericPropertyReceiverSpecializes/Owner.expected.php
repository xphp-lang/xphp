<?php

declare (strict_types=1);
namespace App\InstProp;

class Owner
{
    public Util $util;
    public function __construct()
    {
        $this->util = new Util();
    }
    public function go(): int
    {
        return $this->util->identity_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(123);
    }
}
