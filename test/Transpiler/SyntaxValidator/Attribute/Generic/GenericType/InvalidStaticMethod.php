<?php

declare(strict_types=1);

namespace XPHP\Transpiler\SyntaxValidator\Attribute\Generic\GenericType;

use XPHP\Attribute\GenericType;

#[GenericType("T")]
class InvalidStaticMethod
{
    #[GenericType("Z")]
    #[GenericType("Z")]
    public static function doNothing(): void
    {
    }
}
