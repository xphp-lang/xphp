---
title: "src/Models/Food.xphp"
layout: default
---

[← index](../../index.md) · [Source `.xphp` files](../../index.md#source)

# `src/Models/Food.xphp`

```php
<?php

declare(strict_types=1);

namespace App\Models;

final class Food
{
    public function __construct(public readonly string $label)
    {
    }
}

```
