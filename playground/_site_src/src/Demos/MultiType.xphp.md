---
title: "src/Demos/MultiType.xphp"
layout: default
---

[← index](../../index.md) · [Source `.xphp` files](../../index.md#source)

# `src/Demos/MultiType.xphp`

```php
<?php

declare(strict_types=1);

namespace App\Demos;

use App\Containers\Map;
use App\Containers\Pair;
use App\Models\Plastic;
use App\Models\User;
use ReflectionProperty;

echo '[MultiType] Pair<User, Plastic>, Map<string, int>', PHP_EOL;

$pair = new Pair<User, Plastic>(new User('alice'), new Plastic('red'));
echo '  pair->first->name    = ', $pair->first->name, PHP_EOL;
echo '  pair->second->color  = ', $pair->second->color, PHP_EOL;
echo '  reflected first type = ', (new ReflectionProperty($pair::class, 'first'))->getType()?->getName() ?? '<none>', PHP_EOL;

$map = new Map<string, int>();
$map->set('alpha', 1);
$map->set('beta', 2);
echo '  map firstKey         = ', $map->firstKey(), PHP_EOL;
echo '  map firstValue       = ', $map->firstValue(), PHP_EOL;

// Slot-order matters: Pair<User, Plastic> and Pair<Plastic, User> are *different* generated classes.
$reversed = new Pair<Plastic, User>(new Plastic('blue'), new User('bob'));
echo '  pair vs reversed eq  = ', $pair::class === $reversed::class ? 'SAME (wrong!)' : 'different (good)', PHP_EOL;

echo PHP_EOL;

```
