---
title: "var/dist/Demos/InstanceofTemplate.php"
layout: default
---

[← index](../../../index.md) · [Rewritten user code (`var/dist/`)](../../../index.md#dist)

# `var/dist/Demos/InstanceofTemplate.php`

```php
<?php

declare (strict_types=1);
namespace App\Demos;

use App\Containers\Box;
use App\Models\Food;
use App\Models\Plastic;
echo '[InstanceofTemplate] $x instanceof OriginalTemplate matches any specialization', PHP_EOL;
$foodBox = new \XPHP\Generated\App\Containers\Box\T_3036c16466fa04e2ed024d6b75086b6a023199482af4f6a278a33d8bd9ebe44f(new Food('pizza'));
$plasticBox = new \XPHP\Generated\App\Containers\Box\T_de1e0eaabedbafa176d971782f59025438ff880765b54fb07b0698700f21a0cd(new Plastic('red'));
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
