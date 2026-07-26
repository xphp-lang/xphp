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
 * The turbofish shapes that deliberately stay un-groundable reach the emit phase with their
 * marker intact: a *concrete* inner closure turbofish inside a generic function body, a static
 * method-generic declared on a *different* generic template, and the late-bound
 * `static::`/`parent::` spellings. Left to emit, each produces PHP that references a
 * non-existent type-parameter class or the wrong dispatch target behind an otherwise clean
 * compile. The backstop turns each into a loud compile failure carrying
 * `xphp.unspecialized_generic_leak` before any output is written.
 *
 * (Adjacent shapes are handled elsewhere: a generic closure grounded by an enclosing *function*
 * parameter, `relay`, is a non-concrete variable turbofish caught earlier at the source seam in
 * both modes — see {@see ClosureDispatcherIntegrationTest} and {@see CheckPassIntegrationTest};
 * a NAMED free-function forward (`identity::<T>` inside `wrap<T>`) is grounded by the
 * append-drain — see `generic_function_named_forward` in {@see GenericFunctionIntegrationTest};
 * and an own-template / non-generic-target method turbofish grounded by the enclosing class
 * parameter (`self::gen::<T>`, `Maker::wrap::<T>`) is grounded per specialization — see
 * `generic_class_method_turbofish` in {@see GenericMethodIntegrationTest}.)
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
    public function testCrossTemplateStaticTurbofishIsRejected(): void
    {
        // A static method-generic declared on a DIFFERENT generic template
        // (`Other::gen::<T>` from inside `Holder<T>`): the grounding pass deliberately
        // leaves the marker (it would need Other's own substitution mapping, and
        // appending onto a shared template mid-loop is order-dependent), so the
        // backstop rejects. The own-template and non-generic-target forms of the same
        // call shape ground and run — see the generic_class_method_turbofish fixture.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(GenericMarkerLeakGuard::CODE);

        CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/generic_class_cross_template_turbofish_reject/source',
            'generic-class-cross-template-turbofish',
        );
    }

    #[RunInSeparateProcess]
    public function testStaticPseudoNameTurbofishIsRejected(): void
    {
        // `static::gen::<T>`: honoring late static binding is impossible for the
        // grounding pass, and resolving `static` to the current class would silently
        // re-route a subclass dispatch — keep-marker + loud reject is the contract.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(GenericMarkerLeakGuard::CODE);

        CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/generic_class_static_pseudo_turbofish_reject/source',
            'generic-class-static-pseudo-turbofish',
        );
    }

    #[RunInSeparateProcess]
    public function testParentPseudoNameTurbofishIsRejected(): void
    {
        // `parent::gen::<T>`: same contract as `static::` — the current-class mapping
        // would dispatch to the wrong side of the hierarchy, so the marker is kept.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(GenericMarkerLeakGuard::CODE);

        CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/generic_class_parent_pseudo_turbofish_reject/source',
            'generic-class-parent-pseudo-turbofish',
        );
    }
}
