<?php

declare(strict_types=1);

namespace XPHP\Attribute;

use Attribute;

#[Attribute(
    Attribute::TARGET_METHOD
)]
final readonly class GenericReturn
{
    public function __construct(
        public string $alias,
    ) {
    }
}
