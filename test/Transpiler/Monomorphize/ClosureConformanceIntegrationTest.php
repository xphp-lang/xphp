<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\TestSupport\CompiledFixture;

/**
 * End-to-end coverage for closure-signature conformance through the full
 * compile pipeline: a conforming source compiles, erases every `Closure(...)`
 * to `\Closure`, and the emitted PHP executes; a provably non-conforming source
 * fails the compile with the conformance diagnostic before any output is written.
 */
final class ClosureConformanceIntegrationTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testConformingClosuresCompileEraseAndRun(): void
    {
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_conformance_runtime/source',
            'closure-conformance-run',
        );
        try {
            require __DIR__ . '/../../fixture/compile/closure_conformance_runtime/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    public function testNonConformingClosureFailsCompilation(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('parameter 1: string is not wider than int');

        CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_conformance_reject/source',
            'closure-conformance-reject',
        );
    }

    #[RunInSeparateProcess]
    public function testGroundedGenericClosureConformsWhenTypeParameterResolves(): void
    {
        // `Closure(T): T` grounds to `Closure(int): int` under `Box<int>`; the
        // conforming factory compiles, erases, and runs.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_conformance_grounded_runtime/source',
            'closure-conformance-grounded-run',
        );
        $fixture->registerAutoload('App\\ClosureGroundRuntime\\');
        try {
            require __DIR__ . '/../../fixture/compile/closure_conformance_grounded_runtime/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    public function testGroundedGenericClosureFailsWhenTypeParameterResolvesToAConflict(): void
    {
        // Gradually accepted at the abstract template, but once `Box<int>` grounds
        // `Closure(T $x): T` to `Closure(int $x): int` the `string`-parameter
        // literal is a provable contravariance violation.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('parameter 1: string is not wider than int');

        CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_conformance_grounded_reject/source',
            'closure-conformance-grounded-reject',
        );
    }
}
