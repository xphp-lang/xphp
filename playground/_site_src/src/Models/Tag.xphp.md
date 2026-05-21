---
title: "src/Models/Tag.xphp"
layout: default
---

[← index](../../index.md) · [Source `.xphp` files](../../index.md#source)

# `src/Models/Tag.xphp`

```php
<?php

declare(strict_types=1);

namespace App\Models;

final class Tag implements \Stringable
{
    public function __construct(public readonly string $name)
    {
    }

    public function __toString(): string
    {
        return $this->name;
    }
}

```
