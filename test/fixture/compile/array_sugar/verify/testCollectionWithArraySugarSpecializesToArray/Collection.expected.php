<?php

declare (strict_types=1);
namespace XPHP\Generated\App\ArraySugar\Containers\Collection;

class T_3c24c88e90eb2397e5c2549b00cb72da05e5391c4cbd38daf1be9844df98cd7c implements \App\ArraySugar\Containers\Collection
{
    private array $items;
    public function __construct(\App\ArraySugar\Models\User ...$items)
    {
        $this->items = $items;
    }
    public function first(): ?\App\ArraySugar\Models\User
    {
        return $this->items[0] ?? null;
    }
    public function all(): array
    {
        return $this->items;
    }
}
