---
title: "src/Models/User.xphp"
layout: default
---

[← index](../../index.md) · [Source `.xphp` files](../../index.md#source)

# `src/Models/User.xphp`

```php
<?php

declare(strict_types=1);

namespace App\Models;

final class User
{
    public function __construct(public readonly string $name)
    {
    }
}

```
