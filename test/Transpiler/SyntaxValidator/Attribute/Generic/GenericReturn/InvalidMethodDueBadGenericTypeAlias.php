<?php

declare(strict_types=1);

namespace XPHP\Transpiler\SyntaxValidator\Attribute\Generic\GenericReturn;

use XPHP\Attribute\GenericReturn;
use XPHP\Attribute\GenericType;

#[GenericType("T")]
class InvalidMethodDueBadGenericTypeAlias
{
    #[GenericReturn("Z")]
    public function doNothing(
        mixed $value,
    ): mixed {
        return $value;
    }
}
