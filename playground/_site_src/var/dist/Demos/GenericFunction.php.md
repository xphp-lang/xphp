---
title: "var/dist/Demos/GenericFunction.php"
layout: default
---

[← index](../../../index.md) · [Rewritten user code (`var/dist/`)](../../../index.md#dist)

# `var/dist/Demos/GenericFunction.php`

```php
<?php

declare (strict_types=1);
namespace App\Demos;

use App\Models\User;
echo '[GenericFunction] identity<T>(T $x): T (free function)', PHP_EOL;
$asInt = \App\Demos\identity_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(42);
$asString = \App\Demos\identity_T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8('greetings');
$asUser = \App\Demos\identity_T_d59a159d1113876f898c70f94a3971da6bb3165b1a8f0c07236c92d911df85ed(new User('bob'));
echo '  identity<int>(42)         = ', $asInt, ' (', gettype($asInt), ')', PHP_EOL;
echo '  identity<string>("...")   = ', $asString, ' (', gettype($asString), ')', PHP_EOL;
echo '  identity<User>(bob)->name = ', $asUser->name, ' (', $asUser::class, ')', PHP_EOL;
echo PHP_EOL;
// Free generic function (not inside a class). The compiler generates a separate
// specialized function per unique call-site arg list and rewrites each call site
// to point at the matching mangled FQN.
function identity_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(int $x): int
{
    return $x;
}
// Free generic function (not inside a class). The compiler generates a separate
// specialized function per unique call-site arg list and rewrites each call site
// to point at the matching mangled FQN.
function identity_T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8(string $x): string
{
    return $x;
}
// Free generic function (not inside a class). The compiler generates a separate
// specialized function per unique call-site arg list and rewrites each call site
// to point at the matching mangled FQN.
function identity_T_d59a159d1113876f898c70f94a3971da6bb3165b1a8f0c07236c92d911df85ed(\App\Models\User $x): \App\Models\User
{
    return $x;
}
```
