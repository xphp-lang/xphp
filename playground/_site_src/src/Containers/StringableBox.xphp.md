---
title: "src/Containers/StringableBox.xphp"
layout: default
---

[← index](../../index.md) · [Source `.xphp` files](../../index.md#source)

# `src/Containers/StringableBox.xphp`

```php
<?php

declare(strict_types=1);

namespace App\Containers;

class StringableBox<T: \Stringable>
{
    public function __construct(public T $item)
    {
    }

    public function describe(): string
    {
        return 'StringableBox<' . (string) $this->item . '>';
    }
}

```
