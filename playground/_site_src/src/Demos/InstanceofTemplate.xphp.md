---
title: "src/Demos/InstanceofTemplate.xphp"
layout: default
---

[← index](../../index.md) · [Source `.xphp` files](../../index.md#source)

# `src/Demos/InstanceofTemplate.xphp`

```php
<?php

declare(strict_types=1);

namespace App\Demos;

use App\Containers\Box;
use App\Models\Food;
use App\Models\Plastic;

echo '[InstanceofTemplate] $x instanceof OriginalTemplate matches any specialization', PHP_EOL;

$foodBox = new Box<Food>(new Food('pizza'));
$plasticBox = new Box<Plastic>(new Plastic('red'));

// Both specialized classes implement the marker interface at the original Box FQN.
echo '  Box<Food>    instanceof Box = ', $foodBox instanceof Box ? 'yes' : 'NO', PHP_EOL;
echo '  Box<Plastic> instanceof Box = ', $plasticBox instanceof Box ? 'yes' : 'NO', PHP_EOL;

// And reflection sees `Box` as the marker interface, not the original class.
$r = new \ReflectionClass(Box::class);
echo '  ReflectionClass(Box)::isInterface() = ', $r->isInterface() ? 'true (marker)' : 'false', PHP_EOL;

// Method calls still go through the specialized class (since instanceof against the marker
// gives you the marker's contract — empty in our case — so cast back via the concrete FQN
// if you need methods).
echo '  foodBox->get()->label  = ', $foodBox->get()->label, PHP_EOL;

echo PHP_EOL;

```
