<?php

declare(strict_types=1);

namespace XPHP\Transpiler\SyntaxValidator\Attribute\Generic\GenericType;

use XPHP\Attribute\GenericType;

class InvalidNonStaticMethod
{
    #[GenericType("T")]
    public function doNothing(): void
    {
    }
}
