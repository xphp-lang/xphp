<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use XPHP\TestSupport\CompiledFixture;

/**
 * A generic trait used with an adaptation block (`insteadof` / `as`) specializes its
 * `use`-LIST names but used to leave the adaptation OPERAND names bare, so they resolved
 * to the removed template (`App\A`) and fataled at class load behind a clean compile.
 * These tests compile AND execute the adapted class, so a bare operand would fatal here.
 */
final class GenericTraitAdaptationTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testCrossStatementInsteadofAndAliasResolveAtRuntime(): void
    {
        // Two generic traits used in SEPARATE `use` statements; the conflict is resolved
        // in the second block, whose operands name traits from BOTH statements (the
        // class-scoped map). insteadof picks A::pick, `as` re-exposes B::pick as bpick.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/generic_trait_adaptation/source',
            'generic-trait-adaptation',
        );
        try {
            $fixture->registerAutoload('App');
            $runtime = require __DIR__ . '/../../fixture/compile/generic_trait_adaptation/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testMixedGenericAndPlainTraitAdaptationResolvesAtRuntime(): void
    {
        // A plain trait mixed with two generic traits, both generics excluded in one
        // `insteadof`. The plain operand must stay bare (a real trait); the generic
        // operands rewrite to their specializations.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/generic_trait_adaptation_mixed/source',
            'generic-trait-adaptation-mixed',
        );
        try {
            $fixture->registerAutoload('App');
            $runtime = require __DIR__ . '/../../fixture/compile/generic_trait_adaptation_mixed/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }
}
