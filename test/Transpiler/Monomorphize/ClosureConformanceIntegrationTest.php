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
    public function testDnfGroupedSignaturesCompileEraseAndRun(): void
    {
        // DNF groups (`(A&B)|C`) in a signature's parameter and return: the
        // group scans as one gradual leaf, the arity stays correct (the factory
        // literal is accepted, not arity-false-rejected), the signature fully
        // erases, and the compiled output executes.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_conformance_dnf_runtime/source',
            'closure-conformance-dnf',
        );
        try {
            require __DIR__ . '/../../fixture/compile/closure_conformance_dnf_runtime/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testUserFunctionNamedClosureInExpressionColonsExecutes(): void
    {
        // A user function named `Closure` called after a ternary `:`, inside an
        // alt-syntax `if (...):` block, and after a `case expr():` label — the
        // `) :` pairs those positions produce must not be read as return-type
        // slots; the calls compile untouched and RUN.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_named_user_function_runtime/source',
            'closure-named-user-fn',
        );
        try {
            require __DIR__ . '/../../fixture/compile/closure_named_user_function_runtime/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testArraySugarSignaturesCompileEraseAndRun(): void
    {
        // Array-sugar leaves in a signature's parameter and return lower to
        // `array`: the arity stays correct (one sugared param is ONE param,
        // not three), the signature fully erases, and the output executes.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_conformance_array_sugar_runtime/source',
            'closure-conformance-sugar',
        );
        try {
            require __DIR__ . '/../../fixture/compile/closure_conformance_array_sugar_runtime/verify/runtime.php';
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
    public function testConformingClosureArgumentCompilesEraseAndRuns(): void
    {
        // A conforming closure literal handed to a grounded `Closure(Book): string`
        // instance-method parameter compiles, erases to `\Closure`, and executes.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_arg_instance_runtime/source',
            'closure-arg-instance-run',
        );
        $fixture->registerAutoload('App\\ClosureArgRun\\');
        try {
            require __DIR__ . '/../../fixture/compile/closure_arg_instance_runtime/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    public function testNonConformingClosureArgumentFailsCompilation(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('parameter 1: int is not wider than App\\ClosureArgReject\\Book');

        CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_arg_instance_reject/source',
            'closure-arg-instance-reject',
        );
    }

    #[RunInSeparateProcess]
    public function testConformingStaticClosureArgumentCompilesEraseAndRuns(): void
    {
        // A conforming closure literal handed to a grounded `Closure(int): int`
        // static-method parameter compiles, erases to `\Closure`, and executes.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_arg_static_runtime/source',
            'closure-arg-static-run',
        );
        $fixture->registerAutoload('App\\ClosureArgStaticRun\\');
        try {
            require __DIR__ . '/../../fixture/compile/closure_arg_static_runtime/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    public function testNonConformingStaticClosureArgumentFailsCompilation(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('parameter 1: string is not wider than int');

        CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_arg_static_reject/source',
            'closure-arg-static-reject',
        );
    }

    #[RunInSeparateProcess]
    public function testConformingFreeFunctionClosureArgumentCompilesEraseAndRuns(): void
    {
        // A conforming closure literal handed to a grounded `Closure(int): int` generic
        // free-function parameter compiles, erases to `\Closure`, and executes.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_arg_free_fn_runtime/source',
            'closure-arg-free-fn-run',
        );
        $fixture->registerAutoload('App\\ClosureArgFnRun\\');
        try {
            require __DIR__ . '/../../fixture/compile/closure_arg_free_fn_runtime/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    public function testNonConformingFreeFunctionClosureArgumentFailsCompilation(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('parameter 1: string is not wider than int');

        CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_arg_free_fn_reject/source',
            'closure-arg-free-fn-reject',
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
