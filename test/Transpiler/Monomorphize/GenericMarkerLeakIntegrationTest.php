<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\TestSupport\CompiledFixture;

/**
 * End-to-end coverage for the emitted-generic-marker backstop ({@see GenericMarkerLeakGuard}).
 *
 * Three enclosing-parameter / generic-function-scope turbofish shapes cannot be grounded by the
 * current pipeline; left to emit they each produce PHP that references a non-existent
 * type-parameter class (a runtime `TypeError`/`Error`) behind an otherwise clean compile. The
 * backstop turns each into a loud compile failure carrying `xphp.unspecialized_generic_leak`
 * before any output is written.
 *
 * The must-keep side — a working top-level `$g::<int>` dispatcher and the `contains<U : E>`
 * enclosing-bound forward — is proven zero-false-reject by the existing `closure_dispatcher_arrow`
 * and `enclosing_bound_erasure_forwarding` runtime fixtures, which compile and execute green with
 * this backstop active.
 */
final class GenericMarkerLeakIntegrationTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testGenericClosureGroundedByEnclosingParamIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(GenericMarkerLeakGuard::CODE);

        CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/generic_closure_enclosing_param_leak_reject/source',
            'generic-closure-enclosing-param-leak',
        );
    }

    #[RunInSeparateProcess]
    public function testConcreteInnerTurbofishInsideAGenericFunctionIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(GenericMarkerLeakGuard::CODE);

        CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/generic_function_inner_turbofish_leak_reject/source',
            'generic-function-inner-turbofish-leak',
        );
    }

    #[RunInSeparateProcess]
    public function testMethodTurbofishGroundedByEnclosingClassParamIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(GenericMarkerLeakGuard::CODE);

        CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/generic_class_method_turbofish_leak_reject/source',
            'generic-class-method-turbofish-leak',
        );
    }
}
