---
title: "src/Containers/Box.xphp"
layout: default
---

[← index](../../index.md) · [Source `.xphp` files](../../index.md#source)

# `src/Containers/Box.xphp`

```php
<?php

declare(strict_types=1);

namespace App\Containers;

class Box<T>
{
    public function __construct(public T $item)
    {
    }

    public function get(): T
    {
        return $this->item;
    }
}

```
