<?php

declare(strict_types=1);

namespace XPHP\Attribute;

use Attribute;

#[Attribute(
    Attribute::TARGET_CLASS
    | Attribute::TARGET_METHOD
    | Attribute::IS_REPEATABLE
)]
final readonly class GenericType
{
    public function __construct(
        public string $alias,
    ) {
    }
}
