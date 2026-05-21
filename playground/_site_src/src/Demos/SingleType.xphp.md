---
title: "src/Demos/SingleType.xphp"
layout: default
---

[← index](../../index.md) · [Source `.xphp` files](../../index.md#source)

# `src/Demos/SingleType.xphp`

```php
<?php

declare(strict_types=1);

namespace App\Demos;

use App\Containers\Box;
use App\Models\Food;
use ReflectionProperty;

echo "[SingleType] Box<Food>", PHP_EOL;

$boxOfFood = new Box<Food>(new Food('pizza'));

echo '  Box->get()->label   = ', $boxOfFood->get()->label, PHP_EOL;
echo '  reflected item type = ', (new ReflectionProperty($boxOfFood::class, 'item'))->getType()?->getName() ?? '<none>', PHP_EOL;

try {
    $bad = new Box<Food>('not-a-food');
    echo '  WRONG_TYPE: no error fired', PHP_EOL;
} catch (\TypeError $e) {
    echo '  TypeError fires for non-Food arg (good)', PHP_EOL;
}

echo PHP_EOL;

```
