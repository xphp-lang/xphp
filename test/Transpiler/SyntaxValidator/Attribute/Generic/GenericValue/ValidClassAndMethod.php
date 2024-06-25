<?php

declare(strict_types=1);

namespace XPHP\Transpiler\SyntaxValidator\Attribute\Generic\GenericValue;

use XPHP\Attribute\GenericType;
use XPHP\Attribute\GenericValue;

#[GenericType("T")]
class ValidClassAndMethod
{
    public function doNothing(
        #[GenericValue("T")]
        mixed $value,
    ): void {
    }

    #[GenericType("Z")]
    public static function staticMethod(
        #[GenericValue("Z")]
        mixed $value,
    ): void {
    }
}
