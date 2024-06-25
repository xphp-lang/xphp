<?php

declare(strict_types=1);

namespace XPHP\Transpiler\SyntaxValidator\Attribute\Generic\GenericValue;

use XPHP\Attribute\GenericReturn;
use XPHP\Attribute\GenericType;
use XPHP\Attribute\GenericValue;

#[GenericType("T")]
class InvalidMethodDueBadGenericTypeAlias
{
    #[GenericReturn("T")]
    public function doNothing(
        #[GenericValue("Z")]
        mixed $value,
    ): mixed {
        return $value;
    }
}
