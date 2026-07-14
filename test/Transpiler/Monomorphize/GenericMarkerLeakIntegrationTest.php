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
 * Two enclosing-parameter / generic-function-scope turbofish shapes slip past the source-level
 * gates and reach the emit phase un-grounded: a *concrete* inner closure turbofish inside a
 * generic function body, and a method turbofish grounded by an enclosing class type parameter.
 * Left to emit, each produces PHP that references a non-existent type-parameter class (a runtime
 * `TypeError`/`Error`) behind an otherwise clean compile. The backstop turns each into a loud
 * compile failure carrying `xphp.unspecialized_generic_leak` before any output is written.
 *
 * (The third shape — a generic closure grounded by an enclosing *function* parameter, `relay` —
 * is a non-concrete variable turbofish caught earlier at the source seam in both modes; see
 * {@see ClosureDispatcherIntegrationTest} and {@see CheckPassIntegrationTest}.)
 *
 * The must-keep side — a working top-level `$g::<int>` dispatcher and the `contains<U : E>`
 * enclosing-bound forward — is proven zero-false-reject by the existing `closure_dispatcher_arrow`
 * and `enclosing_bound_erasure_forwarding` runtime fixtures, which compile and execute green with
 * this backstop active.
 */
final class GenericMarkerLeakIntegrationTest extends TestCase
{
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
