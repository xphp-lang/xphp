---
title: "var/dist/Demos/GenericInterface.php"
layout: default
---

[← index](../../../index.md) · [Rewritten user code (`var/dist/`)](../../../index.md#dist)

# `var/dist/Demos/GenericInterface.php`

```php
<?php

declare (strict_types=1);
namespace App\Demos;

use App\Containers\InMemoryRepository;
use App\Containers\Repository;
use App\Models\User;
use ReflectionMethod;
echo '[GenericInterface] Repository<User> implemented by InMemoryRepository<User>', PHP_EOL;
$repo = new \XPHP\Generated\App\Containers\InMemoryRepository\T_d59a159d1113876f898c70f94a3971da6bb3165b1a8f0c07236c92d911df85ed();
$repo->save(new User('alice'));
$repo->save(new User('bob'));
echo '  first()->name              = ', $repo->first()?->name ?? '<null>', PHP_EOL;
// Reflection: save()'s parameter and first()'s return type both reify to the concrete class.
$saveParam = (new ReflectionMethod($repo::class, 'save'))->getParameters()[0]->getType();
$firstReturn = (new ReflectionMethod($repo::class, 'first'))->getReturnType();
echo '  reflected save(): param    = ', $saveParam instanceof \ReflectionNamedType ? $saveParam->getName() : '<unnamed>', PHP_EOL;
echo '  reflected first(): return  = ', $firstReturn instanceof \ReflectionNamedType ? $firstReturn->getName() : '<unnamed>', PHP_EOL;
// Marker test: the specialized class implements the specialized interface, so instanceof works
// against the generated Repository<User> FQCN. (Asking "is this *any* Repository?" without a
// concrete arg is gap #5 — covered separately.)
echo '  implements Repository<User>? ', $repo instanceof Repository ? 'INTERFACE_OK' : 'no — only the specialized iface is implemented', PHP_EOL;
try {
    $repo->save('not-a-user');
    echo '  WRONG_TYPE: save accepted bad arg', PHP_EOL;
} catch (\TypeError $e) {
    echo '  save(): TypeError fires for non-User arg (good)', PHP_EOL;
}
echo PHP_EOL;
```
