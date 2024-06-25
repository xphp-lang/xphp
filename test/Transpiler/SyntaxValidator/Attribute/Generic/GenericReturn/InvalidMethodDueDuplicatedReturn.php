<?php

declare(strict_types=1);

namespace XPHP\Transpiler\SyntaxValidator\Attribute\Generic\GenericReturn;

use XPHP\Attribute\GenericReturn;
use XPHP\Attribute\GenericType;

#[GenericType("T")]
class InvalidMethodDueDuplicatedReturn
{
    #[GenericReturn("T")]
    #[GenericReturn("T")]
    public function doNothing(
        mixed $value,
    ): mixed {
        return $value;
    }
}
