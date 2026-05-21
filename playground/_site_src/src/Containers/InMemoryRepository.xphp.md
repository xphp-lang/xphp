---
title: "src/Containers/InMemoryRepository.xphp"
layout: default
---

[← index](../../index.md) · [Source `.xphp` files](../../index.md#source)

# `src/Containers/InMemoryRepository.xphp`

```php
<?php

declare(strict_types=1);

namespace App\Containers;

class InMemoryRepository<T> implements Repository<T>
{
    private T[] $items;

    public function __construct()
    {
        $this->items = [];
    }

    public function save(T $item): void
    {
        $this->items[] = $item;
    }

    public function first(): ?T
    {
        return $this->items[0] ?? null;
    }
}

```
