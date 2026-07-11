<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\Diagnostics\DiagnosticCollector;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FilepathArray;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;

/**
 * End-to-end of the validate-only `Compiler::check()` path: it collects every generic
 * error in one run and halts after validation (never specializes or emits), so a
 * bound-violating source returns diagnostics instead of throwing.
 */
final class CheckPassIntegrationTest extends TestCase
{
    public function testMultipleErrorsAreCollectedInOneRun(): void
    {
        $diagnostics = $this->check('multi_error');

        self::assertTrue($diagnostics->hasErrors());
        self::assertCount(2, $diagnostics->all());
        foreach ($diagnostics->all() as $d) {
            self::assertSame(Registry::CODE_BOUND_VIOLATION, $d->code);
            self::assertNotNull($d->location);
            self::assertStringEndsWith('Use.xphp', $d->location->file);
        }
    }

    public function testCleanSourcesProduceNoDiagnostics(): void
    {
        $diagnostics = $this->check('clean');

        self::assertFalse($diagnostics->hasErrors());
        self::assertSame([], $diagnostics->all());
    }

    public function testDefaultBoundViolationIsCollectedByCheck(): void
    {
        // Exercises the validateDefaultsAgainstBounds() step of check().
        $diagnostics = $this->check('default_violation');

        self::assertCount(1, $diagnostics->all());
        self::assertSame(Registry::CODE_DEFAULT_BOUND_VIOLATION, $diagnostics->all()[0]->code);
    }

    public function testVariancePositionViolationIsCollectedByCheck(): void
    {
        // Exercises the validateVariancePositions() step of check().
        $diagnostics = $this->check('variance_violation');

        self::assertCount(1, $diagnostics->all());
        self::assertSame(VariancePositionValidator::CODE_VARIANCE_POSITION, $diagnostics->all()[0]->code);
    }

    public function testVarianceEdgeUnprovableIsReportedAsNonFailingWarning(): void
    {
        // A covariant template instantiated over an element type not in the source set:
        // the `extends` edge is silently dropped today; check now reports it as a
        // non-failing Warning at the instantiation site (so exit stays 0).
        $diagnostics = $this->check('variance_edge_unprovable');

        self::assertFalse($diagnostics->hasErrors(), 'a warning must not fail the gate');
        self::assertCount(1, $diagnostics->all());
        $d = $diagnostics->all()[0];
        self::assertSame(Registry::CODE_VARIANCE_EDGE_UNPROVABLE, $d->code);
        self::assertSame(\XPHP\Diagnostics\Severity::Warning, $d->severity);
        self::assertNotNull($d->location);
        self::assertStringEndsWith('Use.xphp', $d->location->file);
        self::assertSame(
            'Variance edge cannot be proven while instantiating App\Check\VarianceEdgeUnprovable\Producer<App\Check\VarianceEdgeUnprovable\Book>.
  type parameter out T is covariant, but App\Check\VarianceEdgeUnprovable\Book is not in the source set the hierarchy was built from (and is not a recognized PHP built-in),
  so the compiler cannot prove its subtype edges — this specialization is not linked to related ones and the covariant relationship silently does not apply at runtime.

  Add App\Check\VarianceEdgeUnprovable\Book to the source set the hierarchy is built from to enable the edge.',
            $d->message,
        );
    }

    public function testVarianceEdgeProvableTypesProduceNoWarning(): void
    {
        // Same covariant template, but the element type IS declared in the source set —
        // its edges are provable, so nothing is reported.
        $diagnostics = $this->check('variance_edge_provable');

        self::assertFalse($diagnostics->hasErrors());
        self::assertSame([], $diagnostics->all());
    }

    public function testCompileDoesNotFailOnUnprovableVarianceEdge(): void
    {
        // Compile has no warning sink; the unprovable edge is skipped exactly as before
        // (autoload-safe), and compilation succeeds without throwing.
        $work = sys_get_temp_dir() . '/xphp-check-compile-' . uniqid('', true);
        mkdir($work, 0o755, true);
        try {
            $result = $this->buildCompiler()->compile(
                $this->sources('variance_edge_unprovable'),
                $this->sourceDir('variance_edge_unprovable'),
                $work . '/dist',
                $work . '/cache',
            );
            self::assertGreaterThan(0, $result->generatedCount);
        } finally {
            self::rrmdir($work);
        }
    }

    public function testCompileStillThrowsOnVariancePositionViolation(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not allowed for covariant variance');

        $work = sys_get_temp_dir() . '/xphp-check-compile-' . uniqid('', true);
        mkdir($work, 0o755, true);
        try {
            $this->buildCompiler()->compile($this->sources('variance_violation'), $this->sourceDir('variance_violation'), $work . '/dist', $work . '/cache');
        } finally {
            self::rrmdir($work);
        }
    }

    public function testInnerVarianceViolationIsCollectedByCheck(): void
    {
        // Composition case the position check misses → only inner-variance reports it,
        // and the position check does NOT also flag it (no double report).
        $diagnostics = $this->check('inner_variance');

        self::assertCount(1, $diagnostics->all());
        $d = $diagnostics->all()[0];
        self::assertSame(InnerVarianceValidator::CODE_INNER_VARIANCE, $d->code);
        // Located at the `Container<T>` return type in P.xphp (line 12).
        self::assertNotNull($d->location);
        self::assertStringEndsWith('P.xphp', $d->location->file);
        self::assertSame(12, $d->location->line);
    }

    public function testMissingTypeArgumentIsCollectedByCheck(): void
    {
        $diagnostics = $this->check('missing_arg');

        self::assertCount(1, $diagnostics->all());
        self::assertSame(Registry::CODE_MISSING_TYPE_ARGUMENT, $diagnostics->all()[0]->code);
        self::assertNotNull($diagnostics->all()[0]->location);
    }

    public function testBareNewOfNonDefaultsGenericIsCollectedByCheck(): void
    {
        // `new Box(5)` where `Box<T>` has a required (non-defaulted) param and no
        // turbofish: cannot pad from defaults, so it is a missing-type-argument error
        // — same code and message as the call path, routed through padArgsWithDefaults.
        $diagnostics = $this->check('bare_new_missing_arg');

        self::assertCount(1, $diagnostics->all());
        $d = $diagnostics->all()[0];
        self::assertSame(Registry::CODE_MISSING_TYPE_ARGUMENT, $d->code);
        self::assertNotNull($d->location);
        self::assertStringEndsWith('Use.xphp', $d->location->file);
        self::assertSame(10, $d->location->line);
    }

    public function testBareNewQualifiedAndRelativeSpellingsAreBothCollected(): void
    {
        // FQ `new \…\Box(5)` and relative `new namespace\Box(6)` reject with the same
        // verdict as the bare spelling, and BOTH are collected in one run (check does
        // not throw-on-first).
        $diagnostics = $this->check('bare_new_missing_arg_qualified');

        self::assertCount(2, $diagnostics->all());
        foreach ($diagnostics->all() as $d) {
            self::assertSame(Registry::CODE_MISSING_TYPE_ARGUMENT, $d->code);
            self::assertNotNull($d->location);
        }
        self::assertSame([10, 11], array_map(
            static fn ($d): ?int => $d->location?->line,
            $diagnostics->all(),
        ));
    }

    public function testBareNewOfConditionalSameNamePlainClassIsNotRejected(): void
    {
        // False-reject guard: a plain `class B` (live branch) coexists with a generic `class B<T>`
        // (dead branch). `new B` resolves to the instantiable plain class, so the bare-new guard
        // must stay silent — rejecting the generic twin's name here would be a false reject.
        $diagnostics = $this->check('bare_new_conditional_same_name');

        self::assertSame([], $diagnostics->all());
    }

    public function testUndefinedTemplateIsCollectedByCheck(): void
    {
        // Exercises the collectUndefinedTemplates() step of check().
        $diagnostics = $this->check('undefined_template');

        self::assertCount(1, $diagnostics->all());
        self::assertSame(Registry::CODE_UNDEFINED_TEMPLATE, $diagnostics->all()[0]->code);
    }

    public function testGenericFunctionBoundViolationIsCollectedByCheck(): void
    {
        $diagnostics = $this->check('generic_function_bound');

        self::assertCount(1, $diagnostics->all());
        $d = $diagnostics->all()[0];
        self::assertSame(Registry::CODE_BOUND_VIOLATION, $d->code);
        self::assertNotNull($d->location);
        self::assertStringEndsWith('Use.xphp', $d->location->file);
        self::assertSame(8, $d->location->line);
    }

    public function testGenericMethodMissingArgumentIsCollectedByCheck(): void
    {
        $diagnostics = $this->check('generic_method_missing_arg');

        self::assertCount(1, $diagnostics->all());
        $d = $diagnostics->all()[0];
        self::assertSame(Registry::CODE_MISSING_TYPE_ARGUMENT, $d->code);
        self::assertNotNull($d->location);
        self::assertSame(9, $d->location->line);
    }

    public function testDuplicateGenericFunctionIsCollectedByCheck(): void
    {
        // Two functions re-declared in the second file → BOTH duplicates collected in one run
        // (the loop must `continue`, not `break`, after the first).
        $diagnostics = $this->check('duplicate_generic_function');

        self::assertCount(2, $diagnostics->all());
        foreach ($diagnostics->all() as $d) {
            self::assertSame(GenericMethodCompiler::CODE_DUPLICATE_GENERIC_FUNCTION, $d->code);
            self::assertNotNull($d->location);
            self::assertStringEndsWith('b.xphp', $d->location->file);
        }
    }

    public function testThisCapturingGenericClosureIsCollectedByCheck(): void
    {
        $diagnostics = $this->check('closure_this_capture');

        self::assertCount(1, $diagnostics->all());
        $d = $diagnostics->all()[0];
        self::assertSame(GenericMethodCompiler::CODE_UNSUPPORTED_THIS_CAPTURE, $d->code);
        self::assertNotNull($d->location);
        self::assertSame(17, $d->location->line);
    }

    public function testStaticGenericClosureIsCollectedByCheck(): void
    {
        $diagnostics = $this->check('closure_static');

        self::assertCount(1, $diagnostics->all());
        $d = $diagnostics->all()[0];
        self::assertSame(GenericMethodCompiler::CODE_UNSUPPORTED_STATIC_CLOSURE, $d->code);
        self::assertNotNull($d->location);
        self::assertSame(12, $d->location->line);
    }

    public function testCompileStillThrowsOnStaticGenericClosure(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('static closures cannot yet be specialized');
        $this->compileFixture('closure_static');
    }

    public function testAttributedStaticGenericClosureIsCollectedByCheck(): void
    {
        // The attribute moves the closure node's start to `#[`; the generic
        // marker must still bind there, so the static-closure reject FIRES —
        // before, the marker was lost and the closure silently compiled raw.
        $diagnostics = $this->check('closure_static_attributed');

        self::assertCount(1, $diagnostics->all());
        $d = $diagnostics->all()[0];
        self::assertSame(GenericMethodCompiler::CODE_UNSUPPORTED_STATIC_CLOSURE, $d->code);
        self::assertNotNull($d->location);
        self::assertSame(19, $d->location->line);
    }

    public function testCompileStillThrowsOnAttributedStaticGenericClosure(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('static closures cannot yet be specialized');
        $this->compileFixture('closure_static_attributed');
    }

    public function testUnspecializedGenericClosuresAreCollectedByCheck(): void
    {
        // Declared-but-never-turbofish-called generic closures across every
        // position (assigned, return, argument, use-capturing, defaulted,
        // unused-param, conditional else-arm) each draw ONE diagnostic; the
        // called twins draw none. Note the eager static/$this rejects also
        // stay single diagnostics (their templates count as attempted) —
        // pinned by the closure_static / closure_this_capture tests' counts.
        $diagnostics = $this->check('closure_unspecialized');

        $all = $diagnostics->all();
        self::assertCount(7, $all);
        $arrowMsg = 'Generic arrow function is declared with type parameters but never specialized: no `$var::<...>(...)` call grounds them, so its type-parameter hints would reach the emitted code as references to non-existent classes. Call it with an explicit turbofish, or remove the `<...>` clause.';
        $closureMsg = 'Generic closure is declared with type parameters but never specialized: no `$var::<...>(...)` call grounds them, so its type-parameter hints would reach the emitted code as references to non-existent classes. Call it with an explicit turbofish, or remove the `<...>` clause.';
        $byLine = [];
        foreach ($all as $d) {
            self::assertSame(GenericMethodCompiler::CODE_UNSPECIALIZED_GENERIC_CLOSURE, $d->code);
            self::assertContains($d->message, [$arrowMsg, $closureMsg]);
            self::assertNotNull($d->location);
            $byLine[$d->location->line] = $d->message;
        }
        ksort($byLine);
        // The static arrow, return-position arrow, argument-position closure,
        // use-capturing closure, defaulted closure, unused-param arrow, and
        // the conditional else-arm arrow — NOT the called twins.
        self::assertSame([12, 16, 19, 22, 24, 27, 30], array_keys($byLine));
        self::assertSame($arrowMsg, $byLine[12]);
        self::assertSame($closureMsg, $byLine[19]);
    }

    public function testCompileThrowsOnUnspecializedGenericClosure(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('never specialized');
        $this->compileFixture('closure_unspecialized');
    }

    public function testUnresolvedGenericMethodTurbofishIsCollectedByCheck(): void
    {
        // A turbofish call to a generic method that exists nowhere on the receiver
        // or its ancestors is a collected error (not a silent pass-through that
        // would fatal at runtime).
        $diagnostics = $this->check('unresolved_generic_method');

        self::assertCount(1, $diagnostics->all());
        $d = $diagnostics->all()[0];
        self::assertSame(GenericMethodCompiler::CODE_UNRESOLVED_GENERIC_CALL, $d->code);
        self::assertNotNull($d->location);
        self::assertSame(16, $d->location->line);
        self::assertSame(
            'Generic method `App\Check\UnresolvedGenericMethod\Box::nope::<...>()` could not be resolved to a declared generic method on `App\Check\UnresolvedGenericMethod\Box`. Check the method name or the receiver\'s type.',
            $d->message,
        );
    }

    public function testCompileStillThrowsOnUnresolvedGenericMethodTurbofish(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not be resolved');
        $this->compileFixture('unresolved_generic_method');
    }

    public function testClassAndMethodLevelErrorsAreBothCollectedInOneRun(): void
    {
        // Proves the validation-superset guarantee: a class-level check (variance) AND a
        // method-level check (generic-function bound) both report in a single `check` run.
        $diagnostics = $this->check('method_and_class_errors');

        $codes = array_map(static fn ($d): string => $d->code, $diagnostics->all());
        self::assertContains(VariancePositionValidator::CODE_VARIANCE_POSITION, $codes);
        self::assertContains(Registry::CODE_BOUND_VIOLATION, $codes);
    }

    public function testCompileStillThrowsOnGenericFunctionBound(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Generic bound violated');
        $this->compileFixture('generic_function_bound');
    }

    public function testUndeclaredTypeParametersAreCollected(): void
    {
        // Two stray type names (`T`, `U`) in one template → both reported in one run;
        // the declared `Z`, the scalar `int`, and the in-source class `Box` are clean.
        $diagnostics = $this->check('undeclared_type_param');

        self::assertCount(2, $diagnostics->all());
        $byName = [];
        foreach ($diagnostics->all() as $d) {
            self::assertSame(UndeclaredTypeParameterValidator::CODE_UNDECLARED_TYPE, $d->code);
            self::assertNotNull($d->location);
            self::assertStringEndsWith('CollectionInterface.xphp', $d->location->file);
            preg_match('/Type `(\w+)`/', $d->message, $m);
            $byName[$m[1]] = $d->location->line;
        }
        self::assertSame(['T', 'U'], array_keys($byName));
        self::assertSame(11, $byName['T']); // `add(T $element)` line
        self::assertSame(13, $byName['U']); // `wrap(U $value)` line
    }

    public function testUndeclaredTypesAreCaughtInEveryMemberPosition(): void
    {
        // property, constructor-promoted param, return, nullable param, union return,
        // intersection param, and a nested closure signature — each stray name is flagged.
        $diagnostics = $this->check('undeclared_type_param_positions');

        $names = [];
        foreach ($diagnostics->all() as $d) {
            self::assertSame(UndeclaredTypeParameterValidator::CODE_UNDECLARED_TYPE, $d->code);
            preg_match('/Type `(\w+)`/', $d->message, $m);
            $names[$m[1]] = true;
        }
        ksort($names);
        self::assertSame(
            ['Clo', 'CloRet', 'InA', 'InB', 'Nul', 'Promo', 'Prop', 'Ret', 'UnA', 'UnB'],
            array_keys($names),
        );
    }

    public function testUndeclaredTypesInMethodAndFunctionGenericsAreCollected(): void
    {
        // A generic method on a plain class and a free generic function — both
        // outside any generic template — are validated by the method-level pass.
        $diagnostics = $this->check('undeclared_type_param_method');

        $contexts = [];
        foreach ($diagnostics->all() as $d) {
            self::assertSame(UndeclaredTypeParameterValidator::CODE_UNDECLARED_TYPE, $d->code);
            self::assertNotNull($d->location);
            preg_match('/Type `(\w+)` used in (.+?) is not/', $d->message, $m);
            $contexts[$m[1]] = $m[2];
        }
        ksort($contexts);
        self::assertSame(['B', 'C', 'D', 'E'], array_keys($contexts));
        self::assertSame('method `pick`', $contexts['B']);
        self::assertSame('function `wrap`', $contexts['C']);
        self::assertSame('closure', $contexts['D']);
        self::assertSame('arrow function', $contexts['E']);
    }

    public function testGenericMethodInsideGenericTemplateIsReportedExactlyOnce(): void
    {
        // The class-member walk owns it; the method-level pass skips generics nested
        // in a generic template — so no double report.
        $diagnostics = $this->check('undeclared_type_param_nested_method');

        self::assertCount(1, $diagnostics->all());
        self::assertSame(UndeclaredTypeParameterValidator::CODE_UNDECLARED_TYPE, $diagnostics->all()[0]->code);
        self::assertSame(
            'Type `Stray` used in template `App\NestedMethod\Box` is not a declared type parameter and does not resolve to a known class, interface, or trait. Declare it as a type parameter, or import (`use`) / fully-qualify it if it names a real type.',
            $diagnostics->all()[0]->message,
        );
    }

    public function testCompileStillThrowsOnUndeclaredTypeInGenericFunction(): void
    {
        // The first finding in source order is `B` in method `pick`.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Type `B` used in method `pick`');
        $this->compileFixture('undeclared_type_param_method');
    }

    public function testUndeclaredNameInABoundIsCollected(): void
    {
        $diagnostics = $this->check('undeclared_bound');

        self::assertCount(1, $diagnostics->all());
        self::assertSame(UndeclaredTypeParameterValidator::CODE_UNDECLARED_TYPE, $diagnostics->all()[0]->code);
        self::assertSame(
            'Type `Nonexistent` used in template `App\BadBound\Box` is not a declared type parameter and does not resolve to a known class, interface, or trait. Declare it as a type parameter, or import (`use`) / fully-qualify it if it names a real type.',
            $diagnostics->all()[0]->message,
        );
    }

    public function testUndeclaredNameInADefaultIsCollected(): void
    {
        $diagnostics = $this->check('undeclared_default');

        self::assertCount(1, $diagnostics->all());
        self::assertSame(UndeclaredTypeParameterValidator::CODE_UNDECLARED_TYPE, $diagnostics->all()[0]->code);
        self::assertSame(
            'Type `Nonexistent` used in template `App\BadDefault\Pair` is not a declared type parameter and does not resolve to a known class, interface, or trait. Declare it as a type parameter, or import (`use`) / fully-qualify it if it names a real type.',
            $diagnostics->all()[0]->message,
        );
    }

    public function testUndeclaredNamesInIntersectionBoundAndGenericArgAreCollected(): void
    {
        // `Bad1` is a leaf of an intersection bound; `Bad2` is a type argument of a
        // declared generic bound (`Holder<Bad2>`). Both stray names are reported.
        $diagnostics = $this->check('undeclared_bound_nested');

        $names = [];
        foreach ($diagnostics->all() as $d) {
            self::assertSame(UndeclaredTypeParameterValidator::CODE_UNDECLARED_TYPE, $d->code);
            preg_match('/Type `(\w+)`/', $d->message, $m);
            $names[$m[1]] = true;
        }
        ksort($names);
        self::assertSame(['Bad1', 'Bad2'], array_keys($names));
    }

    public function testBuiltinImportedAndParamRefBoundsAndDefaultsAreClean(): void
    {
        $diagnostics = $this->check('undeclared_bound_clean');

        self::assertFalse($diagnostics->hasErrors());
        self::assertSame([], $diagnostics->all());
    }

    public function testSameUndeclaredNameInBoundAndDefaultIsReportedOnce(): void
    {
        $diagnostics = $this->check('undeclared_bound_dedup');

        self::assertCount(1, $diagnostics->all());
        self::assertSame(
            'Type `Bad` used in template `App\DupBound\Dup` is not a declared type parameter and does not resolve to a known class, interface, or trait. Declare it as a type parameter, or import (`use`) / fully-qualify it if it names a real type.',
            $diagnostics->all()[0]->message,
        );
    }

    public function testCompileStillThrowsOnUndeclaredBound(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Type `Nonexistent` used in template `App\\BadBound\\Box`');
        $this->compileFixture('undeclared_bound');
    }

    public function testTooManyTypeArgumentsAreCollected(): void
    {
        // Box declares one parameter; two instantiations pass two args each → two
        // findings (not silent truncation), at their call-site lines.
        $diagnostics = $this->check('too_many_type_args');

        self::assertCount(2, $diagnostics->all());
        $lines = [];
        foreach ($diagnostics->all() as $d) {
            self::assertSame(Registry::CODE_TOO_MANY_TYPE_ARGUMENTS, $d->code);
            self::assertNotNull($d->location);
            self::assertStringEndsWith('Use.xphp', $d->location->file);
            $lines[] = $d->location->line;
        }
        sort($lines);
        self::assertSame([8, 9], $lines);
    }

    public function testCompileStillThrowsOnTooManyTypeArguments(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('declares 1 type parameter(s) but was instantiated with 2');
        $this->compileFixture('too_many_type_args');
    }

    public function testImportedAndFullyQualifiedTypesAreNotFlaggedAsUndeclared(): void
    {
        $diagnostics = $this->check('undeclared_type_param_escape');

        self::assertFalse($diagnostics->hasErrors());
        self::assertSame([], $diagnostics->all());
    }

    public function testCompileStillThrowsOnUndeclaredTypeParameter(): void
    {
        // Throws the FIRST finding (the `T` in add(), before `U` in wrap()), not a
        // silent broken emit.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Type `T` used in template `App\\Undeclared\\CollectionInterface`');
        $this->compileFixture('undeclared_type_param');
    }

    public function testCompileStillThrowsOnGenericMethodMissingArgument(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no default');
        $this->compileFixture('generic_method_missing_arg');
    }

    public function testCompileThrowsOnBareNewOfNonDefaultsGeneric(): void
    {
        // Compile mode (no collector) throws the identical missing-type-argument message
        // the call path throws — confirms the shared reporter keeps the throw path intact.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no default');
        $this->compileFixture('bare_new_missing_arg');
    }

    public function testCompileStillThrowsOnDuplicateGenericFunction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already declared');
        $this->compileFixture('duplicate_generic_function');
    }

    public function testCompileStillThrowsOnThisCapturingGenericClosure(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('captures `$this`');
        $this->compileFixture('closure_this_capture');
    }

    public function testCompileStillThrowsOnUndefinedTemplate(): void
    {
        // The same condition is a hard error in compile-mode (no collector) — confirms the
        // shared message builder keeps the throw path intact.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('was instantiated but never defined');

        $work = sys_get_temp_dir() . '/xphp-check-compile-' . uniqid('', true);
        mkdir($work, 0o755, true);
        try {
            $this->buildCompiler()->compile($this->sources('undefined_template'), $this->sourceDir('undefined_template'), $work . '/dist', $work . '/cache');
        } finally {
            self::rrmdir($work);
        }
    }

    public function testParseTimeVarianceOnMethodReportsRealLine(): void
    {
        // A parser-stage rejection (variance marker on a method) is caught in check mode
        // and must report the offending token's real source line, not the line-1 fallback
        // used for position-less parse failures. `eat<out T>` sits on line 9.
        $diagnostics = $this->check('parse_line_variance_method');

        self::assertCount(1, $diagnostics->all());
        $d = $diagnostics->all()[0];
        self::assertSame(Compiler::CODE_PARSE_ERROR, $d->code);
        self::assertSame(
            'Variance markers `out T` / `in T` are not supported on methods, functions, closures, or arrow functions — variance is a class-level-only feature by design: a function or closure specialization has no stable class identity to anchor a subtype `extends` edge to. Move the generic to a class-level type parameter.',
            $d->message,
        );
        self::assertNotNull($d->location);
        self::assertSame(9, $d->location->line);
    }

    public function testParseTimeLegacyVarianceGlyphReportsRealLine(): void
    {
        // The `+T` legacy-variance rejection fires from a different throw site; it too
        // carries its token line. `class Box<+T>` sits on line 7.
        $diagnostics = $this->check('parse_line_legacy_variance');

        self::assertCount(1, $diagnostics->all());
        $d = $diagnostics->all()[0];
        self::assertSame(Compiler::CODE_PARSE_ERROR, $d->code);
        self::assertSame(
            'The `+T` / `-T` variance syntax was replaced by `out T` / `in T`. Write `out` for covariance and `in` for contravariance, e.g. `class Box<out T>` or `class Consumer<in T>`.',
            $d->message,
        );
        self::assertNotNull($d->location);
        self::assertSame(7, $d->location->line);
    }

    public function testParseTimeDefaultOrderingReportsRealLine(): void
    {
        // The required-after-defaulted rejection anchors to the offending parameter's
        // name line (`class Pair<T = int, U>` on line 9), proving the name-line path.
        $diagnostics = $this->check('parse_line_default_ordering');

        self::assertCount(1, $diagnostics->all());
        $d = $diagnostics->all()[0];
        self::assertSame(Compiler::CODE_PARSE_ERROR, $d->code);
        self::assertSame(
            'Generic parameter `U` has no default but follows a parameter with a default. Required type parameters must precede defaulted ones.',
            $d->message,
        );
        self::assertNotNull($d->location);
        self::assertSame(9, $d->location->line);
    }

    public function testParseTimeInvalidDefaultReportsRealLine(): void
    {
        // An invalid default shape (`T = ?int`) rejects from the `$tokens[$afterBound]`
        // (the `=`) throw site — a distinct index from the variance sites. Line 7.
        $diagnostics = $this->check('parse_line_invalid_default');

        self::assertCount(1, $diagnostics->all());
        $d = $diagnostics->all()[0];
        self::assertSame(Compiler::CODE_PARSE_ERROR, $d->code);
        self::assertSame(
            'Generic parameter `T` has an invalid default; only a single concrete or generic type is allowed after `=` (no nullable or union shapes).',
            $d->message,
        );
        self::assertNotNull($d->location);
        self::assertSame(7, $d->location->line);
    }

    public function testDefaultInClosureSignatureTypeIsCollectedAtRealLine(): void
    {
        // A default value in a `Closure(...)` TYPE is rejected at parse time; check
        // collects it as a clean parse-error diagnostic at the `=` line (11), rather
        // than silently mis-modeling the signature into phantom parameters (which
        // would false-reject the valid returned closure literal).
        $diagnostics = $this->check('closure_default_in_type');

        self::assertCount(1, $diagnostics->all());
        $d = $diagnostics->all()[0];
        self::assertSame(Compiler::CODE_PARSE_ERROR, $d->code);
        self::assertSame(
            'A Closure(...) signature type cannot give parameter $x a default value: a '
            . 'signature describes the callable\'s shape, not call-time values.',
            $d->message,
        );
        self::assertNotNull($d->location);
        self::assertSame(11, $d->location->line);
    }

    public function testParseTimeUnionDefaultReportsRealLine(): void
    {
        // A union default (`T = Foo | Bar`) rejects from the `$tokens[$afterDefault]`
        // (the `|`) throw site — again a distinct index. `class Slot<...>` on line 9.
        $diagnostics = $this->check('parse_line_union_default');

        self::assertCount(1, $diagnostics->all());
        $d = $diagnostics->all()[0];
        self::assertSame(Compiler::CODE_PARSE_ERROR, $d->code);
        self::assertSame(
            'Generic parameter `T` has an invalid default; only a single concrete or generic type is allowed after `=` (no nullable or union shapes).',
            $d->message,
        );
        self::assertNotNull($d->location);
        self::assertSame(9, $d->location->line);
    }

    public function testParseTimePositionlessRejectionFallsBackToLineOne(): void
    {
        // A structural rejection raised over parsed entries (a self-bound `T : T`) carries
        // no token position, so check mode collects it via the fallback catch at line 1 —
        // and it is collected, not propagated (the file is still reported, exit stays clean
        // of a fatal).
        $diagnostics = $this->check('parse_self_bound');

        self::assertCount(1, $diagnostics->all());
        $d = $diagnostics->all()[0];
        self::assertSame(Compiler::CODE_PARSE_ERROR, $d->code);
        self::assertSame(
            'Generic parameter `T` cannot use itself as a bound (self-reference detected in the bound expression). Use a nested form like `T : Box<T>` for F-bounded recursion, or remove the bound.',
            $d->message,
        );
        self::assertNotNull($d->location);
        self::assertSame(1, $d->location->line);
    }

    public function testCompileStillThrowsOnVarianceOnMethod(): void
    {
        // Compile mode catches the same rejection as a RuntimeException (XphpParseException
        // extends it) — the message path is unchanged; only check mode reads the line.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Variance markers');
        $this->compileFixture('parse_line_variance_method');
    }

    /**
     * A turbofish on a DYNAMICALLY-named method/static call (`$o->$m::<int>()`, its nullsafe and
     * variable-variable and static-dynamic siblings) cannot be monomorphized — the name is a runtime
     * value. Each used to silently drop the marker and strip the clause, leaving a bare dynamic call
     * against a method that now exists only in its `_T_<hash>` form (runtime fatal behind a clean
     * gate). Each now draws ONE parse-stage diagnostic at the receiver's real line (9 in each fixture).
     *
     * @return iterable<string, array{string}>
     */
    public static function dynamicTurbofishFixtures(): iterable
    {
        yield 'dynamic instance name' => ['dynamic_turbofish_instance'];
        yield 'nullsafe dynamic name' => ['dynamic_turbofish_nullsafe'];
        yield 'variable-variable' => ['dynamic_turbofish_varvar'];
        yield 'static dynamic name' => ['dynamic_turbofish_static'];
    }

    #[DataProvider('dynamicTurbofishFixtures')]
    public function testDynamicNameTurbofishIsRejectedWithRealLine(string $fixture): void
    {
        $diagnostics = $this->check($fixture);

        self::assertCount(1, $diagnostics->all());
        $d = $diagnostics->all()[0];
        self::assertSame(Compiler::CODE_PARSE_ERROR, $d->code);
        self::assertSame(
            'A turbofish (`::<…>`) on a dynamically-named method or static call cannot be monomorphized; the method name must be a literal identifier, not a variable',
            $d->message,
        );
        self::assertNotNull($d->location);
        self::assertSame(9, $d->location->line);
    }

    public function testCompileStillThrowsOnDynamicNameTurbofish(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dynamically-named method');
        $this->compileFixture('dynamic_turbofish_instance');
    }

    public function testAmbiguousGenericTraitOperandIsCollectedByCheck(): void
    {
        // `use A<int>, A<string>, B<int> { A::m insteadof B; }` — the bare operand `A`
        // matches two different specializations of `A`, which an `insteadof` clause
        // cannot disambiguate. Rewriting to an arbitrary one would silently pick a
        // trait; instead it draws ONE parse-stage diagnostic at the operand's line.
        $diagnostics = $this->check('generic_trait_ambiguous_operand');

        self::assertCount(1, $diagnostics->all());
        $d = $diagnostics->all()[0];
        self::assertSame(Compiler::CODE_PARSE_ERROR, $d->code);
        // The remedy names an actionable recourse (a trait-level rename is NOT possible).
        self::assertSame(
            'Ambiguous generic trait operand `A` in an adaptation clause: this class uses more than one specialization of that trait, and an `insteadof` / `as` clause names a trait, not a specialization, so it cannot say which one is meant. Use a single specialization of that trait in an adapted class.',
            $d->message,
        );
        self::assertNotNull($d->location);
        self::assertSame(13, $d->location->line);
    }

    public function testCompileStillThrowsOnAmbiguousGenericTraitOperand(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ambiguous generic trait operand');
        $this->compileFixture('generic_trait_ambiguous_operand');
    }

    private function compileFixture(string $fixture): void
    {
        $work = sys_get_temp_dir() . '/xphp-check-compile-' . uniqid('', true);
        mkdir($work, 0o755, true);
        try {
            $this->buildCompiler()->compile($this->sources($fixture), $this->sourceDir($fixture), $work . '/dist', $work . '/cache');
        } finally {
            self::rrmdir($work);
        }
    }

    public function testClosureConformanceViolationIsCollectedByCheck(): void
    {
        // Exercises the ClosureConformanceValidator step of check(): a return-site
        // closure literal whose parameter is narrower than the target guarantees.
        $diagnostics = $this->check('closure_conformance');

        self::assertCount(1, $diagnostics->all());
        self::assertSame(ClosureConformanceValidator::CODE, $diagnostics->all()[0]->code);
        self::assertSame(
            'Closure literal does not conform to the declared `Closure(...)` type: parameter 1: string is not wider than int',
            $diagnostics->all()[0]->message,
        );
    }

    private function check(string $fixture): DiagnosticCollector
    {
        return $this->buildCompiler()->check($this->sources($fixture));
    }

    private function sources(string $fixture): FilepathArray
    {
        return (new NativeFileFinder())
            ->find($this->sourceDir($fixture))
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
    }

    private function sourceDir(string $fixture): string
    {
        return realpath(__DIR__ . '/../../fixture/check/' . $fixture . '/source')
            ?: throw new RuntimeException("Fixture missing: {$fixture}");
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function buildCompiler(): Compiler
    {
        $phpParser = (new ParserFactory())->createForHostVersion();
        $printer = new StandardPrinter();
        $writer = new NativeFileWriter();

        return new Compiler(
            new NativeFileReader(),
            $writer,
            new XphpSourceParser($phpParser),
            new Specializer(),
            new SpecializedClassGenerator($printer, $writer),
            $printer,
        );
    }
}
