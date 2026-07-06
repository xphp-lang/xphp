<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * Parsing + erasure of closure-signature types (`Closure(int $x): bool`).
 *
 * WI-01 scope: the scanner recognizes a `Closure(...)[: ret]` production in a
 * type slot, erases it to a bare `\Closure` (newline-preserving so line-keyed
 * markers stay aligned), and attaches the structured {@see ClosureSignature} as
 * an AST attribute for the later conformance validator. A flat union / intersection
 * / nullable leaf is structured into a {@see SigUnion} / {@see SigIntersection}; a
 * shape the flat splitter doesn't own (a DNF group, an intersection with a scalar)
 * stays raw ({@see SigRaw}). No conformance checking happens here.
 */
final class ClosureSignatureParseTest extends TestCase
{
    // ===================================================================
    // Shape: the attached ClosureSignature matches the source
    // ===================================================================

    public function testParsesNamedParametersAndReturn(): void
    {
        $sig = self::firstSig('<?php function f(Closure(int $x, string $y): bool $c) {}');

        self::assertNotNull($sig);
        self::assertCount(2, $sig->params);
        self::assertSame('int', self::refName($sig->params[0]->type));
        self::assertTrue(self::isScalarRef($sig->params[0]->type));
        self::assertSame('string', self::refName($sig->params[1]->type));
        self::assertSame('bool', self::refName($sig->return));
        self::assertFalse($sig->nullable);
    }

    public function testParametersMayOmitNames(): void
    {
        // Names are insignificant to conformance; a bare `Closure(int, int): int`
        // must parse to two params exactly like the named form.
        $sig = self::firstSig('<?php class C { public Closure(int, int): int $op; }');

        self::assertNotNull($sig);
        self::assertCount(2, $sig->params);
        self::assertSame('int', self::refName($sig->params[0]->type));
        self::assertSame('int', self::refName($sig->params[1]->type));
        self::assertSame('int', self::refName($sig->return));
    }

    public function testSingleScalarParameterLexedAsCastTokenIsParsed(): void
    {
        // `Closure(int)` — PHP lexes `(int)` as ONE T_INT_CAST token, not
        // `(` `int` `)`. The scanner must accept the cast token as the whole
        // one-scalar parameter list (the same collision the engine RFC handles).
        $sig = self::firstSig('<?php function s(Closure(int): int $c) {}');

        self::assertNotNull($sig);
        self::assertCount(1, $sig->params);
        self::assertSame('int', self::refName($sig->params[0]->type));
        self::assertTrue(self::isScalarRef($sig->params[0]->type));
        self::assertSame('int', self::refName($sig->return));
    }

    public function testEachCastTokenScalarMapsToItsCanonicalName(): void
    {
        // Locks the castTokenScalars() id→name table: every single-scalar param
        // spelled as a cast token resolves to the right scalar. `(unset)` has no
        // matching scalar type, so it maps to `void` (the RFC's bottom return),
        // exercised via the return slot below.
        $cases = [
            '(int): void' => 'int',
            '(bool): void' => 'bool',
            '(float): void' => 'float',
            '(string): void' => 'string',
            '(array): void' => 'array',
            '(object): void' => 'object',
        ];
        foreach ($cases as $spelling => $expected) {
            $sig = self::firstSig("<?php function f(Closure{$spelling} \$c) {}");
            self::assertNotNull($sig, "sig for Closure{$spelling}");
            self::assertCount(1, $sig->params, "one param for Closure{$spelling}");
            self::assertSame($expected, self::refName($sig->params[0]->type), "Closure{$spelling}");
        }
    }

    public function testUnsetCastTokenMapsToVoid(): void
    {
        // `(unset)` is a cast token with no scalar-type spelling; the table maps
        // it to `void`. Kept as its own test so the assertion is unambiguous.
        $sig = self::firstSig('<?php function f(Closure(unset): int $c) {}');

        self::assertNotNull($sig);
        self::assertCount(1, $sig->params);
        self::assertSame('void', self::refName($sig->params[0]->type));
    }

    public function testReturnPositionSignatureIsRecognized(): void
    {
        // `Closure` in a return slot (`) : Closure(): int`) is a type, not a call.
        $sig = self::firstSig('<?php function g(): Closure(): int {}');

        self::assertNotNull($sig);
        self::assertCount(0, $sig->params);
        self::assertSame('int', self::refName($sig->return));
    }

    public function testNoParametersAndNoReturnIsDistinctFromMixed(): void
    {
        // An ABSENT return is `$return === null` — kept distinct from `: mixed`.
        $sig = self::firstSig('<?php function h(Closure() $c) {}');

        self::assertNotNull($sig);
        self::assertCount(0, $sig->params);
        self::assertNull($sig->return, 'absent return must stay null, not default to mixed');
    }

    public function testNullablePrefixSetsNullableFlag(): void
    {
        // `?Closure(int): int` — the `?` precedes the erased `\Closure`; nullable
        // is captured on the signature (the emitted `?\Closure` carries the PHP
        // nullability, the flag records it for conformance).
        $sig = self::firstSig('<?php function h(?Closure(int): int $c) {}');

        self::assertNotNull($sig);
        self::assertTrue($sig->nullable);
        self::assertCount(1, $sig->params);
    }

    public function testByRefAndVariadicMarkersAreCaptured(): void
    {
        $sig = self::firstSig('<?php function m(Closure(int &$r, string ...$rest): void $c) {}');

        self::assertNotNull($sig);
        self::assertCount(2, $sig->params);
        self::assertTrue($sig->params[0]->byRef, 'first param is by-reference');
        self::assertFalse($sig->params[0]->variadic);
        self::assertFalse($sig->params[1]->byRef);
        self::assertTrue($sig->params[1]->variadic, 'last param is variadic');
        self::assertSame('void', self::refName($sig->return));
    }

    public function testNestedClosureParameterAndReturnRecurse(): void
    {
        // `Closure(Closure(int): int): Closure(): string` — the param type and the
        // return type are themselves closure signatures (SigClosure), not TypeRefs.
        $sig = self::firstSig('<?php function nn(Closure(Closure(int): int): Closure(): string $c) {}');

        self::assertNotNull($sig);
        self::assertCount(1, $sig->params);
        $inner = $sig->params[0]->type;
        self::assertInstanceOf(SigClosure::class, $inner);
        self::assertCount(1, $inner->signature->params);
        self::assertSame('int', self::refName($inner->signature->params[0]->type));
        self::assertSame('int', self::refName($inner->signature->return));
        self::assertFalse($inner->signature->nullable, 'a nested closure is not nullable unless prefixed with `?`');

        self::assertInstanceOf(SigClosure::class, $sig->return);
        self::assertCount(0, $sig->return->signature->params);
        self::assertSame('string', self::refName($sig->return->signature->return));
        self::assertFalse($sig->return->signature->nullable);
    }

    public function testFullyQualifiedClosureNameCarriesSignature(): void
    {
        // A `\Closure(int): int` written fully-qualified must normalize to `Closure`
        // (leading-`\` stripped) before the only-`Closure` check, so it is accepted
        // and carries a signature — not rejected as a non-`Closure` name.
        $sig = self::firstSig('<?php function f(\Closure(int): int $c) {}');

        self::assertNotNull($sig);
        self::assertCount(1, $sig->params);
        self::assertSame('int', self::refName($sig->params[0]->type));
        self::assertSame('int', self::refName($sig->return));
    }

    public function testFullyQualifiedNestedClosureNameIsRecognized(): void
    {
        // Same leading-`\` normalization on a NESTED closure leaf.
        $sig = self::firstSig('<?php function f(Closure(\Closure(int): int): int $c) {}');

        self::assertNotNull($sig);
        self::assertInstanceOf(SigClosure::class, $sig->params[0]->type);
        self::assertSame('int', self::refName($sig->params[0]->type->signature->params[0]->type));
    }

    public function testUnionMembersIncludingNullableAndStaticContinueTheScan(): void
    {
        // `A|?B|static` — the scan's union continuation must accept a `?`-prefixed
        // member AND a `static` member, not just plain names. A predicate that drops
        // either would stop the span early and leave unparseable residue.
        $source = '<?php function f(): Closure(): A|?B|static {}';
        $sig = self::firstSig($source);

        self::assertNotNull($sig);
        self::assertInstanceOf(SigRaw::class, $sig->return);
        self::assertSame('A|?B|static', $sig->return->raw);
    }

    public function testIntersectionMembersIncludingNullableAndStaticAreDetected(): void
    {
        // The intersection-vs-by-ref discriminator must treat a `?`-prefixed name and
        // a `static` after `&` as intersection continuations (not by-ref markers).
        $nullableMember = self::firstSig('<?php function f(Closure(A&?B): void $c) {}');
        self::assertNotNull($nullableMember);
        self::assertInstanceOf(SigRaw::class, $nullableMember->params[0]->type);
        self::assertSame('A&?B', $nullableMember->params[0]->type->raw);

        $staticMember = self::firstSig('<?php function f(Closure(A&static): void $c) {}');
        self::assertNotNull($staticMember);
        self::assertInstanceOf(SigRaw::class, $staticMember->params[0]->type);
        self::assertSame('A&static', $staticMember->params[0]->type->raw);
    }

    public function testTypeParametersInSignatureResolveAsTypeParamRefs(): void
    {
        // Generics in scope: a `Closure(T): U` inside a generic class must carry
        // the enclosing type params as type-param TypeRefs so a later work item can
        // substitute them per specialization.
        $sig = self::firstSig(<<<'PHP'
        <?php
        namespace App;
        class Mapper<T, U> {
            public function make(Closure(T): U $fn): void {}
        }
        PHP);

        self::assertNotNull($sig);
        self::assertCount(1, $sig->params);
        $param = $sig->params[0]->type;
        self::assertInstanceOf(SigTypeRef::class, $param);
        self::assertSame('T', $param->type->name);
        self::assertTrue($param->type->isTypeParam, 'T must resolve as an enclosing type-param, not a class');
        self::assertInstanceOf(SigTypeRef::class, $sig->return);
        self::assertTrue($sig->return->type->isTypeParam, 'U must resolve as an enclosing type-param');
    }

    public function testClassTypeParameterResolvesAgainstNamespace(): void
    {
        // A non-type-param class name in a signature resolves against the namespace
        // like any other type reference (reusing resolveTypeRef).
        $sig = self::firstSig(<<<'PHP'
        <?php
        namespace App;
        function f(Closure(Widget): Gadget $c) {}
        PHP);

        self::assertNotNull($sig);
        self::assertSame('App\\Widget', self::refName($sig->params[0]->type));
        self::assertSame('App\\Gadget', self::refName($sig->return));
    }

    // ===================================================================
    // Erasure: output is valid PHP, line-stable, and executes
    // ===================================================================

    public function testErasesToBareClosureLeavingNoSignatureResidue(): void
    {
        $stripped = self::strip('<?php function f(Closure(int $x, string $y): bool $c) {}');

        self::assertStringContainsString('\\Closure', $stripped);
        self::assertStringNotContainsString('int $x', $stripped);
        self::assertStringNotContainsString('): bool', $stripped);
        // The param variable that follows the whole signature must survive.
        self::assertStringContainsString('$c', $stripped);
    }

    public function testErasurePreservesLineCountForLaterMarkers(): void
    {
        // A multi-line signature must not change the total line count — line-keyed
        // markers on later lines rely on newline positions staying fixed.
        $source = <<<'PHP'
        <?php
        namespace App;
        class C<T> {
            public function build(
                Closure(
                    int $x,
                    string $y
                ): bool $cb
            ): void {}
            public Box<T> $b;
        }
        PHP;
        $stripped = self::strip($source);

        self::assertSame(
            substr_count($source, "\n"),
            substr_count($stripped, "\n"),
            'newline count must be identical before and after erasure',
        );
        // The Box<T> marker on a later line must still resolve after the multi-line
        // erasure — proving the line counter stayed aligned.
        $sig = self::firstSig($source);
        self::assertNotNull($sig);
    }

    public function testErasureAtColumnZeroKeepsLaterMarkersAligned(): void
    {
        // A signature whose `Closure` sits at column 0 (start of its line) stresses the
        // erasure byte math: the newline-preserving blank must span exactly the
        // signature bytes and not shift the preceding newline. If it did, the `Box<T>`
        // marker two lines below would desync and no longer resolve as a type param.
        $source = "<?php\n"
            . "namespace App;\n"
            . "class C<T> {\n"
            . "    public function f(\n"
            . "Closure(int): int \$cb\n"
            . "    ): void {}\n"
            . "    public Box<T> \$b;\n"
            . "}\n";

        $args = self::genericArgs($source, 'Box');
        self::assertCount(1, $args);
        self::assertTrue(
            $args[0]->isTypeParam,
            'Box<T> below a column-0 closure signature must stay line-aligned to resolve as a type param',
        );
    }

    public function testErasedSignatureCompilesAndExecutesInNamespace(): void
    {
        // A bare `Closure` in a namespace would fatal (`App\Closure` doesn't exist);
        // erasure MUST emit the fully-qualified `\Closure`. Round-trip through PHP.
        $source = <<<'PHP'
        <?php
        namespace App;
        function apply(Closure(int): int $fn, int $n): int {
            return $fn($n);
        }
        PHP;
        $stripped = self::strip($source);

        $harness = $stripped . "\n\$r = \\App\\apply(fn(int \$x): int => \$x + 1, 41);\necho \$r;";
        $out = self::runPhp($harness);
        self::assertSame('42', $out);
    }

    // ===================================================================
    // Flat union / intersection / nullable leaves are structured (WI-03)
    // ===================================================================

    public function testUnionAndIntersectionLeavesAreStructuredAndErased(): void
    {
        $source = '<?php function u(Closure(A&B): C|null $c) {}';
        $sig = self::firstSig($source);

        self::assertNotNull($sig);
        self::assertCount(1, $sig->params);
        $param = $sig->params[0]->type;
        self::assertInstanceOf(SigIntersection::class, $param);
        self::assertSame(['A', 'B'], self::sigMemberNames($param));
        self::assertInstanceOf(SigUnion::class, $sig->return);
        self::assertSame(['C', 'null'], self::sigMemberNames($sig->return));

        // Still fully erased to a bare \Closure.
        $stripped = self::strip($source);
        self::assertStringContainsString('\\Closure', $stripped);
        self::assertStringNotContainsString('A&B', $stripped);
        self::assertStringNotContainsString('C|null', $stripped);
    }

    public function testUnionParameterSpanEndsSoNextParameterParses(): void
    {
        // The union's end index must be exact, or the following `bool $b` mis-parses.
        $sig = self::firstSig('<?php function f(Closure(int|string $a, bool $b): void $c) {}');

        self::assertNotNull($sig);
        self::assertCount(2, $sig->params);
        self::assertInstanceOf(SigUnion::class, $sig->params[0]->type);
        self::assertSame(['int', 'string'], self::sigMemberNames($sig->params[0]->type));
        self::assertSame('bool', self::refName($sig->params[1]->type));
    }

    public function testKeywordUnionMemberSpanEndsSoNextParameterParses(): void
    {
        // `array` is a reserved-word keyword member (parsed by the keyword branch, not
        // parseTypeArg); its end index must land the following `bool $b` correctly.
        $sig = self::firstSig('<?php function f(Closure(int|array $a, bool $b): void $c) {}');

        self::assertNotNull($sig);
        self::assertCount(2, $sig->params);
        self::assertInstanceOf(SigUnion::class, $sig->params[0]->type);
        self::assertSame(['int', 'array'], self::sigMemberNames($sig->params[0]->type));
        self::assertSame('bool', self::refName($sig->params[1]->type));
    }

    public function testIntersectionParameterSpanEndsSoNextParameterParses(): void
    {
        $sig = self::firstSig('<?php function f(Closure(Countable&Traversable $a, int $b): void $c) {}');

        self::assertNotNull($sig);
        self::assertCount(2, $sig->params);
        self::assertInstanceOf(SigIntersection::class, $sig->params[0]->type);
        self::assertSame(['Countable', 'Traversable'], self::sigMemberNames($sig->params[0]->type));
        self::assertSame('int', self::refName($sig->params[1]->type));
    }

    public function testScalarIntersectionBailSpanEndsSoNextParameterParses(): void
    {
        // The bailed SigRaw's end index must also be exact so `bool $b` parses.
        $sig = self::firstSig('<?php function f(Closure(int&Countable $a, bool $b): void $c) {}');

        self::assertNotNull($sig);
        self::assertCount(2, $sig->params);
        self::assertInstanceOf(SigRaw::class, $sig->params[0]->type);
        self::assertSame('bool', self::refName($sig->params[1]->type));
    }

    public function testMixedTopLevelSeparatorsFallBackToRaw(): void
    {
        // `A|B&C` mixes union and intersection at top level (needs DNF parens) — the
        // flat splitter must not structure it; keep it a gradual SigRaw.
        $sig = self::firstSig('<?php function f(): Closure(): A|B&C {}');

        self::assertNotNull($sig);
        self::assertInstanceOf(SigRaw::class, $sig->return);
        self::assertSame('A|B&C', $sig->return->raw);
    }

    public function testUnionByReferenceParameterKeepsUnionAndMarker(): void
    {
        // `A|B &$r` — the trailing `&` is a by-ref marker (a `$var` follows), NOT an
        // intersection continuation; the union stays structured and the param is by-ref.
        $sig = self::firstSig('<?php function f(Closure(A|B &$r): void $c) {}');

        self::assertNotNull($sig);
        self::assertCount(1, $sig->params);
        self::assertInstanceOf(SigUnion::class, $sig->params[0]->type);
        self::assertSame(['A', 'B'], self::sigMemberNames($sig->params[0]->type));
        self::assertTrue($sig->params[0]->byRef);
    }

    public function testIntersectionWithScalarMemberStaysRawAndErases(): void
    {
        // `int&Countable` is invalid PHP (scalars can't intersect); the splitter keeps
        // it a gradual SigRaw rather than structuring, and it still fully erases.
        $source = '<?php function f(Closure(int&Countable $x): void $c) {}';
        $sig = self::firstSig($source);

        self::assertNotNull($sig);
        self::assertInstanceOf(SigRaw::class, $sig->params[0]->type);
        self::assertSame('int&Countable', $sig->params[0]->type->raw);
        self::assertStringNotContainsString('int&Countable', self::strip($source));
    }

    public function testNullableLeafInsideSignatureIsStructuredAsUnionWithNull(): void
    {
        // A `?Type` leaf INSIDE the signature (distinct from an outer `?Closure`)
        // structures to `Type|null`; the outer nullable flag stays false.
        $sig = self::firstSig('<?php function f(Closure(?int): int $c) {}');

        self::assertNotNull($sig);
        $param = $sig->params[0]->type;
        self::assertInstanceOf(SigUnion::class, $param);
        self::assertSame(['int', 'null'], self::sigMemberNames($param));
        self::assertFalse($sig->nullable, 'the inner `?int` must not set the outer nullable flag');
    }

    // ===================================================================
    // DNF groups — consumed as ONE gradual leaf, never mis-scanned
    // ===================================================================

    public function testLeadingDnfGroupReturnErasesAndStaysRaw(): void
    {
        // `(A&B)|C` in the signature's return: the group is consumed as one
        // gradual leaf and the whole signature erases (this shape used to stop
        // the type scanner cold — the signature was never recognized and the
        // raw superset syntax reached PHP as a parse error).
        $source = '<?php function g(): Closure(): (A&B)|C {}';
        $sig = self::firstSig($source);

        self::assertNotNull($sig);
        self::assertCount(0, $sig->params);
        self::assertInstanceOf(SigRaw::class, $sig->return);
        self::assertSame('(A&B)|C', $sig->return->raw);
        self::assertStringNotContainsString('(A&B)', self::strip($source));
    }

    public function testTrailingDnfGroupReturnErasesFullyNotMidType(): void
    {
        // `A|(B&C)` in the return: the scan must consume PAST the `|(` — a scan
        // that stops mid-type erases only through `A` and emits the valid-but-
        // wrong hint `\Closure    |(B&C)` with a truncated recorded return.
        $source = "<?php function g(): Closure(): A|(B&C) {\n}";
        $sig = self::firstSig($source);

        self::assertNotNull($sig);
        self::assertInstanceOf(SigRaw::class, $sig->return);
        self::assertSame('A|(B&C)', $sig->return->raw);
        self::assertStringNotContainsString('|(B&C)', self::strip($source));
    }

    public function testLeadingDnfGroupParameterIsOneParamAndKeepsReturn(): void
    {
        // `(A&B)|C $x` is ONE parameter (a single-token `(` leaf used to split
        // it into two, throwing the arity off) and the `: int` return — which
        // sat past the mis-scanned span — must survive.
        $sig = self::firstSig('<?php function f(Closure((A&B)|C $x): int $cb) {}');

        self::assertNotNull($sig);
        self::assertCount(1, $sig->params);
        self::assertInstanceOf(SigRaw::class, $sig->params[0]->type);
        self::assertSame('(A&B)|C', $sig->params[0]->type->raw);
        self::assertSame('int', self::refName($sig->return));
    }

    public function testTrailingDnfGroupParameterIsOneParamAndKeepsReturn(): void
    {
        // `A|(B&C) $x` — the trailing-group order mis-parsed as FOUR params.
        $sig = self::firstSig('<?php function f(Closure(A|(B&C) $x): int $cb) {}');

        self::assertNotNull($sig);
        self::assertCount(1, $sig->params);
        self::assertInstanceOf(SigRaw::class, $sig->params[0]->type);
        self::assertSame('A|(B&C)', $sig->params[0]->type->raw);
        self::assertSame('int', self::refName($sig->return));
    }

    public function testDnfGroupWithGenericAndNestedSignatureMember(): void
    {
        // A group interior reuses the full leaf machinery: a generic member
        // whose argument is itself a nested signature (with a cast-token param
        // list) must all land inside the one raw leaf.
        $sig = self::firstSig('<?php function f(Closure((A&B<Closure(int): C>)|D $x): int $cb) {}');

        self::assertNotNull($sig);
        self::assertCount(1, $sig->params);
        self::assertInstanceOf(SigRaw::class, $sig->params[0]->type);
        self::assertSame('(A&B<Closure(int): C>)|D', $sig->params[0]->type->raw);
    }

    public function testNullableDnfGroupReturnStaysRawGradual(): void
    {
        // `?(A&B)` is not valid PHP, but signature types are erased before PHP
        // sees them — the scanner keeps it one gradual raw leaf.
        $sig = self::firstSig('<?php function f(Closure(): ?(A&B) $cb) {}');

        self::assertNotNull($sig);
        self::assertInstanceOf(SigRaw::class, $sig->return);
        self::assertSame('?(A&B)', $sig->return->raw);
    }

    public function testDnfGroupBesideFlatUnionParameter(): void
    {
        // A DNF leaf must not poison its neighbours: the flat union before it
        // stays structured and the return stays typed.
        $sig = self::firstSig('<?php function f(Closure(A|B $x, (C&D)|E $y): F $cb) {}');

        self::assertNotNull($sig);
        self::assertCount(2, $sig->params);
        self::assertInstanceOf(SigUnion::class, $sig->params[0]->type);
        self::assertSame(['A', 'B'], self::sigMemberNames($sig->params[0]->type));
        self::assertInstanceOf(SigRaw::class, $sig->params[1]->type);
        self::assertSame('(C&D)|E', $sig->params[1]->type->raw);
        self::assertSame('F', self::refName($sig->return));
    }

    public function testPlainPhpDnfHintOutsideSignatureIsUntouched(): void
    {
        // Ordinary PHP DNF hints don't involve the signature scanner at all.
        $source = '<?php function f((A&B)|C $x) {}';

        self::assertSame($source, self::strip($source));
    }

    public function testExpressionParenAfterTernaryColonIsNotAbsorbed(): void
    {
        // `($x)` after a ternary `:` is an expression paren — its interior is a
        // variable, not a type, so the group-interior validation rejects it and
        // the call to a user symbol named `Closure` stays untouched.
        $source = '<?php $r = $a ? Closure(Foo::class) : ($x);';

        self::assertNull(self::firstSig($source));
        self::assertSame($source, self::strip($source));
    }

    public function testGroupedUseClauseIsUntouched(): void
    {
        // A closure `use (...)` group is nowhere near a signature slot; pin it
        // against any future scanner change.
        $source = '<?php $f = function () use ($a, &$b) { return $a; };';

        self::assertSame($source, self::strip($source));
    }

    public function testUnionOfTwoDnfGroupsIsOneLeaf(): void
    {
        // `(A&B)|(C&D)` — the continuation after the FIRST group must consume
        // the `|` and the second group into the same leaf; a walk that skips
        // past the separator splits the leaf and corrupts the arity.
        $sig = self::firstSig('<?php function f(Closure((A&B)|(C&D) $x): int $cb) {}');

        self::assertNotNull($sig);
        self::assertCount(1, $sig->params);
        self::assertInstanceOf(SigRaw::class, $sig->params[0]->type);
        self::assertSame('(A&B)|(C&D)', $sig->params[0]->type->raw);
        self::assertSame('int', self::refName($sig->return));
    }

    public function testGroupWithNestedSignatureThenUnionMember(): void
    {
        // The walk must resume EXACTLY one token after the nested signature's
        // end so the following `|A` continuation and the group's `)` are seen.
        $sig = self::firstSig('<?php function f(Closure((Closure(int): int)|A $x): int $cb) {}');

        self::assertNotNull($sig);
        self::assertCount(1, $sig->params);
        self::assertInstanceOf(SigRaw::class, $sig->params[0]->type);
        self::assertSame('(Closure(int): int)|A', $sig->params[0]->type->raw);
    }

    public function testGroupWithGenericMemberThenIntersectionMember(): void
    {
        // Same resume-offset pin for the ANGLE branch: after `A<T>` the `&B`
        // continuation and the `)` must still be reached.
        $sig = self::firstSig('<?php function f(Closure((A<T>&B)|C $x): int $cb) {}');

        self::assertNotNull($sig);
        self::assertCount(1, $sig->params);
        self::assertInstanceOf(SigRaw::class, $sig->params[0]->type);
        self::assertSame('(A<T>&B)|C', $sig->params[0]->type->raw);
    }

    public function testSingleNameGroupIsConsumedFromItsFirstToken(): void
    {
        // `(A)|B` — a one-token interior; an interior scan that starts one
        // token late sees only the `)` and wrongly rejects the group.
        $sig = self::firstSig('<?php function f(Closure((A)|B $x): int $cb) {}');

        self::assertNotNull($sig);
        self::assertCount(1, $sig->params);
        self::assertInstanceOf(SigRaw::class, $sig->params[0]->type);
        self::assertSame('(A)|B', $sig->params[0]->type->raw);
    }

    public function testNestedFullyQualifiedClosureSignatureInReturn(): void
    {
        // The nested-signature branch must recognize `\Closure` (leading `\`)
        // — matching on the bare text would leave the nested tail unconsumed.
        $sig = self::firstSig('<?php function f(): Closure(): \Closure(int): int {}');

        self::assertNotNull($sig);
        self::assertInstanceOf(SigClosure::class, $sig->return);
        self::assertSame('int', self::refName($sig->return->signature->return));
    }

    public function testAdjacentGroupsAreNotSilentlySwallowed(): void
    {
        // `(A&B)(C&D)` is not a type — the scan ends after the first group and
        // the junk tail must survive as loud residue, never be absorbed.
        $stripped = self::strip('<?php function f(): Closure(): (A&B)(C&D) {}');

        self::assertStringContainsString('\\Closure', $stripped);
        self::assertStringContainsString('(C&D)', $stripped);
    }

    public function testBodyOpeningBraceEndsTheReturnScan(): void
    {
        // A body whose first statement is a bare function call (`strlen(...)`)
        // must never be absorbed into the return-type span: the `{` terminates
        // the scan unconditionally.
        $stripped = self::strip("<?php function f(): Closure(): int { strlen('x'); }");

        self::assertStringContainsString("{ strlen('x'); }", $stripped);
    }

    public function testGroupInteriorMustReachTheClosingParen(): void
    {
        // `(A|B, C)` — the interior scan stops at the `,`, so the group is NOT
        // a type leaf; the pre-existing raw fallback (a one-token `(` leaf and
        // whatever follows) is preserved rather than a group ending mid-way.
        $sig = self::firstSig('<?php function f(Closure((A|B, C) $x): void $cb) {}');

        self::assertNotNull($sig);
        self::assertCount(3, $sig->params);
        self::assertInstanceOf(SigRaw::class, $sig->params[0]->type);
        self::assertSame('(', $sig->params[0]->type->raw);
    }

    public function testNestedSignatureWithoutReturnInScannedReturnPosition(): void
    {
        // `Closure(A)` — a nested signature whose last token is its own `)`
        // (no return type). The walk must resume exactly ONE token after it;
        // resuming a token early re-consumes the nested signature (or corrupts
        // the span with a stray `)` residue).
        $source = '<?php function f(): Closure(): Closure(A) {}';
        $sig = self::firstSig($source);

        self::assertNotNull($sig);
        self::assertInstanceOf(SigClosure::class, $sig->return);
        self::assertCount(1, $sig->return->signature->params);
        self::assertNull($sig->return->signature->return);
        self::assertSame('<?php function f(): \Closure              {}', self::strip($source));
    }

    public function testNameThenGroupInReturnStaysTheOnlyClosureThrow(): void
    {
        // `A (B)` — a name followed by a paren group is A trying to carry a
        // call signature; the only-Closure structural throw must stay loud (a
        // scan that treats `(B)` as a fresh group leaf silently swallows it).
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only "Closure" may carry a call signature, "A" may not');
        self::strip('<?php function f(Closure(): A (B) $x) {}');
    }

    public function testDoubleSeparatorLeavesLoudResidue(): void
    {
        // `A&|B` is malformed — the scan stops at the dangling `&` and the
        // junk survives as loud residue, never silently swallowed.
        $stripped = self::strip('<?php function f(): Closure(): A&|B {}');

        self::assertStringContainsString('&|B', $stripped);
    }

    public function testBareClosureMemberInSignatureUnionReturn(): void
    {
        // A bare `Closure` (no paren) as a union member inside a signature's
        // return: the nested-signature branch must require the name AND the
        // following `(` together — keying on either alone sends the bare name
        // into the nested scan, which fails and kills the whole recognition.
        $sig = self::firstSig('<?php function f(): Closure(): Closure|null {}');

        self::assertNotNull($sig);
        self::assertInstanceOf(SigUnion::class, $sig->return);
        self::assertSame(['Closure', 'null'], self::sigMemberNames($sig->return));
    }

    public function testTruncatedNestedSignatureFailsRecognitionCleanly(): void
    {
        // An unbalanced NESTED signature aborts recognition of the outer one —
        // the whole file stays untouched. A scan that survives the nested
        // failure restarts from the file start (where the leading bare name
        // `A` would satisfy it) and corrupts the span.
        $source = '<?php A::class; function f(): Closure(): Closure(int';

        self::assertSame($source, self::strip($source));
    }

    public function testTruncatedSourceAtEofDoesNotFatal(): void
    {
        // A file ending mid-signature must degrade cleanly (the walk-boundary
        // guards), never read past the token array. The return-slot variant
        // reaches the group scan with its interior running off the file end.
        self::assertIsString(self::strip('<?php function f(): Closure(): A|'));
        self::assertIsString(self::strip('<?php function f(): Closure(): (A'));
        self::assertIsString(self::strip('<?php function f(): Closure(): A['));
        self::assertIsString(self::strip('<?php function f(): Closure()'));
        $truncatedGroup = '<?php function f(Closure((A';
        self::assertSame($truncatedGroup, self::strip($truncatedGroup));
    }

    // ===================================================================
    // Array-sugar leaves — lowered to `array` inside signatures
    // ===================================================================

    public function testArraySugarReturnLowersToArrayAndErases(): void
    {
        // `int[]` in the signature's return: consumed into the blanked span
        // (this shape used to leave `[]` residue → a raw PHP parse error) and
        // lowered to `array`, the same lowering the global rewrite applies.
        $source = '<?php function f(): Closure(): int[] {}';
        $sig = self::firstSig($source);

        self::assertNotNull($sig);
        self::assertSame('array', self::refName($sig->return));
        self::assertStringNotContainsString('[]', self::strip($source));
    }

    public function testArraySugarParameterIsOneParamLoweredToArray(): void
    {
        // `U[] $x` is ONE parameter lowered to `array` (it used to mis-parse
        // as THREE — `U`, `[`, `]` — falsely rejecting a correct one-param
        // literal on arity at a checked site).
        $source = '<?php function f(Closure(U[] $x): void $cb) {}';
        $sig = self::firstSig($source);

        self::assertNotNull($sig);
        self::assertCount(1, $sig->params);
        self::assertSame('array', self::refName($sig->params[0]->type));
        self::assertSame('void', self::refName($sig->return));
        self::assertStringNotContainsString('[]', self::strip($source));
    }

    public function testArraySugarReturnBeforeParamVariableStillRecognizes(): void
    {
        // `Closure(): T[] $cb` — the recognition gate looks at the token after
        // the span; with the `[]` inside the span the `$cb` is visible again
        // (it used to see `[`, fail the gate, and let the global rewrite fire
        // MID-signature → parse error).
        $sig = self::firstSig('<?php function f(Closure(): T[] $cb) {}');

        self::assertNotNull($sig);
        self::assertCount(0, $sig->params);
        self::assertSame('array', self::refName($sig->return));
    }

    public function testArraySugarUnionMemberLowersToArray(): void
    {
        // Member-level sugar: `A|B[]` structures as a union of `A` and `array`.
        $sig = self::firstSig('<?php function f(Closure(A|B[] $x): void $cb) {}');

        self::assertNotNull($sig);
        self::assertCount(1, $sig->params);
        self::assertInstanceOf(SigUnion::class, $sig->params[0]->type);
        self::assertSame(['A', 'array'], self::sigMemberNames($sig->params[0]->type));
    }

    public function testNullableArraySugarLowersToArrayOrNull(): void
    {
        // `?int[]` ≡ array|null after lowering.
        $sig = self::firstSig('<?php function f(Closure(?int[] $x): void $cb) {}');

        self::assertNotNull($sig);
        self::assertInstanceOf(SigUnion::class, $sig->params[0]->type);
        self::assertSame(['array', 'null'], self::sigMemberNames($sig->params[0]->type));
    }

    public function testChainedArraySugarIsConsumedWhole(): void
    {
        // `U[][]` — the global sugar consumes chains; the signature scanner
        // must match it. One pair consumed out of two leaves `[`/`]` residue:
        // phantom params at a checked site (a false reject) or a parse error
        // in a return.
        $source = '<?php function f(Closure(U[][] $x): int[][] $cb) {}';
        $sig = self::firstSig($source);

        self::assertNotNull($sig);
        self::assertCount(1, $sig->params);
        self::assertSame('array', self::refName($sig->params[0]->type));
        self::assertSame('array', self::refName($sig->return));
        self::assertStringNotContainsString('[]', self::strip($source));
    }

    public function testPartialChainAfterCompletePairStaysLoudResidue(): void
    {
        // `A[][0]` — the first pair is sugar, the `[0]` is expression junk;
        // consume the sugar, leave the junk loud.
        $stripped = self::strip('<?php function f(): Closure(): A[][0] {}');

        self::assertStringContainsString('[0]', $stripped);
        self::assertStringNotContainsString('[]', $stripped);
    }

    public function testWhitespaceSeparatedArraySugarIsConsumed(): void
    {
        // The global sugar tolerates whitespace around the bracket pair; the
        // signature scanner must accept the same spelling.
        $sig = self::firstSig('<?php function f(Closure(U [] $x): void $cb) {}');

        self::assertNotNull($sig);
        self::assertCount(1, $sig->params);
        self::assertSame('array', self::refName($sig->params[0]->type));
    }

    public function testArraySugarWithUnionContinuationErasesFully(): void
    {
        // `int[]|null` — the walk must resume exactly one token after the `]`
        // so the `|null` continuation joins the span; resuming on (or before)
        // the `]` truncates the span and leaves `|null` residue.
        $source = '<?php function f(): Closure(): int[]|null {}';
        $sig = self::firstSig($source);

        self::assertNotNull($sig);
        self::assertInstanceOf(SigUnion::class, $sig->return);
        self::assertSame(['array', 'null'], self::sigMemberNames($sig->return));
        self::assertStringNotContainsString('|null', self::strip($source));
    }

    public function testArraySugarWithGroupContinuationErasesFully(): void
    {
        // `int[]|(A&B)` — the token right after the `]` is the separator that
        // carries the group; skipping it truncates the span.
        $source = '<?php function f(): Closure(): int[]|(A&B) {}';
        $sig = self::firstSig($source);

        self::assertNotNull($sig);
        self::assertInstanceOf(SigRaw::class, $sig->return);
        self::assertSame('int[]|(A&B)', $sig->return->raw);
        self::assertStringNotContainsString('|(', self::strip($source));
    }

    public function testNonEmptyBracketAfterReturnTypeStaysLoudResidue(): void
    {
        // `A[0]` is expression syntax, not array sugar — the bracket pair must
        // NOT be absorbed into the type span; the junk survives loudly.
        $stripped = self::strip('<?php function f(): Closure(): A[0] {}');

        self::assertStringContainsString('[0]', $stripped);
    }

    public function testDoubledClosingBracketStaysLoudResidue(): void
    {
        // `A]]` — no opening `[` means no sugar; the sugar probe must bail on
        // the first non-`[` token, never read on and match the second `]`.
        $stripped = self::strip('<?php function f(): Closure(): A]] {}');

        self::assertStringContainsString(']]', $stripped);
    }

    public function testVariadicMarkerDirectlyAfterMemberArraySugar(): void
    {
        // Tight `B[]...$rest` — the member leaf must end exactly one token
        // after the `]` so the `...` is seen as the variadic marker, not
        // skipped into the parameter variable.
        $sig = self::firstSig('<?php function f(Closure(A|B[]...$rest): void $cb) {}');

        self::assertNotNull($sig);
        self::assertCount(1, $sig->params);
        self::assertInstanceOf(SigUnion::class, $sig->params[0]->type);
        self::assertSame(['A', 'array'], self::sigMemberNames($sig->params[0]->type));
        self::assertTrue($sig->params[0]->variadic);
    }

    public function testGenericArraySugarComboStaysLoud(): void
    {
        // `Foo<T>[]` — the generic + sugar combination is the pre-existing
        // loud gap everywhere; inside a signature it must stay loud residue,
        // not be silently absorbed or lowered.
        $stripped = self::strip('<?php function f(): Closure(): Foo<T>[] {}');

        self::assertStringContainsString('[]', $stripped);
    }

    public function testArrayAccessOfClosureCallIsUntouched(): void
    {
        // `$arr[Closure(1)]` — brackets in expression context around a call to
        // a user symbol named `Closure`; nothing here is a type slot.
        $source = '<?php $r = $arr[Closure(1)];';

        self::assertNull(self::firstSig($source));
        self::assertSame($source, self::strip($source));
    }

    // ===================================================================
    // Structural rejects
    // ===================================================================

    public function testOnlyClosureMayCarryACallSignature(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only "Closure" may carry a call signature, "Foo" may not');
        self::strip('<?php function f(Foo(int): int $c) {}');
    }

    public function testVariadicParameterMustBeLast(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only the last parameter of a Closure signature can be variadic');
        self::strip('<?php function f(Closure(int ...$rest, string $tail): void $c) {}');
    }

    // ===================================================================
    // No false positives — expression-context `Closure(` must be untouched
    // ===================================================================

    public function testCallToUserFunctionNamedClosureIsNotTreatedAsSignature(): void
    {
        $source = "<?php function Closure(\$n) { return \$n; }\n\$y = Closure(5);";

        self::assertNull(self::firstSig($source), 'a call `Closure(5)` is not a type signature');
        // Source is left intact (no erasure) so the call still works.
        $stripped = self::strip($source);
        self::assertStringContainsString('Closure(5)', $stripped);
        self::assertStringNotContainsString('\\Closure', $stripped);
    }

    public function testInstanceofClosureIsNotTreatedAsSignature(): void
    {
        $source = '<?php $r = $c instanceof Closure;';

        self::assertNull(self::firstSig($source));
        self::assertSame(rtrim($source), rtrim(self::strip($source)), 'instanceof Closure must be untouched');
    }

    public function testPlainClosureTypeHintWithoutSignatureIsUntouched(): void
    {
        // A bare `Closure $c` hint (no `(`) is an ordinary type — no marker, and
        // the scanner must not rewrite it.
        $source = '<?php function f(Closure $c) {}';

        self::assertNull(self::firstSig($source));
        self::assertStringContainsString('Closure $c', self::strip($source));
        self::assertStringNotContainsString('\\Closure', self::strip($source));
    }

    public function testSignatureShapedCallInExpressionContextIsNotErased(): void
    {
        // A call whose first argument is a NAME (`Closure(Foo::class)`) passes the
        // cheap type-ish pre-filter, so only the POSITION GATE distinguishes it: the
        // `)` is followed by `;`, not a `$var`, and it is not a return slot, so the
        // production falls through untouched. Locks the gate independently of the
        // pre-filter (which the literal-argument tests above cover).
        $source = "<?php \$x = Closure(Foo::class);";

        self::assertNull(self::firstSig($source));
        self::assertStringContainsString('Closure(Foo::class)', self::strip($source));
        self::assertStringNotContainsString('\\Closure', self::strip($source));
    }

    /**
     * Expression-context `) :` producers — ternaries, case labels, alt-syntax
     * blocks, member calls of the semi-reserved `fn`/`function` — must never be
     * read as return-type slots: the source stays byte-identical and no
     * only-Closure throw fires. (Keying on bare `) :` silently erased calls to
     * a user function named `Closure` and hard-threw on ordinary `g(FOO)`.)
     *
     * @param non-empty-string $source
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('expressionColonShapes')]
    public function testExpressionColonShapesAreNeverReturnSlots(string $source): void
    {
        self::assertNull(self::firstSig($source));
        self::assertSame($source, self::strip($source));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function expressionColonShapes(): iterable
    {
        $closureFn = '<?php namespace App; function Closure($n) { return $n; } ';
        yield 'ternary else: first-class callable' => [$closureFn . '$r = $a ? b() : Closure(...);'];
        yield 'ternary else: const-arg call' => [$closureFn . '$r = $a ? b() : Closure(A);'];
        yield 'ternary else: grouping parens' => [$closureFn . '$r = ($a) ? ($b) : Closure(A);'];
        yield 'ternary else: new before the colon' => [$closureFn . '$r = $a ? new Foo() : Closure(A);'];
        yield 'ternary else: ANY function name must not throw' => ['<?php namespace App; $r = $a ? b() : g(FOO);'];
        yield 'alt-syntax if' => [$closureFn . 'if ($x): Closure(A); endif;'];
        yield 'alt-syntax if: any name' => ['<?php namespace App; if ($x): g(FOO); endif;'];
        yield 'alt-syntax elseif: any name' => ['<?php namespace App; if ($a): ; elseif (f()): g(FOO); endif;'];
        yield 'alt-syntax while' => ['<?php namespace App; while (f()): g(FOO); endwhile;'];
        yield 'alt-syntax foreach' => ['<?php namespace App; foreach (f() as $x): g(FOO); endforeach;'];
        yield 'declare block colon' => ['<?php declare(ticks=1): g(FOO); enddeclare;'];
        yield 'case label' => [$closureFn . 'switch ($a) { case f(): Closure(A); }'];
        yield 'statement right after a body brace' => [
            // `{ Closure(A); }` — the walk must REQUIRE the `:`; skipping that
            // check makes the body's `)` (of the enclosing header) masquerade
            // as a return slot and erases the statement.
            $closureFn . 'function f() { Closure(A); }',
        ];
        yield 'static call of semi-reserved fn' => [$closureFn . '$r = $c ? C::fn() : Closure(A);'];
        yield 'static call of semi-reserved function' => [$closureFn . '$r = $c ? C::function() : Closure(A);'];
        yield 'instance call of semi-reserved fn' => [$closureFn . '$r = $c ? $o->fn() : Closure(A);'];
        yield 'short ternary' => [$closureFn . '$r = b() ?: Closure(A);'];
        yield 'goto label' => [$closureFn . 'lbl: Closure(A); goto lbl;'];
    }

    /**
     * Genuine declaration-header return slots — every spelling of the
     * `function`/`fn` head the discriminator must accept.
     *
     * @param non-empty-string $source
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('declarationReturnSlots')]
    public function testDeclarationReturnSlotsKeepRecognizing(string $source): void
    {
        self::assertNotNull(self::firstSig($source), 'the return slot must recognize the signature');
        self::assertStringContainsString('\\Closure', self::strip($source));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function declarationReturnSlots(): iterable
    {
        yield 'nullable return slot' => ['<?php function f(): ?Closure(int): int {}'];
        yield 'tight colon return slot' => ['<?php function f():Closure(int): int {}'];
        yield 'nullable return, tight colon' => ['<?php function f():?Closure(int): int {}'];
        yield 'tight use clause (no space before use)' => ['<?php $f = function ()use ($a): Closure(int): int {};'];
        yield 'tight by-ref arrow fn' => ['<?php $f = fn&(): Closure(int): int => fn(int $x): int => $x;'];
        yield 'by-ref named function' => ['<?php function &f(): Closure(int): int {}'];
        yield 'closure with use clause' => ['<?php $f = function () use ($a): Closure(int): int {};'];
        yield 'by-ref closure with use clause' => ['<?php $f = function &() use ($a): Closure(int): int {};'];
        yield 'by-ref arrow fn' => ['<?php $f = fn &(): Closure(int): int => fn(int $x): int => $x;'];
        yield 'parenthesised default in params' => ['<?php function f($x = (1 + 2)): Closure(int): int {}'];
        yield 'paren inside a string default' => ['<?php function f($s = "a)b"): Closure(int): int {}'];
        yield 'comment between paren and colon' => ['<?php function f() /* c */ : Closure(int): int {}'];
        yield 'abstract method' => ['<?php abstract class C { abstract protected function m(): Closure(int): int; }'];
    }

    public function testSourceWithoutAnyClosureSignatureIsReturnedUnchanged(): void
    {
        $source = <<<'PHP'
        <?php
        namespace App;
        class Box<T> {
            public T $item;
            public function get(): T { return $this->item; }
        }
        PHP;
        // No closure signature anywhere → the strip output must be byte-identical
        // to the generic-only erasure it would already produce (no closure marker
        // interference). We only assert no \Closure was introduced.
        self::assertStringNotContainsString('\\Closure', self::strip($source));
    }

    // ===================================================================
    // Adversarial span / token-walk coverage. `parse()` runs the erasure
    // through nikic, so a signature span that ends too early or too late
    // leaves invalid residue and fails to parse — every assertion here both
    // checks the parsed shape AND (implicitly, via firstSig calling parse())
    // proves the erased output is still valid PHP.
    // ===================================================================

    public function testGenericTypeInReturnPositionIsParsedAndErased(): void
    {
        // A `Name<...>` return type: scanTypeExprEnd must extend the span across
        // the angle clause (findAngleEnd), and parseSigType must keep the generic
        // args. A span that stops at `Vec` would leave `<int>` as invalid residue.
        $sig = self::firstSig('<?php function f(): Closure(): Vec<int> {}');

        self::assertNotNull($sig);
        self::assertInstanceOf(SigTypeRef::class, $sig->return);
        self::assertSame('Vec', $sig->return->type->name);
        self::assertCount(1, $sig->return->type->args);
        self::assertSame('int', $sig->return->type->args[0]->name);
    }

    public function testNestedGenericAnglesInSignatureEraseFully(): void
    {
        // `Map<K, Vec<V>>` — the `>>` is two levels of angle nesting. findAngleEnd's
        // depth counter must reach zero only at the OUTER `>`; a broken counter
        // ends the span at the inner `>` and leaves `>` residue (unparseable).
        $source = '<?php function f(Closure(Map<K, Vec<V>>): bool $c) {}';
        $sig = self::firstSig($source);

        self::assertNotNull($sig);
        $paramType = $sig->params[0]->type;
        self::assertInstanceOf(SigTypeRef::class, $paramType);
        self::assertSame('Map', $paramType->type->name);
        self::assertCount(2, $paramType->type->args);

        // Fully erased: no fragment of the nested generic survives (and firstSig
        // above already proved the erased output re-parses).
        $stripped = self::strip($source);
        self::assertStringNotContainsString('Map', $stripped);
        self::assertStringNotContainsString('Vec', $stripped);
        self::assertStringNotContainsString('>', $stripped);
    }

    public function testThreeMemberUnionReturnIsStructuredAndErases(): void
    {
        // `A|B|C` drives scanTypeExprEnd's `|`-continuation loop twice. A span that
        // stops after the first `|` yields raw `A|B` and leaves `|C` residue.
        $source = '<?php function f(): Closure(): A|B|C {}';
        $sig = self::firstSig($source);

        self::assertNotNull($sig);
        self::assertInstanceOf(SigUnion::class, $sig->return);
        self::assertSame(['A', 'B', 'C'], self::sigMemberNames($sig->return));
        self::assertStringNotContainsString('A|B|C', self::strip($source));
    }

    public function testIntersectionByReferenceParameterSeparatesTypeFromMarker(): void
    {
        // `A&B &$r` — the FIRST `&` is an intersection continuation (a Name follows),
        // the SECOND `&` is the by-ref marker (a `$var` follows). scanTypeExprEnd must
        // consume the first and STOP at the second (its non-continuing-`&` break);
        // parseSigParam then reads the by-ref marker.
        $source = '<?php function f(Closure(A&B &$r): void $c) {}';
        $sig = self::firstSig($source);

        self::assertNotNull($sig);
        self::assertCount(1, $sig->params);
        self::assertInstanceOf(SigRaw::class, $sig->params[0]->type);
        self::assertSame('A&B', $sig->params[0]->type->raw);
        self::assertTrue($sig->params[0]->byRef, 'the second `&` is the by-ref marker');
        self::assertSame('void', self::refName($sig->return));
    }

    public function testWhitespaceAndCommentsWithinSignatureAreSkipped(): void
    {
        // Spaces around every token and a comment between params — exercises the
        // forward whitespace/comment skips in the param/return walk.
        $source = '<?php function f(Closure( int $x , /* c */ string $y ) : bool $c) {}';
        $sig = self::firstSig($source);

        self::assertNotNull($sig);
        self::assertCount(2, $sig->params);
        self::assertSame('int', self::refName($sig->params[0]->type));
        self::assertSame('string', self::refName($sig->params[1]->type));
        self::assertSame('bool', self::refName($sig->return));
    }

    public function testCommentBetweenNullableAndClosureStillMarksNullable(): void
    {
        // The backward walk (skipWsBack) that finds a leading `?` must skip a comment
        // sitting between the `?` and `Closure`.
        $sig = self::firstSig('<?php function f(?/* n */Closure(int): int $c) {}');

        self::assertNotNull($sig);
        self::assertTrue($sig->nullable);
    }

    public function testTightlyPackedSignatureIsParsed(): void
    {
        // No whitespace anywhere — the token walks must advance to the next
        // SIGNIFICANT token, not blindly by one index (which coincides only when a
        // whitespace token happens to sit between). Locks the comma / colon skips.
        $sig = self::firstSig('<?php function f(Closure(int,string):bool $c){}');

        self::assertNotNull($sig);
        self::assertCount(2, $sig->params);
        self::assertSame('int', self::refName($sig->params[0]->type));
        self::assertSame('string', self::refName($sig->params[1]->type));
        self::assertSame('bool', self::refName($sig->return));
    }

    public function testTightlyPackedCastParamAndReturn(): void
    {
        // `Closure(int):int` — cast-token param list immediately followed by `:int`
        // with no surrounding whitespace.
        $sig = self::firstSig('<?php function f(Closure(int):int $c){}');

        self::assertNotNull($sig);
        self::assertCount(1, $sig->params);
        self::assertSame('int', self::refName($sig->params[0]->type));
        self::assertSame('int', self::refName($sig->return));
    }

    public function testByReferenceVariadicParameterCapturesBothMarkers(): void
    {
        // `int&...$rest` — a by-reference variadic. The `&` (by-ref) and `...`
        // (variadic) must both be read, in order, off the same parameter; a skip that
        // overshoots the `&` would miss the `...` and drop the variadic flag.
        $sig = self::firstSig('<?php function f(Closure(int&...$rest):void $c){}');

        self::assertNotNull($sig);
        self::assertCount(1, $sig->params);
        self::assertTrue($sig->params[0]->byRef);
        self::assertTrue($sig->params[0]->variadic);
        self::assertSame('int', self::refName($sig->params[0]->type));
    }

    public function testNestedClosureInnerTypeIsResolvedAgainstNamespace(): void
    {
        // The resolver must recurse INTO a nested closure: an inner class name is
        // namespace-resolved (`Widget` → `App\Widget`). Without the recursion the
        // inner leaf would stay the bare, unresolved `Widget`.
        $sig = self::firstSig(<<<'PHP'
        <?php
        namespace App;
        function f(Closure(Closure(Widget): int): int $c) {}
        PHP);

        self::assertNotNull($sig);
        $inner = $sig->params[0]->type;
        self::assertInstanceOf(SigClosure::class, $inner);
        self::assertSame('App\\Widget', self::refName($inner->signature->params[0]->type));
    }

    // ===================================================================
    // Multiple signatures on one line map to Name nodes in source order
    // ===================================================================

    public function testTwoSignaturesOnOneLineMapToNamesInSourceOrder(): void
    {
        $sigs = self::allSigs('<?php function f(Closure(int): int $a, Closure(string): bool $b) {}');

        self::assertCount(2, $sigs);
        self::assertSame('int', self::refName($sigs[0]->params[0]->type));
        self::assertSame('int', self::refName($sigs[0]->return));
        self::assertSame('string', self::refName($sigs[1]->params[0]->type));
        self::assertSame('bool', self::refName($sigs[1]->return));
    }

    // ===================================================================
    // Reserved-word type keywords: `array` / `callable` / `static` lex as their
    // own tokens (T_ARRAY / T_CALLABLE / T_STATIC), not T_STRING, so they must be
    // recognized as type names — otherwise the signature fails to erase.
    // ===================================================================

    public function testArrayAsFirstParameterErasesAndParses(): void
    {
        $sig = self::firstSig('<?php function f(Closure(array $a): void $cb) {}');

        self::assertNotNull($sig);
        self::assertCount(1, $sig->params);
        self::assertSame('array', self::refName($sig->params[0]->type));
        self::assertSame('void', self::refName($sig->return));
    }

    public function testCallableAsReturnTypeErasesAndParses(): void
    {
        $sig = self::firstSig('<?php function f(): Closure(): callable {}');

        self::assertNotNull($sig);
        self::assertSame('callable', self::refName($sig->return));
    }

    public function testArrayAsNonFirstParameterIsAStructuredLeaf(): void
    {
        // Not just erased — represented as a SigTypeRef, not raw text.
        $sig = self::firstSig('<?php function f(Closure(int $a, array $b): void $cb) {}');

        self::assertNotNull($sig);
        self::assertCount(2, $sig->params);
        self::assertSame('array', self::refName($sig->params[1]->type));
    }

    public function testStaticAsReturnTypeIsAStructuredLeaf(): void
    {
        $sig = self::firstSig('<?php function f(Closure(): static $cb) {}');

        self::assertNotNull($sig);
        self::assertSame('static', self::refName($sig->return));
    }

    public function testUnionWithArrayMemberErasesFullyWithoutResidue(): void
    {
        // Regression: `int|array` once stopped the span at `int`, leaving `|array`
        // which re-parsed into a bogus `\Closure|array` return type (unsound). The
        // whole union must erase and be carried raw.
        $source = '<?php function f(): Closure(): int|array {}';
        $sig = self::firstSig($source);

        self::assertNotNull($sig);
        self::assertInstanceOf(SigUnion::class, $sig->return);
        self::assertSame(['int', 'array'], self::sigMemberNames($sig->return));

        $stripped = self::strip($source);
        self::assertStringNotContainsString('|array', $stripped);
        self::assertStringNotContainsString('|', $stripped);
    }

    public function testNullableArrayLeafIsStructuredAsUnionWithNull(): void
    {
        $sig = self::firstSig('<?php function f(Closure(): ?array $cb) {}');

        self::assertNotNull($sig);
        self::assertInstanceOf(SigUnion::class, $sig->return);
        self::assertSame(['array', 'null'], self::sigMemberNames($sig->return));
    }

    // ===================================================================
    // Marker attaches to the exact Name it belongs to — a plain `Closure`
    // hint sharing a line with a real signature must not steal the marker.
    // ===================================================================

    public function testPlainClosureHintDoesNotStealASiblingSignature(): void
    {
        // param $a is a plain `Closure`; param $b carries the signature. The marker
        // must land on $b, not the earlier plain $a.
        $names = self::closureTypeNamesInOrder('<?php function f(Closure $a, Closure(int): int $b) {}');

        self::assertCount(2, $names);
        self::assertNull($names[0], 'the plain `Closure $a` hint must carry no signature');
        self::assertNotNull($names[1], 'the signature must attach to `Closure(int): int $b`');
        self::assertSame('int', self::refName($names[1]->return));
    }

    public function testSignatureParamDoesNotLeakToPlainClosureReturn(): void
    {
        // param $a carries the signature; the bare `Closure` return type is plain.
        $names = self::closureTypeNamesInOrder('<?php function f(Closure(int): int $a): Closure {}');

        self::assertCount(2, $names);
        self::assertNotNull($names[0], 'the signature must attach to the `Closure(int): int $a` param');
        self::assertNull($names[1], 'the bare `Closure` return type must carry no signature');
    }

    // ===================================================================
    // Byte-length preservation: erasure must not shift positions of later
    // byte-keyed markers (anonymous-closure generics).
    // ===================================================================

    public function testErasureDoesNotShiftLaterByteKeyedMarkers(): void
    {
        // An anonymous generic closure is matched by BYTE POSITION. A closure
        // signature earlier in the file must erase to an equal-length span so the
        // later closure's `<T>` marker still aligns and attaches.
        $source = "<?php\n"
            . "function outer(Closure(int): int \$cb) {}\n"
            . "\$f = function <T>(T \$x): T { return \$x; };\n";

        $ast = self::parser()->parse($source);
        $params = null;
        $walker = function ($nodes) use (&$walker, &$params): void {
            foreach ($nodes as $node) {
                if ($node instanceof Node\Expr\Closure) {
                    $params = $node->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
                }
                if (is_object($node) && method_exists($node, 'getSubNodeNames')) {
                    foreach ($node->getSubNodeNames() as $name) {
                        $value = $node->$name;
                        if (is_array($value)) {
                            $walker($value);
                        } elseif (is_object($value)) {
                            $walker([$value]);
                        }
                    }
                }
            }
        };
        $walker($ast);

        self::assertIsArray($params, 'the anonymous closure generic marker must still attach after a closure-sig erasure');
        self::assertCount(1, $params);
        self::assertSame('T', $params[0]->name);
    }

    // ===================================================================
    // Helpers
    // ===================================================================

    /**
     * The signature (or null) attached to each `Closure`/`\Closure` type Name in
     * the source, in traversal order — one entry per Name, so a plain hint shows
     * up as a null slot.
     *
     * @return list<?ClosureSignature>
     */
    private static function closureTypeNamesInOrder(string $source): array
    {
        $ast = self::parser()->parse($source);
        $out = [];
        $walker = function ($nodes) use (&$walker, &$out): void {
            foreach ($nodes as $node) {
                if ($node instanceof Name && ltrim($node->toString(), '\\') === 'Closure') {
                    $sig = $node->getAttribute(XphpSourceParser::ATTR_CLOSURE_SIG);
                    $out[] = $sig instanceof ClosureSignature ? $sig : null;
                }
                if (is_object($node) && method_exists($node, 'getSubNodeNames')) {
                    foreach ($node->getSubNodeNames() as $name) {
                        $value = $node->$name;
                        if (is_array($value)) {
                            $walker($value);
                        } elseif (is_object($value)) {
                            $walker([$value]);
                        }
                    }
                }
            }
        };
        $walker($ast);
        return $out;
    }

    // ===================================================================
    // Base helpers
    // ===================================================================

    private static function parser(): XphpSourceParser
    {
        return new XphpSourceParser((new ParserFactory())->createForHostVersion());
    }

    private static function strip(string $source): string
    {
        return self::parser()->strip($source);
    }

    /** The ClosureSignature attached to the first `\Closure` Name, or null. */
    private static function firstSig(string $source): ?ClosureSignature
    {
        return self::allSigs($source)[0] ?? null;
    }

    /**
     * Every attached ClosureSignature in source (traversal) order.
     *
     * @return list<ClosureSignature>
     */
    private static function allSigs(string $source): array
    {
        $ast = self::parser()->parse($source);
        $found = [];
        $walker = function ($nodes) use (&$walker, &$found): void {
            foreach ($nodes as $node) {
                if ($node instanceof Name) {
                    $sig = $node->getAttribute(XphpSourceParser::ATTR_CLOSURE_SIG);
                    if ($sig instanceof ClosureSignature) {
                        $found[] = $sig;
                    }
                }
                if (is_object($node) && method_exists($node, 'getSubNodeNames')) {
                    foreach ($node->getSubNodeNames() as $name) {
                        $value = $node->$name;
                        if (is_array($value)) {
                            $walker($value);
                        } elseif (is_object($value)) {
                            $walker([$value]);
                        }
                    }
                }
            }
        };
        $walker($ast);
        return $found;
    }

    /**
     * The ATTR_GENERIC_ARGS attached to the first Name matching $nameLookup.
     *
     * @return list<TypeRef>
     */
    private static function genericArgs(string $source, string $nameLookup): array
    {
        $ast = self::parser()->parse($source);
        $found = [];
        $walker = function ($nodes) use (&$walker, &$found, $nameLookup): void {
            foreach ($nodes as $node) {
                if ($found) {
                    return;
                }
                if ($node instanceof Name && $node->toString() === $nameLookup) {
                    $args = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
                    if (is_array($args)) {
                        $found = $args;
                        return;
                    }
                }
                if (is_object($node) && method_exists($node, 'getSubNodeNames')) {
                    foreach ($node->getSubNodeNames() as $name) {
                        $value = $node->$name;
                        if (is_array($value)) {
                            $walker($value);
                        } elseif (is_object($value)) {
                            $walker([$value]);
                        }
                    }
                }
            }
        };
        $walker($ast);
        return $found;
    }

    private static function refName(?SigType $type): ?string
    {
        if (!$type instanceof SigTypeRef) {
            return null;
        }
        return $type->type->name;
    }

    /**
     * The (unresolved) member names of a compound leaf, in order.
     *
     * @return list<?string>
     */
    private static function sigMemberNames(SigUnion|SigIntersection $type): array
    {
        return array_map(self::refName(...), $type->members);
    }

    private static function isScalarRef(SigType $type): bool
    {
        return $type instanceof SigTypeRef && $type->type->isScalar;
    }

    private static function runPhp(string $code): string
    {
        $file = tempnam(sys_get_temp_dir(), 'xphp_closure_sig_') ?: throw new \RuntimeException('tempnam failed');
        try {
            file_put_contents($file, $code);
            $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
            return trim((string) $output);
        } finally {
            @unlink($file);
        }
    }
}
