---
title: "var/dist/Demos/MultiType.php"
layout: default
---

[← index](../../../index.md) · [Rewritten user code (`var/dist/`)](../../../index.md#dist)

# `var/dist/Demos/MultiType.php`

```php
<?php

declare (strict_types=1);
namespace App\Demos;

use App\Containers\Map;
use App\Containers\Pair;
use App\Models\Plastic;
use App\Models\User;
use ReflectionProperty;
echo '[MultiType] Pair<User, Plastic>, Map<string, int>', PHP_EOL;
$pair = new \XPHP\Generated\App\Containers\Pair\T_3ca1518773f5e15bb180bc027c8394e76250619a79189c7cb33652fd9815b2ed(new User('alice'), new Plastic('red'));
echo '  pair->first->name    = ', $pair->first->name, PHP_EOL;
echo '  pair->second->color  = ', $pair->second->color, PHP_EOL;
echo '  reflected first type = ', (new ReflectionProperty($pair::class, 'first'))->getType()?->getName() ?? '<none>', PHP_EOL;
$map = new \XPHP\Generated\App\Containers\Map\T_ddc02d7f9d24d965819939d5b07227efa95da520d87d6728a4c41906ecce5bca();
$map->set('alpha', 1);
$map->set('beta', 2);
echo '  map firstKey         = ', $map->firstKey(), PHP_EOL;
echo '  map firstValue       = ', $map->firstValue(), PHP_EOL;
// Slot-order matters: Pair<User, Plastic> and Pair<Plastic, User> are *different* generated classes.
$reversed = new \XPHP\Generated\App\Containers\Pair\T_307894382bcf10563dfe258ab0eef6cf6d925ef67d412e2b8743703540f18bdd(new Plastic('blue'), new User('bob'));
echo '  pair vs reversed eq  = ', $pair::class === $reversed::class ? 'SAME (wrong!)' : 'different (good)', PHP_EOL;
echo PHP_EOL;
```
