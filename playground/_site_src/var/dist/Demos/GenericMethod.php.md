---
title: "var/dist/Demos/GenericMethod.php"
layout: default
---

[← index](../../../index.md) · [Rewritten user code (`var/dist/`)](../../../index.md#dist)

# `var/dist/Demos/GenericMethod.php`

```php
<?php

declare (strict_types=1);
namespace App\Demos;

use App\Containers\Util;
use App\Models\User;
echo '[GenericMethod] Util::identity<T>(T $x): T (method-scoped generic)', PHP_EOL;
$asInt = \App\Containers\Util::identity_T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8(42);
$asString = \App\Containers\Util::identity_T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8('hello');
$asUser = \App\Containers\Util::identity_T_d59a159d1113876f898c70f94a3971da6bb3165b1a8f0c07236c92d911df85ed(new User('alice'));
echo '  identity<int>(42)          = ', $asInt, ' (', gettype($asInt), ')', PHP_EOL;
echo '  identity<string>("hello")  = ', $asString, ' (', gettype($asString), ')', PHP_EOL;
echo '  identity<User>(alice)->name= ', $asUser->name, ' (', $asUser::class, ')', PHP_EOL;
try {
    // identity<int> demands int — strict_types makes this fail.
    $bad = Util::identity_T_FAKE(42);
} catch (\Throwable $e) {
    // Just demonstrating that the specialized methods do exist; ignore the contrived error.
}
try {
    // Force a runtime TypeError via the specialized method directly.
    /** @var callable $bad */
    $bad = [Util::class, 'identity_T_0'];
    if (is_callable($bad)) {
        $bad('not-an-int');
    }
} catch (\TypeError $e) {
    // Expected — the mangled method enforces the concrete type.
}
echo PHP_EOL;
```
