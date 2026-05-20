<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
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
        self::assertMatchesRegularExpression('/T_[0-9a-f]{64}$/', $fqn);
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

    public function testCustomHashLengthShortensClassName(): void
    {
        $registry = new Registry(hashLength: 16);

        $instantiation = $registry->recordInstantiation('App\\Containers\\Box', [new TypeRef('App\\Models\\Plastic')]);
        self::assertMatchesRegularExpression('/T_[0-9a-f]{16}$/', $instantiation->generatedFqn);
    }

    public function testCustomHashLengthStillCollisionDistinct(): void
    {
        $reg = new Registry(hashLength: 16);
        $reg->recordInstantiation('App\\Containers\\Box', [new TypeRef('App\\Models\\Plastic')]);
        $reg->recordInstantiation('App\\Containers\\Box', [new TypeRef('App\\Models\\Metal')]);

        self::assertCount(2, $reg->instantiations());
    }

    public function testStaticGeneratedFqnAcceptsHashLength(): void
    {
        $fqn = Registry::generatedFqn('App\\Box', [new TypeRef('App\\Plastic')], 32);
        self::assertMatchesRegularExpression('/T_[0-9a-f]{32}$/', $fqn);
    }

    public function testConstructorRejectsBelowMinimum(): void
    {
        self::expectException(\InvalidArgumentException::class);
        new Registry(hashLength: 15);
    }

    public function testConstructorRejectsOverMax(): void
    {
        self::expectException(\InvalidArgumentException::class);
        new Registry(hashLength: 65);
    }

    public function testResolveFromEnvReturnsDefaultWhenUnset(): void
    {
        $previous = getenv('XPHP_HASH_LENGTH');
        putenv('XPHP_HASH_LENGTH');
        try {
            self::assertSame(Registry::DEFAULT_HASH_HEX_LENGTH, Registry::resolveHashLengthFromEnv());
        } finally {
            $previous === false ? putenv('XPHP_HASH_LENGTH') : putenv("XPHP_HASH_LENGTH={$previous}");
        }
    }

    public function testResolveFromEnvParsesValidInteger(): void
    {
        $previous = getenv('XPHP_HASH_LENGTH');
        putenv('XPHP_HASH_LENGTH=24');
        try {
            self::assertSame(24, Registry::resolveHashLengthFromEnv());
        } finally {
            $previous === false ? putenv('XPHP_HASH_LENGTH') : putenv("XPHP_HASH_LENGTH={$previous}");
        }
    }

    public function testResolveFromEnvThrowsOnNonNumeric(): void
    {
        $previous = getenv('XPHP_HASH_LENGTH');
        putenv('XPHP_HASH_LENGTH=abc');
        try {
            $this->expectException(\InvalidArgumentException::class);
            // Tight message match kills the Throw_ removal (the bare `new ...` without throw
            // would later fall through to validateHashLength((int)'abc'=0), throwing a
            // different exception with the "between 16 and 64" message instead.
            $this->expectExceptionMessage('XPHP_HASH_LENGTH must be a positive integer; got "abc".');
            Registry::resolveHashLengthFromEnv();
        } finally {
            $previous === false ? putenv('XPHP_HASH_LENGTH') : putenv("XPHP_HASH_LENGTH={$previous}");
        }
    }

    public function testResolveFromEnvThrowsOnOutOfRange(): void
    {
        $previous = getenv('XPHP_HASH_LENGTH');
        putenv('XPHP_HASH_LENGTH=100');
        try {
            $this->expectException(\InvalidArgumentException::class);
            Registry::resolveHashLengthFromEnv();
        } finally {
            $previous === false ? putenv('XPHP_HASH_LENGTH') : putenv("XPHP_HASH_LENGTH={$previous}");
        }
    }

    public function testCollisionTriggersHelpfulRuntimeException(): void
    {
        // Real sha256 collisions at length 16+ are infeasible to find by brute force, so we
        // simulate one by directly seeding the internal map with a fake entry that occupies
        // the FQCN slot the next recordInstantiation() call will produce.
        $registry = new Registry(hashLength: 16);

        $newArgs = [new TypeRef('App\\Models\\Metal')];
        $collidingFqn = Registry::generatedFqn('App\\Containers\\Box', $newArgs, 16);

        // Seed: pretend Box<Plastic> was already recorded at the Box<Metal> hash slot.
        $reflection = new \ReflectionClass($registry);
        $prop = $reflection->getProperty('instantiations');
        $prop->setValue($registry, [
            $collidingFqn => new GenericInstantiation(
                'App\\Containers\\Box',
                [new TypeRef('App\\Models\\Plastic')],
                $collidingFqn,
            ),
        ]);

        try {
            $registry->recordInstantiation('App\\Containers\\Box', $newArgs);
            self::fail('expected RuntimeException for hash collision');
        } catch (\RuntimeException $e) {
            // Exact message match locks the order and presence of every concat operand.
            // This kills the 5 Concat / ConcatOperandRemoval mutations in collisionMessage()
            // that previously slipped through substring-only assertions.
            $expected = "Hash collision detected while monomorphizing generics.\n\n"
                . "Two distinct instantiations produced the same specialized FQCN:\n"
                . "  existing : App\\Containers\\Box<App\\Models\\Plastic>\n"
                . "  new      : App\\Containers\\Box<App\\Models\\Metal>\n"
                . "  collision: {$collidingFqn}\n\n"
                . "The current XPHP_HASH_LENGTH = 16 is too short for this codebase.\n"
                . "Increase it (max 64, the full sha256 digest) and re-run, e.g.:\n\n"
                . "    XPHP_HASH_LENGTH=32 bin/xphp compile <source> <target> <cache>\n";
            self::assertSame($expected, $e->getMessage());
        }
    }

    public function testCollisionMessageSuggestsMaxWhenAlreadyAtHigherLength(): void
    {
        $registry = new Registry(hashLength: 48);

        $newArgs = [new TypeRef('App\\Models\\Metal')];
        $collidingFqn = Registry::generatedFqn('App\\Containers\\Box', $newArgs, 48);

        $reflection = new \ReflectionClass($registry);
        $prop = $reflection->getProperty('instantiations');
        $prop->setValue($registry, [
            $collidingFqn => new GenericInstantiation(
                'App\\Containers\\Box',
                [new TypeRef('App\\Models\\Plastic')],
                $collidingFqn,
            ),
        ]);

        try {
            $registry->recordInstantiation('App\\Containers\\Box', $newArgs);
            self::fail('expected collision exception');
        } catch (\RuntimeException $e) {
            // 48 * 2 = 96, clamped to MAX (64)
            self::assertStringContainsString('XPHP_HASH_LENGTH=64 bin/xphp compile', $e->getMessage());
        }
    }

    public function testIdempotentRecordingDoesNotTriggerCollisionCheck(): void
    {
        $registry = new Registry();
        $a = $registry->recordInstantiation('App\\Containers\\Box', [new TypeRef('App\\Models\\Plastic')]);
        $b = $registry->recordInstantiation('App\\Containers\\Box', [new TypeRef('App\\Models\\Plastic')]);
        self::assertSame($a, $b);
    }

    // -----------------------------------------------------------------------
    // A5: ltrim normalization — leading backslashes on template / arg FQNs
    // must be stripped so `\App\Box` and `App\Box` are treated as identical.
    // -----------------------------------------------------------------------

    public function testLeadingBackslashOnTemplateFqnIsNormalized(): void
    {
        $withSlash = Registry::generatedFqn('\\App\\Containers\\Box', [new TypeRef('App\\Plastic')]);
        $without   = Registry::generatedFqn('App\\Containers\\Box', [new TypeRef('App\\Plastic')]);
        self::assertSame($without, $withSlash);
    }

    public function testLeadingBackslashOnArgFqnIsNormalized(): void
    {
        $withSlash = Registry::generatedFqn('App\\Box', [new TypeRef('\\App\\Plastic')]);
        $without   = Registry::generatedFqn('App\\Box', [new TypeRef('App\\Plastic')]);
        self::assertSame($without, $withSlash);
    }

    public function testRecordInstantiationTreatsBackslashFormsAsIdentical(): void
    {
        $registry = new Registry();
        $a = $registry->recordInstantiation('\\App\\Box', [new TypeRef('\\App\\Plastic')]);
        $b = $registry->recordInstantiation('App\\Box',   [new TypeRef('App\\Plastic')]);

        self::assertSame($a->generatedFqn, $b->generatedFqn);
        self::assertCount(1, $registry->instantiations(), 'leading backslash must not produce a duplicate entry');
    }

    public function testIsSameInstantiationIgnoresLeadingBackslashOnTemplateFqn(): void
    {
        $registry = new Registry();
        $registry->recordInstantiation('App\\Containers\\Box', [new TypeRef('App\\Models\\Plastic')]);

        // Recording the same instantiation via the leading-backslash form must hit the
        // "same instantiation" branch in isSameInstantiation (which uses ltrim on $existing->templateFqn);
        // without ltrim, the comparison would fail and a hash collision would be falsely reported.
        $second = $registry->recordInstantiation('\\App\\Containers\\Box', [new TypeRef('\\App\\Models\\Plastic')]);

        self::assertCount(1, $registry->instantiations());
        self::assertSame('App\\Containers\\Box', $second->templateFqn);
    }

    // -----------------------------------------------------------------------
    // A6: static generatedFqn validation — kills `MethodCallRemoval` of
    //     self::validateHashLength inside generatedFqn().
    // -----------------------------------------------------------------------

    public function testStaticGeneratedFqnRejectsBelowMinimum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Registry::generatedFqn('App\\Box', [new TypeRef('App\\Plastic')], 0);
    }

    public function testStaticGeneratedFqnRejectsOverMax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Registry::generatedFqn('App\\Box', [new TypeRef('App\\Plastic')], 100);
    }

    // -----------------------------------------------------------------------
    // A1: Registry::toArray() shape — lock every expected key so
    //     ArrayItemRemoval / ArrayItem (=> -> >) / UnwrapArrayMap mutations
    //     cannot escape silently.
    // -----------------------------------------------------------------------

    public function testToArrayEmitsExpectedShapeForDefinitionsAndInstantiations(): void
    {
        $registry = new Registry();

        $templateAst = new Class_(new Identifier('Box'));
        $registry->recordDefinition(
            'App\\Containers\\Box',
            'Box',
            ['T'],
            $templateAst,
            '/some/source/Box.xphp',
        );
        $registry->recordInstantiation('App\\Containers\\Box', [new TypeRef('App\\Models\\Plastic')]);

        $out = $registry->toArray();

        // ---- definitions ----
        self::assertCount(1, $out['definitions']);
        $def = $out['definitions'][0];
        self::assertSame(['name', 'typeParams', 'sourceFile'], array_keys($def), 'definition keys must be exactly these three');
        self::assertSame('App\\Containers\\Box', $def['name']);
        self::assertSame(['T'], $def['typeParams']);
        self::assertSame('/some/source/Box.xphp', $def['sourceFile']);

        // ---- instantiations ----
        self::assertCount(1, $out['instantiations']);
        $inst = $out['instantiations'][0];
        self::assertSame(['template', 'concreteTypes', 'generatedFqn'], array_keys($inst), 'instantiation keys must be exactly these three');
        self::assertSame('App\\Containers\\Box', $inst['template']);
        // concreteTypes must be a list of display strings (UnwrapArrayMap would leave raw TypeRefs).
        self::assertIsArray($inst['concreteTypes']);
        self::assertSame(['App\\Models\\Plastic'], $inst['concreteTypes']);
        self::assertStringStartsWith('XPHP\\Generated\\App\\Containers\\Box\\T_', $inst['generatedFqn']);
    }

    public function testToArraySerializesNestedGenericArgAsDisplayString(): void
    {
        $registry = new Registry();
        $lstOfPlastic = new TypeRef('App\\Containers\\Lst', [new TypeRef('App\\Models\\Plastic')]);
        $registry->recordInstantiation('App\\Containers\\Box', [$lstOfPlastic]);

        $out = $registry->toArray();

        // Find the outer Box<Lst<Plastic>> entry; concreteTypes should be the angle-bracket form.
        $boxEntry = null;
        foreach ($out['instantiations'] as $entry) {
            if ($entry['template'] === 'App\\Containers\\Box') {
                $boxEntry = $entry;
                break;
            }
        }
        self::assertNotNull($boxEntry);
        self::assertSame(
            ['App\\Containers\\Lst<App\\Models\\Plastic>'],
            $boxEntry['concreteTypes'],
        );
    }
}
