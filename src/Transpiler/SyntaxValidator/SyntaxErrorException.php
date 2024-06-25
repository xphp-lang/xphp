<?php

declare(strict_types=1);

namespace XPHP\Transpiler\SyntaxValidator;

use Exception;

class SyntaxErrorException extends Exception
{
    public static function createFromPrevious(SyntaxErrorException $e): static
    {
        return new self(
            message: $e->getMessage(),
            code: $e->getCode(),
            previous: $e,
        );
    }
}
