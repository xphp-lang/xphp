---
title: "src/Containers/Util.xphp"
layout: default
---

[← index](../../index.md) · [Source `.xphp` files](../../index.md#source)

# `src/Containers/Util.xphp`

```php
<?php

declare(strict_types=1);

namespace App\Containers;

class Util
{
    public static function identity<T>(T $x): T
    {
        return $x;
    }

    public static function first<T>(array $items): ?T
    {
        return $items[0] ?? null;
    }
}

```
