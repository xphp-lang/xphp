<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    public function testGeneratedFqnIsStableForSameInput(): void
    {
        $a = Registry::generatedFqn('App\\Containers\\Box', [new TypeRef('App\\Models\\Plastic')]);
        $b = Registry::generatedFqn('App\\Containers\\Box', [new TypeRef('App\\Models\\Plastic')]);
        self::assertSame($a, $b);
    }

    public function testGeneratedFqnMirrorsTemplateNamespace(): void
    {
        $fqn = Registry::generatedFqn('App\\Containers\\Box', [new TypeRef('App\\Models\\Plastic')]);
        self::assertStringStartsWith('XPHP\\Generated\\App\\Containers\\Box\\T_', $fqn);
        self::assertMatchesRegularExpression('/T_[0-9a-f]{16}$/', $fqn);
    }

    public function testDifferentTemplateNamespacesProduceDifferentFqns(): void
    {
        $a = Registry::generatedFqn('App\\Containers\\Box', [new TypeRef('App\\Models\\Plastic')]);
        $b = Registry::generatedFqn('App\\Other\\Box', [new TypeRef('App\\Models\\Plastic')]);

        self::assertNotSame($a, $b);
        self::assertStringContainsString('App\\Containers\\Box', $a);
        self::assertStringContainsString('App\\Other\\Box', $b);
    }

    public function testDifferentArgsProduceDifferentHashes(): void
    {
        $a = Registry::generatedFqn('App\\Containers\\Box', [new TypeRef('App\\Models\\Plastic')]);
        $b = Registry::generatedFqn('App\\Containers\\Box', [new TypeRef('App\\Models\\Metal')]);

        self::assertNotSame($a, $b);
        self::assertStringStartsWith('XPHP\\Generated\\App\\Containers\\Box\\T_', $a);
        self::assertStringStartsWith('XPHP\\Generated\\App\\Containers\\Box\\T_', $b);
    }

    public function testSameArgShortNameDifferentNamespacesDoNotCollide(): void
    {
        $a = Registry::generatedFqn('App\\Containers\\Box', [new TypeRef('App\\Models\\Plastic')]);
        $b = Registry::generatedFqn('App\\Containers\\Box', [new TypeRef('App\\Other\\Plastic')]);

        self::assertNotSame($a, $b, 'short-name collision must be prevented by hashing the full canonical arg FQCN');
    }

    public function testNestedGenericArgsAffectHashDeterministically(): void
    {
        $nested = new TypeRef('App\\Containers\\Lst', [new TypeRef('App\\Models\\Plastic')]);

        $a = Registry::generatedFqn('App\\Containers\\Box', [$nested]);
        $b = Registry::generatedFqn('App\\Containers\\Box', [$nested]);

        self::assertSame($a, $b);
        self::assertStringStartsWith('XPHP\\Generated\\App\\Containers\\Box\\T_', $a);
    }

    public function testRecordInstantiationIsIdempotent(): void
    {
        $registry = new Registry();

        $first = $registry->recordInstantiation('App\\Containers\\Box', [new TypeRef('App\\Models\\Plastic')]);
        $second = $registry->recordInstantiation('App\\Containers\\Box', [new TypeRef('App\\Models\\Plastic')]);

        self::assertSame($first, $second);
        self::assertCount(1, $registry->instantiations());
    }

    public function testDistinctTypesProduceDistinctEntries(): void
    {
        $registry = new Registry();

        $registry->recordInstantiation('App\\Containers\\Box', [new TypeRef('App\\Models\\Plastic')]);
        $registry->recordInstantiation('App\\Containers\\Box', [new TypeRef('App\\Models\\Metal')]);

        self::assertCount(2, $registry->instantiations());
        foreach ($registry->instantiations() as $fqn => $_) {
            self::assertStringStartsWith('XPHP\\Generated\\App\\Containers\\Box\\T_', $fqn);
        }
    }

    public function testRecordInstantiationRecursivelyRegistersNestedInstantiations(): void
    {
        $registry = new Registry();

        $nested = new TypeRef('App\\Containers\\Lst', [new TypeRef('App\\Models\\Plastic')]);
        $registry->recordInstantiation('App\\Containers\\Box', [$nested]);

        self::assertCount(2, $registry->instantiations());

        $hasBox = false;
        $hasLst = false;
        foreach ($registry->instantiations() as $fqn => $_) {
            if (str_starts_with($fqn, 'XPHP\\Generated\\App\\Containers\\Box\\T_')) {
                $hasBox = true;
            }
            if (str_starts_with($fqn, 'XPHP\\Generated\\App\\Containers\\Lst\\T_')) {
                $hasLst = true;
            }
        }
        self::assertTrue($hasBox, 'expected an outer Box specialization');
        self::assertTrue($hasLst, 'expected a transitive Lst specialization');
    }
}
