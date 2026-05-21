---
title: "src/Containers/Repository.xphp"
layout: default
---

[← index](../../index.md) · [Source `.xphp` files](../../index.md#source)

# `src/Containers/Repository.xphp`

```php
<?php

declare(strict_types=1);

namespace App\Containers;

interface Repository<T>
{
    public function save(T $item): void;

    public function first(): ?T;
}

```
