<?php
declare(strict_types=1);
/**
 * Runtime verify for `scalar_alias_class_resolves`.
 *
 * Loading `Use.php` links each `Box<…>` specialization. Before the fix a class whose name aliases a scalar
 * (`Double`/`Integer`/`Boolean`) used as a type argument was emitted as the bare scalar type hint
 * (`double`/`integer`/`boolean`), which PHP interprets as a non-existent class — a `TypeError` on
 * construction. That all three construct, run, and return their class-typed values proves the alias names
 * resolve to the classes in argument position.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope, autoload registered.
 */
use PHPUnit\Framework\Assert;
require $fixture->targetDir . '/Use.php';
Assert::assertInstanceOf(\App\Double::class, $dv, 'Box::<Double> must carry an App\\Double, not a scalar');
Assert::assertSame(2.5, $dv->f);
Assert::assertInstanceOf(\App\Integer::class, $iv, 'Box::<Integer> must carry an App\\Integer');
Assert::assertSame(7, $iv->i);
Assert::assertInstanceOf(\App\Boolean::class, $bv, 'Box::<Boolean> must carry an App\\Boolean');
Assert::assertTrue($bv->b);
echo "OK\n";
