<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use XPHP\TestSupport\CompiledFixture;

/**
 * A generic method may carry a name that is a PHP keyword (`list`, `print`, …) — PHP permits every
 * semi-reserved word as a method name. The declaration scanner used to require a `T_STRING` name, so
 * the `<T>` clause after a keyword name was never stripped and reached php-parser as a raw parse
 * error; and the static call-site scanner rejected a keyword name after `::`, so `Reg::print::<int>()`
 * parse-errored too (the instance form `$r->list::<int>()` already worked, because `list`
 * re-tokenizes as `T_STRING` after `->`). Both gates now accept keyword names. This test compiles AND
 * executes the output to prove the specialized keyword-named methods are declared and callable.
 */
final class KeywordNamedGenericMethodTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testKeywordNamedGenericMethodSpecializesAndRunsViaBothCallSites(): void
    {
        // Instance `$r->list::<int>(41)` returns 41; static `Reg::print::<int>(7)` returns 7. A gate
        // that still rejected the keyword name would parse-error at compile, not reach this assertion.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/keyword_named_generic_method/source',
            'keyword-named-generic-method',
        );
        try {
            $fixture->registerAutoload('App');
            require __DIR__ . '/../../fixture/compile/keyword_named_generic_method/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testKeywordNamedNonGenericMethodsAndListDestructuringPassThroughUnchanged(): void
    {
        // Must-keep: the keyword-turbofish support fires only for `keyword::<…>` after `::`, so an
        // ordinary keyword-named non-generic method (`R::print(20)`, `$r->list(10)`) and a `list()`
        // destructuring must be untouched — never routed into the closure-signature / array-sugar
        // sub-branches, which would throw or mis-parse. list(10)+1=11, print(20)+2=22, unpack=7.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/keyword_nongeneric_passthrough/source',
            'keyword-nongeneric-passthrough',
        );
        try {
            $fixture->registerAutoload('App');
            require __DIR__ . '/../../fixture/compile/keyword_nongeneric_passthrough/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }
}
