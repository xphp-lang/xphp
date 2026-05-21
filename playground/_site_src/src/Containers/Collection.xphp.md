---
title: "src/Containers/Collection.xphp"
layout: default
---

[← index](../../index.md) · [Source `.xphp` files](../../index.md#source)

# `src/Containers/Collection.xphp`

```php
<?php

declare(strict_types=1);

namespace App\Containers;

class Collection<T>
{
    private T[] $items;

    public function __construct(T ...$items)
    {
        $this->items = $items;
    }

    public function first(): ?T
    {
        return $this->items[0] ?? null;
    }

    public function all(): T[]
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }
}

```
