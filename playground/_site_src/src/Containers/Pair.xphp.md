---
title: "src/Containers/Pair.xphp"
layout: default
---

[← index](../../index.md) · [Source `.xphp` files](../../index.md#source)

# `src/Containers/Pair.xphp`

```php
<?php

declare(strict_types=1);

namespace App\Containers;

class Pair<K, V>
{
    public function __construct(
        public K $first,
        public V $second,
    ) {
    }
}

```
