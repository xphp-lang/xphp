<?php

declare(strict_types=1);

namespace XPHP\Attribute;

use Attribute;

#[Attribute(
    Attribute::TARGET_PARAMETER
    | Attribute::IS_REPEATABLE
)]
final readonly class GenericValue
{
    public function __construct(
        public string $alias,
    ) {
    }
}
