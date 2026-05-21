---
title: "var/dist/Models/Tag.php"
layout: default
---

[← index](../../../index.md) · [Rewritten user code (`var/dist/`)](../../../index.md#dist)

# `var/dist/Models/Tag.php`

```php
<?php

declare (strict_types=1);
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
