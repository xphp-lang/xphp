<?php

declare(strict_types=1);

namespace XPHP\Type\GenericType;

use XPHP\Attribute\GenericReturn;
use XPHP\Attribute\GenericType;
use XPHP\Attribute\GenericValue;

#[GenericType("T[]")]
final readonly class GenericCollection
{
    public function __construct(
        #[GenericValue("T[]")]
        private array $items,
    ) {
    }

    #[GenericReturn("T[]")]
    public function items(): array
    {
        return $this->items;
    }
}
