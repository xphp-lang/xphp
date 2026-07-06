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

    #[RunInSeparateProcess]
    public function testExceptionFactoryAgainstBuiltinThrowableTargetCompilesAndRuns(): void
    {
        // Regression: a user subclass of the built-in \Exception returned against a
        // Closure(): \Throwable target must not be false-rejected (the hierarchy
        // models no built-in ancestor edges).
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_conformance_builtin_ok/source',
            'closure-conformance-builtin',
        );
        $fixture->registerAutoload('App\\ClosureBuiltinOk\\');
        try {
            require __DIR__ . '/../../fixture/compile/closure_conformance_builtin_ok/verify/runtime.php';
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

    public function testGroundedUnionMemberFailsWhenTypeParameterResolvesToAConflict(): void
    {
        // `Closure(): T|int` grounds to `string|int` under `Box<string>`; the class
        // return literal is provably neither member, so the union member grounding
        // turns a gradual accept at the template into a build failure.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not a subtype of string|int');

        CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_conformance_grounded_union_reject/source',
            'closure-conformance-grounded-union',
        );
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
