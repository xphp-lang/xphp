<?php

declare(strict_types=1);

namespace XPHP\Transpiler\SyntaxValidator\Attribute\Generic\GenericType;

use XPHP\Attribute\GenericType;

#[GenericType("T")]
#[GenericType("T")]
class InvalidClass
{
    #[GenericType("Z")]
    public static function doNothing(): void
    {
    }
}
