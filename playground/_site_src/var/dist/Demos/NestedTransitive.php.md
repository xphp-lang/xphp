---
title: "var/dist/Demos/NestedTransitive.php"
layout: default
---

[← index](../../../index.md) · [Rewritten user code (`var/dist/`)](../../../index.md#dist)

# `var/dist/Demos/NestedTransitive.php`

```php
<?php

declare (strict_types=1);
namespace App\Demos;

use App\Containers\Box;
use App\Containers\Wrapper;
use App\Models\Plastic;
use ReflectionProperty;
echo '[NestedTransitive] Wrapper<Plastic> -> transitive Box<Plastic>', PHP_EOL;
$wrapper = new \XPHP\Generated\App\Containers\Wrapper\T_de1e0eaabedbafa176d971782f59025438ff880765b54fb07b0698700f21a0cd(new \XPHP\Generated\App\Containers\Box\T_de1e0eaabedbafa176d971782f59025438ff880765b54fb07b0698700f21a0cd(new Plastic('green')));
echo '  unwrap()->color           = ', $wrapper->unwrap()->color, PHP_EOL;
echo '  reflected wrapper->box    = ', (new ReflectionProperty($wrapper::class, 'box'))->getType()?->getName() ?? '<none>', PHP_EOL;
echo '  reflected box->item       = ', (new ReflectionProperty($wrapper->box::class, 'item'))->getType()?->getName() ?? '<none>', PHP_EOL;
echo '  Box<Plastic> was transitively specialized via Wrapper<Plastic>\'s constructor signature', PHP_EOL;
echo PHP_EOL;
```
