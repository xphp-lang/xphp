<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit matrix for the pure conformance engine: candidate closure signature vs.
 * target `Closure(...)` type. `check()` returns null when the candidate conforms
 * OR the mismatch is not provable (gradual accept), and a violation only for a
 * PROVABLE mismatch. The cardinal rule: never a false reject.
 */
final class ClosureSignatureConformanceTest extends TestCase
{
    // ---- Arity -----------------------------------------------------------

    public function testEqualArityConforms(): void
    {
        self::assertConforms(
            self::sig([self::p(self::ref('int')), self::p(self::ref('int'))], self::ref('int')),
            self::sig([self::p(self::ref('int')), self::p(self::ref('int'))], self::ref('int')),
        );
    }

    public function testCandidateWithFewerParametersIsRejected(): void
    {
        self::assertViolation(
            ClosureConformanceViolation::KIND_ARITY_TOO_FEW,
            self::sig([self::p(self::ref('int'))], self::ref('int')),          // candidate: 1 param
            self::sig([self::p(self::ref('int')), self::p(self::ref('int'))], self::ref('int')), // target: 2
        );
    }

    public function testCandidateRequiringMoreThanTargetGuaranteesIsRejected(): void
    {
        self::assertViolation(
            ClosureConformanceViolation::KIND_ARITY_REQUIRES_MORE,
            self::sig([self::p(self::ref('int')), self::p(self::ref('int'))], self::ref('int')), // candidate requires 2
            self::sig([self::p(self::ref('int'))], self::ref('int')),                            // target guarantees 1
        );
    }

    public function testCandidateExtraOptionalParameterConforms(): void
    {
        // fn(int $x, int $y = 0) accepts a 1-arg call — conforms to Closure(int): int.
        self::assertConforms(
            self::sig([self::p(self::ref('int')), self::p(self::ref('int'), optional: true)], self::ref('int')),
            self::sig([self::p(self::ref('int'))], self::ref('int')),
        );
    }

    public function testVariadicCandidateAbsorbsExtraTargetParameters(): void
    {
        self::assertConforms(
            self::sig([self::p(self::ref('int'), variadic: true)], self::ref('int')),
            self::sig([self::p(self::ref('int')), self::p(self::ref('int'))], self::ref('int')),
        );
    }

    public function testVariadicTargetRequiresVariadicCandidate(): void
    {
        // target Closure(int, ...int): a caller may pass 1, 2, 3… args; a fixed
        // 1-arg candidate that matches the required arity still can't absorb the
        // tail, so it needs its own variadic.
        self::assertViolation(
            ClosureConformanceViolation::KIND_VARIADIC_REQUIRED,
            self::sig([self::p(self::ref('int'))], self::ref('int')),  // fixed candidate (arity 1 OK)
            self::sig([self::p(self::ref('int')), self::p(self::ref('int'), variadic: true)], self::ref('int')),
        );
    }

    // ---- Parameters: contravariant --------------------------------------

    public function testWiderCandidateParameterConforms(): void
    {
        // target param Apple; candidate param Fruit (wider) — contravariant OK.
        self::assertConforms(
            self::sig([self::p(self::ref('App\\Fruit'))], self::ref('int')),
            self::sig([self::p(self::ref('App\\Apple'))], self::ref('int')),
        );
    }

    public function testNarrowerCandidateParameterIsRejected(): void
    {
        // target param Fruit; candidate param Apple (narrower) — provable violation.
        self::assertViolation(
            ClosureConformanceViolation::KIND_PARAM_TYPE,
            self::sig([self::p(self::ref('App\\Apple'))], self::ref('int')),
            self::sig([self::p(self::ref('App\\Fruit'))], self::ref('int')),
        );
    }

    public function testUnrelatedClassParameterIsRejected(): void
    {
        self::assertViolation(
            ClosureConformanceViolation::KIND_PARAM_TYPE,
            self::sig([self::p(self::ref('App\\Apple'))], self::ref('int')),
            self::sig([self::p(self::ref('App\\Orange'))], self::ref('int')),
        );
    }

    public function testScalarParameterMismatchIsRejected(): void
    {
        self::assertViolation(
            ClosureConformanceViolation::KIND_PARAM_TYPE,
            self::sig([self::p(self::ref('string'))], self::ref('int')),
            self::sig([self::p(self::ref('int'))], self::ref('int')),
        );
    }

    public function testUntypedCandidateParameterConformsGradually(): void
    {
        // fn($x) => ... : untyped param ⇒ mixed ⇒ always conforms.
        self::assertConforms(
            self::sig([self::p(self::ref('mixed'))], self::ref('int')),
            self::sig([self::p(self::ref('int'))], self::ref('int')),
        );
    }

    // ---- Return: covariant ----------------------------------------------

    public function testNarrowerReturnConforms(): void
    {
        self::assertConforms(
            self::sig([], self::ref('App\\Apple')),   // candidate returns Apple
            self::sig([], self::ref('App\\Fruit')),   // target returns Fruit
        );
    }

    public function testWiderReturnIsRejected(): void
    {
        self::assertViolation(
            ClosureConformanceViolation::KIND_RETURN_TYPE,
            self::sig([], self::ref('App\\Fruit')),   // candidate returns Fruit (wider)
            self::sig([], self::ref('App\\Apple')),   // target returns Apple
        );
    }

    public function testAbsentCandidateReturnConformsGradually(): void
    {
        self::assertConforms(
            self::sig([], null),                      // candidate: no return type
            self::sig([], self::ref('int')),
        );
    }

    public function testAbsentTargetReturnAcceptsAnyCandidateReturn(): void
    {
        self::assertConforms(
            self::sig([], self::ref('int')),
            self::sig([], null),
        );
    }

    // ---- By-reference: exact --------------------------------------------

    public function testByRefCandidateInByValueSlotIsRejected(): void
    {
        self::assertViolation(
            ClosureConformanceViolation::KIND_BYREF,
            self::sig([self::p(self::ref('int'), byRef: true)], self::ref('int')),
            self::sig([self::p(self::ref('int'))], self::ref('int')),
        );
    }

    public function testByValueCandidateInByRefSlotIsRejected(): void
    {
        self::assertViolation(
            ClosureConformanceViolation::KIND_BYREF,
            self::sig([self::p(self::ref('int'))], self::ref('int')),
            self::sig([self::p(self::ref('int'), byRef: true)], self::ref('int')),
        );
    }

    // ---- Pseudo-type leaves: every one must ACCEPT (would false-reject
    //      if routed through isSubtype). ------------------------------------

    #[DataProvider('pseudoTypeAcceptPairs')]
    public function testPseudoTypeLeavesAreAccepted(string $candidateReturn, string $targetReturn): void
    {
        self::assertConforms(
            self::sig([], self::ref($candidateReturn)),
            self::sig([], self::ref($targetReturn)),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function pseudoTypeAcceptPairs(): iterable
    {
        yield 'class candidate vs object target' => ['App\\Fruit', 'object'];
        yield 'object candidate vs class target' => ['object', 'App\\Fruit'];
        yield 'never candidate return' => ['never', 'App\\Fruit'];
        yield 'array candidate vs iterable target' => ['array', 'iterable'];
        yield 'mixed target' => ['int', 'mixed'];
        yield 'mixed candidate' => ['mixed', 'int'];
        yield 'callable vs Closure' => ['callable', 'App\\Closurish'];
        yield 'static leaf' => ['static', 'App\\Fruit'];
        yield 'self leaf' => ['self', 'App\\Fruit'];
    }

    public function testTrueAndFalseNormalizeToBool(): void
    {
        self::assertConforms(self::sig([], self::ref('true')), self::sig([], self::ref('bool')));
        self::assertConforms(self::sig([], self::ref('false')), self::sig([], self::ref('bool')));
    }

    // ---- Gradual: type-param, SigRaw, unresolved class ------------------

    public function testUngroundedTypeParameterLeafIsAccepted(): void
    {
        self::assertConforms(
            self::sig([self::p(self::refParam('T'))], self::refParam('U')),
            self::sig([self::p(self::ref('int'))], self::ref('string')),
        );
    }

    public function testRawUnionLeafIsAcceptedGradually(): void
    {
        self::assertConforms(
            self::sig([self::p(new SigRaw('A|B'))], self::ref('int')),
            self::sig([self::p(self::ref('App\\Apple'))], self::ref('int')),
        );
    }

    public function testUnresolvedClassIsAcceptedGradually(): void
    {
        // Neither class is in the hierarchy ⇒ isSubtype null ⇒ accept.
        self::assertConforms(
            self::sig([], self::ref('App\\Unknown\\Foo')),
            self::sig([], self::ref('App\\Unknown\\Bar')),
        );
    }

    // ---- Nested closure leaf: variance flips ----------------------------

    public function testNestedClosureParameterFlipsVariance(): void
    {
        // target param: Closure(Apple): int  ; candidate param: Closure(Fruit): int
        // A parameter position is contravariant, and a nested closure's own params
        // are contravariant — the two flips compose so the candidate's inner param
        // (Fruit) being WIDER than the target's inner param (Apple) is a REJECT
        // (the candidate promises to accept a narrower inner arg than the target).
        $targetInner = self::sig([self::p(self::ref('App\\Apple'))], self::ref('int'));
        $candidateInner = self::sig([self::p(self::ref('App\\Fruit'))], self::ref('int'));

        $violation = self::engine()->check(
            self::sig([self::p(self::cl($candidateInner))], self::ref('int')),
            self::sig([self::p(self::cl($targetInner))], self::ref('int')),
        );
        self::assertNotNull($violation);
        self::assertSame(ClosureConformanceViolation::KIND_PARAM_TYPE, $violation->kind);
        self::assertSame('parameter 1: Closure(...) is not wider than Closure(...)', $violation->detail);
    }

    public function testNestedClosureReturnConforms(): void
    {
        // return position: candidate Closure(Fruit): Apple vs target Closure(Apple): Fruit
        // inner param contravariant (Fruit wider than Apple: OK), inner return
        // covariant (Apple narrower than Fruit: OK) — conforms.
        $candidateInner = self::sig([self::p(self::ref('App\\Fruit'))], self::ref('App\\Apple'));
        $targetInner = self::sig([self::p(self::ref('App\\Apple'))], self::ref('App\\Fruit'));

        self::assertConforms(
            self::sig([], self::cl($candidateInner)),
            self::sig([], self::cl($targetInner)),
        );
    }

    public function testClosureCandidateVsScalarTargetIsRejected(): void
    {
        self::assertViolation(
            ClosureConformanceViolation::KIND_RETURN_TYPE,
            self::sig([], self::cl(self::sig([], self::ref('int')))),
            self::sig([], self::ref('int')),
        );
    }

    // ---- checkStructural stops at arity/by-ref, ignores type rules -------

    public function testCheckStructuralIgnoresTypeMismatch(): void
    {
        // A narrower param (type violation) but matching arity/by-ref: structural
        // pass is clean; full check rejects.
        $candidate = self::sig([self::p(self::ref('App\\Apple'))], self::ref('int'));
        $target = self::sig([self::p(self::ref('App\\Fruit'))], self::ref('int'));

        self::assertNull(self::engine()->checkStructural($candidate, $target));
        self::assertNotNull(self::engine()->check($candidate, $target));
    }

    public function testCheckStructuralStillCatchesArity(): void
    {
        self::assertSame(
            ClosureConformanceViolation::KIND_ARITY_TOO_FEW,
            self::engine()->checkStructural(
                self::sig([], self::ref('int')),
                self::sig([self::p(self::ref('int'))], self::ref('int')),
            )?->kind,
        );
    }

    public function testVariadicCandidateTailTypeIsCheckedOnOverflowPositions(): void
    {
        // candidate Closure(int, Fruit ...$rest); target Closure(int, Apple, string).
        // Position 0 uses the FIXED candidate param (int); the overflow position 2
        // falls back to the variadic tail (Fruit) and provably mismatches `string`.
        self::assertViolation(
            ClosureConformanceViolation::KIND_PARAM_TYPE,
            self::sig([self::p(self::ref('int')), self::p(self::ref('App\\Fruit'), variadic: true)], self::ref('int')),
            self::sig([self::p(self::ref('int')), self::p(self::ref('App\\Apple')), self::p(self::ref('string'))], self::ref('int')),
        );
    }

    public function testVariadicCandidateFixedParameterIsUsedBeforeTail(): void
    {
        // candidate Closure(Fruit, int ...$rest); target Closure(Apple, int). At
        // position 0 the FIXED candidate param (Fruit, wider than Apple) must be
        // used — NOT the variadic tail (int) — so this conforms. Reading the tail
        // at a fixed position would wrongly reject (Apple vs int).
        self::assertConforms(
            self::sig([self::p(self::ref('App\\Fruit')), self::p(self::ref('int'), variadic: true)], self::ref('int')),
            self::sig([self::p(self::ref('App\\Apple')), self::p(self::ref('int'))], self::ref('int')),
        );
    }

    public function testVariadicCandidateTailByRefIsCheckedOnOverflowPositions(): void
    {
        // candidate Closure(int, int ...$rest by-value); target Closure(int, int, int&).
        // The overflow position 2 falls back to the by-value tail and mismatches the
        // target's by-ref param.
        self::assertViolation(
            ClosureConformanceViolation::KIND_BYREF,
            self::sig([self::p(self::ref('int')), self::p(self::ref('int'), variadic: true)], self::ref('int')),
            self::sig([self::p(self::ref('int')), self::p(self::ref('int')), self::p(self::ref('int'), byRef: true)], self::ref('int')),
        );
    }

    public function testVariadicCandidateFixedByRefIsUsedBeforeTail(): void
    {
        // candidate Closure(int&, int ...$rest by-value); target Closure(int&, int).
        // Position 0 must use the FIXED by-ref param (matches the target's by-ref);
        // reading the by-value tail there would wrongly reject.
        self::assertConforms(
            self::sig([self::p(self::ref('int'), byRef: true), self::p(self::ref('int'), variadic: true)], self::ref('int')),
            self::sig([self::p(self::ref('int'), byRef: true), self::p(self::ref('int'))], self::ref('int')),
        );
    }

    public function testRequiredParameterAfterOptionalStillCountsTowardArity(): void
    {
        // A required parameter following an optional one lowers nothing: the leading
        // optional means required arity is 0, so this conforms to a 0-param target;
        // but the presence of a later required param must not be silently dropped —
        // against a target that guarantees 0, requiring even one is too many only if
        // it is *leading*. Here the optional leads, so arity is 0 → conforms.
        self::assertConforms(
            self::sig([self::p(self::ref('int'), optional: true), self::p(self::ref('int'))], self::ref('int')),
            self::sig([], self::ref('int')),
        );
    }

    public function testRawUnionOnTargetSideIsAcceptedGradually(): void
    {
        self::assertConforms(
            self::sig([], self::ref('App\\Apple')),
            self::sig([], new SigRaw('A|B')),
        );
    }

    public function testClosureCandidateVsClassTargetIsAccepted(): void
    {
        // A closure value vs a non-scalar leaf (a class / object) is gradually
        // accepted — only a true-scalar target is a provable mismatch.
        self::assertConforms(
            self::sig([], self::cl(self::sig([], self::ref('int')))),
            self::sig([], self::ref('App\\Closurish')),
        );
    }

    public function testKnownClassAgainstUndeclaredTargetClassIsAccepted(): void
    {
        // Regression: isSubtype(knownChild, undeclaredParent) returns a hard false
        // (BFS never reaches the unknown); it must NOT be read as a proven mismatch.
        self::assertConforms(
            self::sig([], self::ref('App\\Apple')),          // known
            self::sig([], self::ref('App\\External\\Thing')), // undeclared
        );
    }

    public function testUndeclaredCandidateAgainstKnownTargetClassIsAccepted(): void
    {
        self::assertConforms(
            self::sig([], self::ref('App\\External\\Thing')), // undeclared
            self::sig([], self::ref('App\\Fruit')),           // known
        );
    }

    public function testBuiltinReturnTargetIsAcceptedGradually(): void
    {
        // Regression (false-reject): the hierarchy models no ancestor edges for
        // PHP built-ins, so isSubtype('Exception','Throwable') returns a hard false.
        // A built-in target must NOT be read as a proven mismatch — Exception really
        // IS a Throwable. Covariant return: candidate Exception, target Throwable.
        self::assertConforms(
            self::sig([], self::ref('Exception')),
            self::sig([], self::ref('Throwable')),
        );
    }

    public function testUserSubclassOfBuiltinAgainstBuiltinReturnTargetIsAccepted(): void
    {
        // App\MyExc extends the built-in Exception; its ancestry escapes into the
        // unmodeled built-in graph, so it is really a Throwable but BFS can't prove it.
        self::assertConforms(
            self::sig([], self::ref('App\\MyExc')),
            self::sig([], self::ref('Throwable')),
        );
    }

    public function testBuiltinParameterTargetIsAcceptedGradually(): void
    {
        // Contravariant parameter: target Exception, candidate Throwable. Throwable
        // is genuinely wider than Exception, but the relation runs through the
        // built-in graph — accept rather than false-reject.
        self::assertConforms(
            self::sig([self::p(self::ref('Throwable'))], self::ref('void')),
            self::sig([self::p(self::ref('Exception'))], self::ref('void')),
        );
    }

    // ---- Union / intersection member variance (WI-03) --------------------

    public function testUnionTargetParameterRejectsTooNarrowCandidate(): void
    {
        // target Closure(int|string $x); candidate fn(int $x) can't accept a string
        // the target may pass — sub-union OR finds the string arm provably-not.
        self::assertViolation(
            ClosureConformanceViolation::KIND_PARAM_TYPE,
            self::sig([self::p(self::ref('int'))], null),                                    // candidate
            self::sig([self::p(self::union(self::ref('int'), self::ref('string')))], null),  // target
        );
    }

    public function testUnionParameterAcceptsEqualUnionRegardlessOfMemberOrder(): void
    {
        // The order-independence case that a super-first decomposition would false-
        // reject: candidate string|int vs target int|string must conform.
        self::assertConforms(
            self::sig([self::p(self::union(self::ref('string'), self::ref('int')))], null),
            self::sig([self::p(self::union(self::ref('int'), self::ref('string')))], null),
        );
    }

    public function testNarrowerReturnConformsToUnionReturn(): void
    {
        // candidate return int; target return int|string — int fits the union.
        self::assertConforms(
            self::sig([], self::ref('int')),
            self::sig([], self::union(self::ref('int'), self::ref('string'))),
        );
    }

    public function testReturnOutsideUnionIsRejected(): void
    {
        // candidate return float; target return int|string — provably neither.
        self::assertViolation(
            ClosureConformanceViolation::KIND_RETURN_TYPE,
            self::sig([], self::ref('float')),
            self::sig([], self::union(self::ref('int'), self::ref('string'))),
        );
    }

    public function testUnionReturnWithGradualMemberStaysGradual(): void
    {
        // candidate return App\Apple; target return int|App\External\Thing. Apple is
        // provably not an int, but the undeclared Thing arm can't be disproven — so
        // the union stays gradual and accepts (no false reject). A scalar candidate
        // would instead reject, since a scalar is provably no class at all.
        self::assertConforms(
            self::sig([], self::ref('App\\Apple')),
            self::sig([], self::union(self::ref('int'), self::ref('App\\External\\Thing'))),
        );
    }

    public function testNarrowerReturnConformsToNullableUnionReturn(): void
    {
        // ?int modelled as int|null; candidate return int is a subtype — accept.
        self::assertConforms(
            self::sig([], self::ref('int')),
            self::sig([], self::union(self::ref('int'), self::ref('null'))),
        );
    }

    public function testSuperIntersectionReturnRejectsNonMember(): void
    {
        // candidate return App\Fruit; target return App\Apple & App\Closurish —
        // Fruit is provably not an Apple, so the intersection is not satisfied.
        self::assertViolation(
            ClosureConformanceViolation::KIND_RETURN_TYPE,
            self::sig([], self::ref('App\\Fruit')),
            self::sig([], self::intersection(self::ref('App\\Apple'), self::ref('App\\Closurish'))),
        );
    }

    public function testSuperIntersectionWithUndeclaredMemberStaysGradual(): void
    {
        // One member undeclared ⇒ the whole intersection is unprovable ⇒ accept.
        self::assertConforms(
            self::sig([], self::ref('App\\Apple')),
            self::sig([], self::intersection(self::ref('App\\Apple'), self::ref('App\\External\\Thing'))),
        );
    }

    public function testSubIntersectionParameterIsAcceptedEvenWhenUninhabited(): void
    {
        // CRITICAL cardinal-rule guard: target Closure(Apple&Orange $x) is
        // uninhabited (two concrete siblings ⇒ `never`), and `never` is a subtype of
        // everything, so ANY candidate parameter is vacuously wide. The sub-side
        // intersection must stay gradual, never decompose into a reject.
        self::assertConforms(
            self::sig([self::p(self::ref('App\\Closurish'))], null),
            self::sig([self::p(self::intersection(self::ref('App\\Apple'), self::ref('App\\Orange')))], null),
        );
    }

    public function testSubIntersectionParameterAcceptedAgainstUnionCandidate(): void
    {
        // Pins the sub-intersection-vs-union-super accept path: target parameter
        // Apple&Orange (uninhabited ⇒ gradual sub side) checked contravariantly against
        // a candidate parameter Apple|Orange. Decomposing the sub side would false-reject;
        // it must stay gradual through the super-union arm.
        self::assertConforms(
            self::sig([self::p(self::union(self::ref('App\\Apple'), self::ref('App\\Orange')))], null),
            self::sig([self::p(self::intersection(self::ref('App\\Apple'), self::ref('App\\Orange')))], null),
        );
    }

    public function testSubIntersectionParameterAcceptedAgainstIntersectionCandidate(): void
    {
        // Sub-intersection-vs-intersection-super accept path: the sub-side intersection
        // must stay gradual as the super-intersection arm recurses over each member.
        self::assertConforms(
            self::sig([self::p(self::intersection(self::ref('App\\Apple'), self::ref('App\\Closurish')))], null),
            self::sig([self::p(self::intersection(self::ref('App\\Apple'), self::ref('App\\Orange')))], null),
        );
    }

    public function testSubIntersectionReturnAcceptedAgainstUnionTarget(): void
    {
        // The return analog: candidate return Apple&Orange (gradual sub side) checked
        // covariantly against a union target return Apple|Orange. Stays gradual.
        self::assertConforms(
            self::sig([], self::intersection(self::ref('App\\Apple'), self::ref('App\\Orange'))),
            self::sig([], self::union(self::ref('App\\Apple'), self::ref('App\\Orange'))),
        );
    }

    public function testViolationDetailRendersUnionMembers(): void
    {
        $violation = self::engine()->check(
            self::sig([], self::ref('float')),
            self::sig([], self::union(self::ref('int'), self::ref('string'))),
        );
        self::assertNotNull($violation);
        self::assertSame(ClosureConformanceViolation::KIND_RETURN_TYPE, $violation->kind);
        self::assertSame('return type: float is not a subtype of int|string', $violation->detail);
    }

    public function testViolationDetailRendersIntersectionMembers(): void
    {
        $violation = self::engine()->check(
            self::sig([], self::ref('App\\Fruit')),
            self::sig([], self::intersection(self::ref('App\\Apple'), self::ref('App\\Closurish'))),
        );
        self::assertNotNull($violation);
        self::assertSame('return type: App\\Fruit is not a subtype of App\\Apple&App\\Closurish', $violation->detail);
    }

    public function testViolationDetailNamesThePositionAndBothTypes(): void
    {
        $violation = self::engine()->check(
            self::sig([self::p(self::ref('string'))], self::ref('int')),
            self::sig([self::p(self::ref('int'))], self::ref('int')),
        );
        self::assertNotNull($violation);
        self::assertSame(ClosureConformanceViolation::KIND_PARAM_TYPE, $violation->kind);
        self::assertSame('parameter 1: string is not wider than int', $violation->detail);
    }

    public function testReturnViolationDetailNamesBothTypes(): void
    {
        $violation = self::engine()->check(
            self::sig([], self::ref('App\\Fruit')),
            self::sig([], self::ref('App\\Apple')),
        );
        self::assertNotNull($violation);
        self::assertSame(ClosureConformanceViolation::KIND_RETURN_TYPE, $violation->kind);
        self::assertSame('return type: App\\Fruit is not a subtype of App\\Apple', $violation->detail);
    }

    public function testByRefViolationDetailNamesTheDirections(): void
    {
        $violation = self::engine()->check(
            self::sig([self::p(self::ref('int'), byRef: true)], self::ref('int')),
            self::sig([self::p(self::ref('int'))], self::ref('int')),
        );
        self::assertNotNull($violation);
        self::assertSame(ClosureConformanceViolation::KIND_BYREF, $violation->kind);
        self::assertSame('parameter 1: by-reference-ness must match exactly (target by-value, candidate by-ref)', $violation->detail);
    }

    // ---- checkTypesOnly (grounded re-check: type relations without structural) ----

    public function testCheckTypesOnlySkipsArityMismatch(): void
    {
        // The grounded pass must ignore arity — it was already decided abstract.
        $candidate = self::sig([self::p(self::ref('int'))], self::ref('int'));                 // 1 param
        $target = self::sig([self::p(self::ref('int')), self::p(self::ref('int'))], self::ref('int')); // 2 params
        self::assertNull(self::engine()->checkTypesOnly($candidate, $target));
        // ...whereas the full check reports the structural violation.
        self::assertSame(
            ClosureConformanceViolation::KIND_ARITY_TOO_FEW,
            self::engine()->check($candidate, $target)?->kind,
        );
    }

    public function testCheckTypesOnlySkipsByRefMismatch(): void
    {
        $candidate = self::sig([self::p(self::ref('int'), byRef: true)], self::ref('int'));
        $target = self::sig([self::p(self::ref('int'))], self::ref('int'));
        self::assertNull(self::engine()->checkTypesOnly($candidate, $target));
        self::assertSame(
            ClosureConformanceViolation::KIND_BYREF,
            self::engine()->check($candidate, $target)?->kind,
        );
    }

    public function testCheckTypesOnlyStillCatchesParameterMismatch(): void
    {
        // A narrower (sibling) candidate parameter is a provable contravariance
        // violation the grounded pass must still catch.
        $violation = self::engine()->checkTypesOnly(
            self::sig([self::p(self::ref('App\\Apple'))], self::ref('int')),  // candidate wants Apple
            self::sig([self::p(self::ref('App\\Fruit'))], self::ref('int')),  // target passes Fruit
        );
        self::assertNotNull($violation);
        self::assertSame(ClosureConformanceViolation::KIND_PARAM_TYPE, $violation->kind);
    }

    public function testCheckTypesOnlyStillCatchesReturnMismatch(): void
    {
        // A wider candidate return is a provable covariance violation.
        $violation = self::engine()->checkTypesOnly(
            self::sig([], self::ref('App\\Fruit')),  // candidate returns Fruit
            self::sig([], self::ref('App\\Apple')),  // target promises Apple
        );
        self::assertNotNull($violation);
        self::assertSame(ClosureConformanceViolation::KIND_RETURN_TYPE, $violation->kind);
    }

    // ---- Helpers ---------------------------------------------------------

    private static function engine(): ClosureSignatureConformance
    {
        // App\Apple <: App\Fruit, App\Orange <: App\Fruit; App\Closurish is a bare class.
        // App\MyExc extends the built-in Exception (its ancestry escapes into PHP's
        // unmodeled built-in graph).
        $hierarchy = new TypeHierarchy([
            'App\\Apple' => ['App\\Fruit'],
            'App\\Orange' => ['App\\Fruit'],
            'App\\Fruit' => [],
            'App\\Closurish' => [],
            'App\\MyExc' => ['Exception'],
        ]);
        return new ClosureSignatureConformance($hierarchy);
    }

    private static function assertConforms(ClosureSignature $candidate, ClosureSignature $target): void
    {
        $violation = self::engine()->check($candidate, $target);
        self::assertNull(
            $violation,
            'expected conformance but got: ' . ($violation?->detail ?? ''),
        );
    }

    private static function assertViolation(string $kind, ClosureSignature $candidate, ClosureSignature $target): void
    {
        $violation = self::engine()->check($candidate, $target);
        self::assertNotNull($violation, 'expected a violation but the pair conformed');
        self::assertSame($kind, $violation->kind, 'wrong violation kind (detail: ' . $violation->detail . ')');
    }

    /**
     * @param list<ClosureSignatureParam> $params
     */
    private static function sig(array $params, ?SigType $return, bool $nullable = false): ClosureSignature
    {
        return new ClosureSignature($params, $return, $nullable);
    }

    private static function p(SigType $type, bool $byRef = false, bool $variadic = false, bool $optional = false): ClosureSignatureParam
    {
        // Exercise the constructor's `$optional = false` default in the common case
        // (only pass it when true) so that default stays behaviorally pinned.
        return $optional
            ? new ClosureSignatureParam($type, $byRef, $variadic, true)
            : new ClosureSignatureParam($type, $byRef, $variadic);
    }

    private static function ref(string $name): SigTypeRef
    {
        $scalars = ['int', 'string', 'float', 'bool', 'true', 'false', 'mixed', 'object',
            'void', 'never', 'null', 'array', 'iterable', 'callable', 'self', 'static', 'parent'];
        return new SigTypeRef(new TypeRef($name, [], isScalar: in_array($name, $scalars, true)));
    }

    private static function refParam(string $name): SigTypeRef
    {
        return new SigTypeRef(new TypeRef($name, [], isTypeParam: true));
    }

    private static function cl(ClosureSignature $sig): SigClosure
    {
        return new SigClosure($sig);
    }

    private static function union(SigType ...$members): SigUnion
    {
        return new SigUnion(array_values($members));
    }

    private static function intersection(SigType ...$members): SigIntersection
    {
        return new SigIntersection(array_values($members));
    }
}
