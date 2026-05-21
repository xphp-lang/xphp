---
title: "var/dist/Demos/SingleType.php"
layout: default
---

[← index](../../../index.md) · [Rewritten user code (`var/dist/`)](../../../index.md#dist)

# `var/dist/Demos/SingleType.php`

```php
<?php

declare (strict_types=1);
namespace App\Demos;

use App\Containers\Box;
use App\Models\Food;
use ReflectionProperty;
echo "[SingleType] Box<Food>", PHP_EOL;
$boxOfFood = new \XPHP\Generated\App\Containers\Box\T_3036c16466fa04e2ed024d6b75086b6a023199482af4f6a278a33d8bd9ebe44f(new Food('pizza'));
echo '  Box->get()->label   = ', $boxOfFood->get()->label, PHP_EOL;
echo '  reflected item type = ', (new ReflectionProperty($boxOfFood::class, 'item'))->getType()?->getName() ?? '<none>', PHP_EOL;
try {
    $bad = new \XPHP\Generated\App\Containers\Box\T_3036c16466fa04e2ed024d6b75086b6a023199482af4f6a278a33d8bd9ebe44f('not-a-food');
    echo '  WRONG_TYPE: no error fired', PHP_EOL;
} catch (\TypeError $e) {
    echo '  TypeError fires for non-Food arg (good)', PHP_EOL;
}
echo PHP_EOL;
```
