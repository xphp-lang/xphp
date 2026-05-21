---
title: "src/Demos/Bounds.xphp"
layout: default
---

[← index](../../index.md) · [Source `.xphp` files](../../index.md#source)

# `src/Demos/Bounds.xphp`

```php
<?php

declare(strict_types=1);

namespace App\Demos;

use App\Containers\StringableBox;
use App\Models\Tag;

echo '[Bounds] StringableBox<T: \\Stringable>', PHP_EOL;

// Tag implements \Stringable -> bound is satisfied at compile time.
$tagBox = new StringableBox<Tag>(new Tag('hello'));
echo '  describe()                 = ', $tagBox->describe(), PHP_EOL;
echo '  item is the bound type     = ', $tagBox->item instanceof \Stringable ? 'STRINGABLE_OK' : 'NOT_STRINGABLE', PHP_EOL;

// If this file declared `new StringableBox<int>()`, compilation would fail with:
//   Generic bound violated while instantiating App\Containers\StringableBox<int>.
//     type parameter T is bounded by Stringable
//     but the supplied concrete type is int
//     "int" does not extend/implement "Stringable".
// (Bounds are validated at compile time — the build refuses to emit a class that
// would TypeError at runtime.)
echo '  bound enforced at compile time — see Containers/StringableBox.xphp', PHP_EOL;

echo PHP_EOL;

```
