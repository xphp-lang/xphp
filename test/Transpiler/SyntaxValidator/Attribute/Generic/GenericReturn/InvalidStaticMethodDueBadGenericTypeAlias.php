<?php

declare(strict_types=1);

namespace XPHP\Transpiler\SyntaxValidator\Attribute\Generic\GenericReturn;

use XPHP\Attribute\GenericReturn;
use XPHP\Attribute\GenericType;

class InvalidStaticMethodDueBadGenericTypeAlias
{
    #[GenericType("T")]
    #[GenericReturn("Z")]
    public static function doNothing(
        mixed $value,
    ): mixed {
        return $value;
    }
}
