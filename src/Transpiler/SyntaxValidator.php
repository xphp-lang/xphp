<?php

declare(strict_types=1);

namespace XPHP\Transpiler;

use Exception;
use Roave\BetterReflection\Reflector\Reflector;
use XPHP\Transpiler\SyntaxValidator\ClassValidator;

final readonly class SyntaxValidator
{
    /**
     * @throws Exception
     */
    public static function validate(Reflector $reflector): void
    {
        $classes = $reflector->reflectAllClasses();

        foreach ($classes as $class) {
            ClassValidator::validate($class);
        }
    }
}
