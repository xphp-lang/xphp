---
title: "src/Demos/ArraySugar.xphp"
layout: default
---

[← index](../../index.md) · [Source `.xphp` files](../../index.md#source)

# `src/Demos/ArraySugar.xphp`

```php
<?php

declare(strict_types=1);

namespace App\Demos;

use App\Containers\Collection;
use App\Models\User;
use ReflectionMethod;

echo '[ArraySugar] Collection<User>: T[] property + ?T return type', PHP_EOL;

$users = new Collection<User>(new User('alice'), new User('bob'), new User('carol'));

echo '  collection->count()     = ', $users->count(), PHP_EOL;
echo '  collection->first()->name = ', $users->first()?->name ?? '<null>', PHP_EOL;

$first = (new ReflectionMethod($users::class, 'first'))->getReturnType();
echo '  reflected first(): type = ', $first instanceof \ReflectionNamedType ? $first->getName() : '<not-named>', PHP_EOL;
echo '  reflected first(): nullable = ', $first instanceof \ReflectionNamedType && $first->allowsNull() ? 'yes' : 'no', PHP_EOL;

$empty = new Collection<User>();
echo '  empty collection first  = ', $empty->first() === null ? 'null (good)' : 'unexpected', PHP_EOL;

// `T ...$items` is what actually enforces element type at runtime.
try {
    $bad = new Collection<User>(new User('alice'), 'not-a-user');
    echo '  WRONG_TYPE: variadic accepted bad arg', PHP_EOL;
} catch (\TypeError $e) {
    echo '  variadic TypeError fires for non-User arg (good)', PHP_EOL;
}

echo PHP_EOL;

```
