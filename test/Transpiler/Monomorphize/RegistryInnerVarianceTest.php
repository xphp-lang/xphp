<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\PropertyProperty;
use PhpParser\Node\UnionType;
use PhpParser\Node\VarLikeIdentifier;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\Diagnostics\DiagnosticCollector;

/**
 * Tests `Registry::validateInnerVariance` -- the pass that composes outer
 * position variance with inner template slot variance to catch cases the
 * parse-time validator can't (because at parse time we don't yet know the
 * inner template's variance).
 *
 * White-box: builds the template AST by hand so each test exercises one
 * composition-rule case without spinning up the full compile pipeline. The
 * `wireWiringMatchesCompiler` test confirms the integration point.
 */
final class RegistryInnerVarianceTest extends TestCase
{
    public function testPositionFlaggedDefinitionIsSkippedButLaterDefinitionsStillRun(): void
    {
        // P (direct +T-in-param) is flagged by the position check and recorded FIRST;
        // Q (composition violation) is recorded AFTER. Inner-variance must skip P (already
        // reported) yet still report Q — i.e. it must `continue` past P, not `break`.
        $collector = new DiagnosticCollector();
        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\P',
                'P',
                [new TypeParam('T', variance: Variance::Covariant)],
                $this->classWithMethod('P', 'set', [new Param(new \PhpParser\Node\Expr\Variable('x'), type: new Name(['T']))], new Identifier('void')),
            ),
            $this->makeDefinition('App\\Container', 'Container', [new TypeParam('X')], new Class_(new Identifier('Container'))),
            $this->makeDefinition(
                'App\\Q',
                'Q',
                [new TypeParam('T', variance: Variance::Covariant)],
                $this->classWithMethod('Q', 'f', [], $this->genericName('App\\Container', [new TypeRef('T', isTypeParam: true)])),
            ),
        ], $collector);

        $flagged = $registry->validateVariancePositions();
        $registry->validateInnerVariance($flagged);

        self::assertSame(['App\\P'], $flagged);
        self::assertCount(2, $collector->all());
        $codes = array_map(static fn ($d): string => $d->code, $collector->all());
        self::assertContains(VariancePositionValidator::CODE_VARIANCE_POSITION, $codes);
        self::assertContains(InnerVarianceValidator::CODE_INNER_VARIANCE, $codes);
    }

    public function testAllPositionFlaggedDefinitionsAreSkippedByInnerVariance(): void
    {
        // Two direct +T-in-param violations: both flagged by the position check, so the
        // inner-variance pass must skip BOTH (the full flagged list, not a truncation).
        $collector = new DiagnosticCollector();
        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\P',
                'P',
                [new TypeParam('T', variance: Variance::Covariant)],
                $this->classWithMethod('P', 'set', [new Param(new \PhpParser\Node\Expr\Variable('x'), type: new Name(['T']))], new Identifier('void')),
            ),
            $this->makeDefinition(
                'App\\R',
                'R',
                [new TypeParam('T', variance: Variance::Covariant)],
                $this->classWithMethod('R', 'set', [new Param(new \PhpParser\Node\Expr\Variable('x'), type: new Name(['T']))], new Identifier('void')),
            ),
        ], $collector);

        $flagged = $registry->validateVariancePositions();
        $registry->validateInnerVariance($flagged);

        self::assertSame(['App\\P', 'App\\R'], $flagged);
        self::assertCount(2, $collector->all());
        foreach ($collector->all() as $d) {
            self::assertSame(VariancePositionValidator::CODE_VARIANCE_POSITION, $d->code);
        }
    }

    public function testNullFileProducesNullLocationWithoutError(): void
    {
        // Defensive: with no file, the diagnostic still emits (null location) and must not
        // attempt to build a SourceLocation from a null file.
        $registry = $this->registryWith([
            $this->makeDefinition('App\\Container', 'Container', [new TypeParam('X')], new Class_(new Identifier('Container'))),
            $this->makeDefinition(
                'App\\P',
                'P',
                [new TypeParam('T', variance: Variance::Covariant)],
                $this->classWithMethod(
                    'P',
                    'f',
                    [],
                    $this->genericName('App\\Container', [new TypeRef('T', isTypeParam: true)]),
                ),
            ),
        ]);
        $container = $registry->definition('App\\Container');
        $p = $registry->definition('App\\P');
        self::assertNotNull($container);
        self::assertNotNull($p);

        $collector = new DiagnosticCollector();
        InnerVarianceValidator::assertComposition($p, ['App\\Container' => $container, 'App\\P' => $p], $collector, null);

        self::assertCount(1, $collector->all());
        self::assertNull($collector->all()[0]->location);
    }

    public function testCollectModeGathersInnerVarianceDiagnosticInsteadOfThrowing(): void
    {
        // Same composition violation as the throw-mode test, but with a collector:
        // it must be reported as a Diagnostic (not thrown), so `xphp check` continues.
        $collector = new DiagnosticCollector();
        $registry = $this->registryWith([
            $this->makeDefinition('App\\Container', 'Container', [new TypeParam('X')], new Class_(new Identifier('Container'))),
            $this->makeDefinition(
                'App\\P',
                'P',
                [new TypeParam('T', variance: Variance::Covariant)],
                $this->classWithMethod(
                    'P',
                    'f',
                    [],
                    $this->genericName('App\\Container', [new TypeRef('T', isTypeParam: true)]),
                ),
            ),
        ], $collector);

        $registry->validateInnerVariance();

        self::assertCount(1, $collector->all());
        self::assertSame(InnerVarianceValidator::CODE_INNER_VARIANCE, $collector->all()[0]->code);
        self::assertStringContainsString('Variance violation in template P', $collector->all()[0]->message);
    }

    public function testCovariantOuterInInvariantInnerSlotIsRejected(): void
    {
        // class Container<X> {}                    // X is Invariant
        // class P<+T> { function f(): Container<T> }
        // Outer pos = Covariant (return); inner slot = Invariant.
        // effective = Invariant; +T not in {Invariant} -> reject.
        $registry = $this->registryWith([
            $this->makeDefinition('App\\Container', 'Container', [new TypeParam('X')], new Class_(new Identifier('Container'))),
            $this->makeDefinition(
                'App\\P',
                'P',
                [new TypeParam('T', variance: Variance::Covariant)],
                $this->classWithMethod(
                    'P',
                    'f',
                    [],
                    $this->genericName('App\\Container', [new TypeRef('T', isTypeParam: true)]),
                ),
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Variance violation in template P');
        $this->expectExceptionMessage('+T');
        $this->expectExceptionMessage('invariant-only position');
        $this->expectExceptionMessage('Container');
        $registry->validateInnerVariance();
    }

    public function testContravariantOuterInInvariantInnerSlotIsRejected(): void
    {
        // class Container<X> {}
        // class P<-T> { function f(Container<T> $x): void }
        // Outer pos = Contravariant (param); inner slot = Invariant.
        // effective = Invariant; -T not in {Invariant} -> reject.
        $registry = $this->registryWith([
            $this->makeDefinition('App\\Container', 'Container', [new TypeParam('X')], new Class_(new Identifier('Container'))),
            $this->makeDefinition(
                'App\\P',
                'P',
                [new TypeParam('T', variance: Variance::Contravariant)],
                $this->classWithMethod(
                    'P',
                    'f',
                    [new Param(
                        new \PhpParser\Node\Expr\Variable('x'),
                        type: $this->genericName('App\\Container', [new TypeRef('T', isTypeParam: true)]),
                    )],
                    new Identifier('void'),
                ),
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('-T');
        $this->expectExceptionMessage('invariant-only position');
        $registry->validateInnerVariance();
    }

    public function testCovariantOuterInContravariantInnerSlotIsRejected(): void
    {
        // class Sink<-X> {}
        // class P<+T> { function f(): Sink<T> }
        // Outer pos = Cov; inner slot = Contra.
        // effective = flip(Cov) = Contra; +T not in {Inv, Contra} -> reject.
        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\Sink',
                'Sink',
                [new TypeParam('X', variance: Variance::Contravariant)],
                new Class_(new Identifier('Sink')),
            ),
            $this->makeDefinition(
                'App\\P',
                'P',
                [new TypeParam('T', variance: Variance::Covariant)],
                $this->classWithMethod(
                    'P',
                    'f',
                    [],
                    $this->genericName('App\\Sink', [new TypeRef('T', isTypeParam: true)]),
                ),
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('+T');
        $this->expectExceptionMessage('contravariant-only position');
        $registry->validateInnerVariance();
    }

    public function testCovariantOuterInCovariantInnerSlotIsAccepted(): void
    {
        // class Producer<+X> {}
        // class P<+T> { function f(): Producer<T> }
        // Outer pos = Cov; inner slot = Cov.
        // effective = Cov; +T in {Inv, Cov} -> accept.
        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\Producer',
                'Producer',
                [new TypeParam('X', variance: Variance::Covariant)],
                new Class_(new Identifier('Producer')),
            ),
            $this->makeDefinition(
                'App\\P',
                'P',
                [new TypeParam('T', variance: Variance::Covariant)],
                $this->classWithMethod(
                    'P',
                    'f',
                    [],
                    $this->genericName('App\\Producer', [new TypeRef('T', isTypeParam: true)]),
                ),
            ),
        ]);

        $registry->validateInnerVariance();
        $this->assertTrue(true);
    }

    public function testContravariantPathThroughDoubleFlipIsAccepted(): void
    {
        // class Sink<-X> {}
        // class P<+T> { function f(Sink<T> $x): void }
        // Outer pos = Contra (param); inner slot = Contra.
        // effective = flip(Contra) = Cov; +T in {Inv, Cov} -> accept.
        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\Sink',
                'Sink',
                [new TypeParam('X', variance: Variance::Contravariant)],
                new Class_(new Identifier('Sink')),
            ),
            $this->makeDefinition(
                'App\\P',
                'P',
                [new TypeParam('T', variance: Variance::Covariant)],
                $this->classWithMethod(
                    'P',
                    'f',
                    [new Param(
                        new \PhpParser\Node\Expr\Variable('x'),
                        type: $this->genericName('App\\Sink', [new TypeRef('T', isTypeParam: true)]),
                    )],
                    new Identifier('void'),
                ),
            ),
        ]);

        $registry->validateInnerVariance();
        $this->assertTrue(true);
    }

    public function testInvariantOuterIsAcceptedInAnyInnerSlot(): void
    {
        // class Producer<+X> {}
        // class P<T> { function f(): Producer<T> }   // T is Invariant
        // effective for T: compose(Cov, Cov) = Cov;
        // Invariant in {Inv, Cov} -> accept.
        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\Producer',
                'Producer',
                [new TypeParam('X', variance: Variance::Covariant)],
                new Class_(new Identifier('Producer')),
            ),
            $this->makeDefinition(
                'App\\P',
                'P',
                [new TypeParam('T', variance: Variance::Covariant)],     // need at least one variance marker so the pass runs
                $this->classWithMethod(
                    'P',
                    'f',
                    [],
                    $this->genericName('App\\Producer', [new TypeRef('T', isTypeParam: true)]),
                ),
            ),
        ]);

        $registry->validateInnerVariance();
        $this->assertTrue(true);
    }

    public function testNonGenericInnerTypeIsNoOp(): void
    {
        // class P<+T> { function f(): SomeOpaqueClass }
        // No xphp:genericArgs attribute; no leaf is T; walker no-ops.
        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\P',
                'P',
                [new TypeParam('T', variance: Variance::Covariant)],
                $this->classWithMethod(
                    'P',
                    'f',
                    [],
                    new Name(['App', 'SomeOpaqueClass']),
                ),
            ),
        ]);

        $registry->validateInnerVariance();
        $this->assertTrue(true);
    }

    public function testScalarInnerArgIsNoOp(): void
    {
        // class Producer<+X> {}
        // class P<+T> { function f(): Producer<int> }   // int is scalar, not T
        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\Producer',
                'Producer',
                [new TypeParam('X', variance: Variance::Covariant)],
                new Class_(new Identifier('Producer')),
            ),
            $this->makeDefinition(
                'App\\P',
                'P',
                [new TypeParam('T', variance: Variance::Covariant)],
                $this->classWithMethod(
                    'P',
                    'f',
                    [],
                    $this->genericName('App\\Producer', [new TypeRef('int', isScalar: true)]),
                ),
            ),
        ]);

        $registry->validateInnerVariance();
        $this->assertTrue(true);
    }

    public function testTwoDeepNestingComposesAllTheWay(): void
    {
        // class Container<X> {}                  // Invariant
        // class Outer<+Y> {}                     // Covariant
        // class P<+T> { function f(): Outer<Container<T>> }
        // Effective at T's leaf: compose(Cov, Cov) = Cov; then compose(Cov, Inv) = Inv.
        // +T not in {Inv} -> reject.
        $registry = $this->registryWith([
            $this->makeDefinition('App\\Container', 'Container', [new TypeParam('X')], new Class_(new Identifier('Container'))),
            $this->makeDefinition(
                'App\\Outer',
                'Outer',
                [new TypeParam('Y', variance: Variance::Covariant)],
                new Class_(new Identifier('Outer')),
            ),
            $this->makeDefinition(
                'App\\P',
                'P',
                [new TypeParam('T', variance: Variance::Covariant)],
                $this->classWithMethod(
                    'P',
                    'f',
                    [],
                    $this->genericName('App\\Outer', [
                        new TypeRef('App\\Container', [new TypeRef('T', isTypeParam: true)]),
                    ]),
                ),
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invariant-only position');
        $this->expectExceptionMessage('Container');
        $registry->validateInnerVariance();
    }

    public function testTwoDeepNestingAllCovariantIsAccepted(): void
    {
        // Replace Container with Container<+X> -- compose(Cov, Cov) = Cov twice.
        // +T in {Inv, Cov} -> accept.
        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\Container',
                'Container',
                [new TypeParam('X', variance: Variance::Covariant)],
                new Class_(new Identifier('Container')),
            ),
            $this->makeDefinition(
                'App\\Outer',
                'Outer',
                [new TypeParam('Y', variance: Variance::Covariant)],
                new Class_(new Identifier('Outer')),
            ),
            $this->makeDefinition(
                'App\\P',
                'P',
                [new TypeParam('T', variance: Variance::Covariant)],
                $this->classWithMethod(
                    'P',
                    'f',
                    [],
                    $this->genericName('App\\Outer', [
                        new TypeRef('App\\Container', [new TypeRef('T', isTypeParam: true)]),
                    ]),
                ),
            ),
        ]);

        $registry->validateInnerVariance();
        $this->assertTrue(true);
    }

    public function testUnknownInnerTemplateFallsBackToInvariant(): void
    {
        // class P<+T> { function f(): VendorThing<T> }    // VendorThing not registered
        // Conservative-unknown: inner slot treated as Invariant -> reject.
        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\P',
                'P',
                [new TypeParam('T', variance: Variance::Covariant)],
                $this->classWithMethod(
                    'P',
                    'f',
                    [],
                    $this->genericName('Vendor\\VendorThing', [new TypeRef('T', isTypeParam: true)]),
                ),
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invariant-only position');
        $registry->validateInnerVariance();
    }

    public function testIsNoOpForVarianceFreeTemplates(): void
    {
        // class Box<T> {}     -- pure invariant; pass short-circuits.
        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\Box',
                'Box',
                [new TypeParam('T')],
                $this->classWithMethod(
                    'Box',
                    'f',
                    [new Param(new \PhpParser\Node\Expr\Variable('x'), type: new Name(['T']))],
                    new Name(['T']),
                ),
            ),
        ]);

        $registry->validateInnerVariance();
        $this->assertTrue(true);
    }

    public function testConstructorPromotedPropertyWithVariantInnerSlotIsRejected(): void
    {
        // class Container<-X> {}
        // class P<+T> { function __construct(public Container<T> $c) }
        // Constructor param outer-pos is Invariant; inner slot Contra.
        // compose(Invariant, Contra) = Invariant; +T not in {Inv} -> reject.
        // Pins the ctor-promoted-property -> Invariant outer-pos branch.
        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\Container',
                'Container',
                [new TypeParam('X', variance: Variance::Contravariant)],
                new Class_(new Identifier('Container')),
            ),
            $this->makeDefinition(
                'App\\P',
                'P',
                [new TypeParam('T', variance: Variance::Covariant)],
                $this->classWithMethod(
                    'P',
                    '__construct',
                    [
                        new Param(
                            new \PhpParser\Node\Expr\Variable('c'),
                            type: $this->genericName('App\\Container', [new TypeRef('T', isTypeParam: true)]),
                            flags: \PhpParser\Modifiers::PUBLIC,
                        ),
                    ],
                    null,
                ),
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invariant-only position');
        $registry->validateInnerVariance();
    }

    public function testUnionTypeArmHitsInnerVarianceCheck(): void
    {
        // class Producer<-X> {}
        // class P<+T> { function f(): Producer<T>|null }
        // Outer pos = Cov; arm Producer's slot = Contra.
        // compose(Cov, Contra) = Contra; +T not in {Inv, Contra} -> reject.
        $producerOrNull = new UnionType([
            $this->genericName('App\\Producer', [new TypeRef('T', isTypeParam: true)]),
            new Identifier('null'),
        ]);
        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\Producer',
                'Producer',
                [new TypeParam('X', variance: Variance::Contravariant)],
                new Class_(new Identifier('Producer')),
            ),
            $this->makeDefinition(
                'App\\P',
                'P',
                [new TypeParam('T', variance: Variance::Covariant)],
                $this->classWithMethod('P', 'f', [], $producerOrNull),
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('contravariant-only position');
        $registry->validateInnerVariance();
    }

    public function testFBoundWithContravariantInnerSlotIsRejected(): void
    {
        // class Sink<-X> {}
        // class P<+T : Sink<T>> {}
        // Bound is an Invariant outer-position (PHP class-compat); inner slot
        // Contra; compose(Invariant, Contra) = Invariant; +T not in {Inv} ->
        // reject. Pins the type-param bound walk.
        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\Sink',
                'Sink',
                [new TypeParam('X', variance: Variance::Contravariant)],
                new Class_(new Identifier('Sink')),
            ),
            $this->makeDefinition(
                'App\\P',
                'P',
                [
                    new TypeParam(
                        'T',
                        new BoundLeaf(new TypeRef('App\\Sink', [new TypeRef('T', isTypeParam: true)])),
                        variance: Variance::Covariant,
                    ),
                ],
                new Class_(new Identifier('P')),
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('+T');
        $this->expectExceptionMessage('invariant-only position');
        $registry->validateInnerVariance();
    }

    public function testFBoundUnionAndIntersectionAreWalked(): void
    {
        // class Sink<-X> {}
        // class P<+T : Sink<T> & Sink<T>> {}    // BoundIntersection of two leaves
        // Both arms hit the same violation. Pins BoundUnion/BoundIntersection
        // walk.
        $boundLeaf = new BoundLeaf(new TypeRef('App\\Sink', [new TypeRef('T', isTypeParam: true)]));
        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\Sink',
                'Sink',
                [new TypeParam('X', variance: Variance::Contravariant)],
                new Class_(new Identifier('Sink')),
            ),
            $this->makeDefinition(
                'App\\P',
                'P',
                [
                    new TypeParam(
                        'T',
                        new BoundIntersection($boundLeaf, $boundLeaf),
                        variance: Variance::Covariant,
                    ),
                ],
                new Class_(new Identifier('P')),
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invariant-only position');
        $registry->validateInnerVariance();
    }

    public function testDefaultExpressionWalkRejectsVariantInnerSlot(): void
    {
        // class Container<-X> {}
        // class P<+T, U = Container<T>> {}
        // Default is an Invariant outer-position; inner Container slot is Contra;
        // compose(Invariant, Contra) = Invariant; +T not in {Inv} -> reject.
        // Pins the type-param default walk.
        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\Container',
                'Container',
                [new TypeParam('X', variance: Variance::Contravariant)],
                new Class_(new Identifier('Container')),
            ),
            $this->makeDefinition(
                'App\\P',
                'P',
                [
                    new TypeParam('T', variance: Variance::Covariant),
                    new TypeParam(
                        'U',
                        default: new TypeRef('App\\Container', [new TypeRef('T', isTypeParam: true)]),
                    ),
                ],
                new Class_(new Identifier('P')),
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('+T');
        $this->expectExceptionMessage('invariant-only position');
        $registry->validateInnerVariance();
    }

    public function testLeadingBackslashInTemplateFqnIsStripped(): void
    {
        // ATTR_TEMPLATE_FQN can carry a leading `\\` from FQ name resolution.
        // The ltrim must strip it; otherwise the registry lookup misses the
        // template and we'd fall to conservative-unknown (Invariant).
        // Here Container's X is Covariant -- if we strip correctly, the
        // composition stays Cov and accepts +T. If ltrim is removed,
        // lookup misses, conservative-Inv kicks in, rejects.
        $genericNode = new Name(['App', 'Container']);
        $genericNode->setAttribute(XphpSourceParser::ATTR_GENERIC_ARGS, [new TypeRef('T', isTypeParam: true)]);
        $genericNode->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, '\\App\\Container');

        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\Container',
                'Container',
                [new TypeParam('X', variance: Variance::Covariant)],
                new Class_(new Identifier('Container')),
            ),
            $this->makeDefinition(
                'App\\P',
                'P',
                [new TypeParam('T', variance: Variance::Covariant)],
                $this->classWithMethod('P', 'f', [], $genericNode),
            ),
        ]);

        $registry->validateInnerVariance();
        $this->assertTrue(true);
    }

    public function testNullableTypeWrapsInnerGenericForVarianceCheck(): void
    {
        // class P<+T> { function f(): ?Container<T> }     where Container's X is Inv.
        // The NullableType must recurse; the inner Container<T> still triggers
        // the invariant rejection. Pins the NullableType walker branch.
        $registry = $this->registryWith([
            $this->makeDefinition('App\\Container', 'Container', [new TypeParam('X')], new Class_(new Identifier('Container'))),
            $this->makeDefinition(
                'App\\P',
                'P',
                [new TypeParam('T', variance: Variance::Covariant)],
                $this->classWithMethod(
                    'P',
                    'f',
                    [],
                    new NullableType($this->genericName('App\\Container', [new TypeRef('T', isTypeParam: true)])),
                ),
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invariant-only position');
        $registry->validateInnerVariance();
    }

    public function testTypeRefArgsRecurseThroughKnownInnerTemplate(): void
    {
        // BoundLeaf -> TypeRef('App\\Container', [TypeRef('App\\Producer', [T])])
        // outer pos via bound = Invariant
        // Container's X = Cov -> compose(Inv, Cov) = Inv
        // Producer's X = Inv -> compose(Inv, Inv) = Inv
        // +T not in {Inv} -> reject.
        // Pins the TypeRef recursion path AND the inner-def lookup via TypeRef name.
        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\Container',
                'Container',
                [new TypeParam('X', variance: Variance::Covariant)],
                new Class_(new Identifier('Container')),
            ),
            $this->makeDefinition('App\\Producer', 'Producer', [new TypeParam('X')], new Class_(new Identifier('Producer'))),
            $this->makeDefinition(
                'App\\P',
                'P',
                [
                    new TypeParam(
                        'T',
                        new BoundLeaf(new TypeRef('App\\Container', [
                            new TypeRef('App\\Producer', [new TypeRef('T', isTypeParam: true)]),
                        ])),
                        variance: Variance::Covariant,
                    ),
                ],
                new Class_(new Identifier('P')),
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invariant-only position');
        $this->expectExceptionMessage('Producer');     // inner label surfaces in error
        $registry->validateInnerVariance();
    }

    public function testErrorMessageUsesInnerTemplateShortNameNotFqn(): void
    {
        // Verify the inner-label in the error message uses the template's
        // registered shortName, not the Name->toString() form. This catches
        // mutations that swap `$innerDef?->templateShortName ?? $type->toString()`
        // around (Coalesce mutant) -- the substring "ShortBox" is in shortName
        // but NOT in toString result `App\Container\BoxClass`.
        $genericNode = new Name(['App', 'Container', 'BoxClass']);
        $genericNode->setAttribute(XphpSourceParser::ATTR_GENERIC_ARGS, [new TypeRef('T', isTypeParam: true)]);
        $genericNode->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, 'App\\Container\\BoxClass');

        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\Container\\BoxClass',
                'ShortBox',          // intentionally != toString of the Name node
                [new TypeParam('X')],
                new Class_(new Identifier('BoxClass')),
            ),
            $this->makeDefinition(
                'App\\P',
                'P',
                [new TypeParam('T', variance: Variance::Covariant)],
                $this->classWithMethod('P', 'f', [], $genericNode),
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('via slot 0 of ShortBox');
        $registry->validateInnerVariance();
    }

    public function testLeadingBackslashInTypeRefNameIsStripped(): void
    {
        // Exercises the walkTypeRef ltrim. Setup: `class P<+T> { f(): Outer<Inner<T>> }`.
        // The OUTER Outer lookup uses ATTR_TEMPLATE_FQN (walkPhpType branch);
        // the INNER `\App\Inner` lookup goes through walkTypeRef which uses
        // ltrim on `$ref->name`. If ltrim is dropped, the Inner lookup misses,
        // conservative-Inv kicks in, compose(Cov, Inv) = Inv, rejects.
        // With ltrim intact, Inner is found, Cov*Cov*Cov = Cov, accepts.
        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\Outer',
                'Outer',
                [new TypeParam('X', variance: Variance::Covariant)],
                new Class_(new Identifier('Outer')),
            ),
            $this->makeDefinition(
                'App\\Inner',
                'Inner',
                [new TypeParam('Y', variance: Variance::Covariant)],
                new Class_(new Identifier('Inner')),
            ),
            $this->makeDefinition(
                'App\\P',
                'P',
                [new TypeParam('T', variance: Variance::Covariant)],
                $this->classWithMethod(
                    'P',
                    'f',
                    [],
                    $this->genericName('App\\Outer', [
                        // Inner TypeRef name has a leading `\\` -- the ltrim
                        // inside walkTypeRef must strip it before the registry
                        // lookup.
                        new TypeRef('\\App\\Inner', [new TypeRef('T', isTypeParam: true)]),
                    ]),
                ),
            ),
        ]);

        $registry->validateInnerVariance();
        $this->assertTrue(true);
    }

    public function testTypeRefErrorMessageUsesInnerTemplateShortName(): void
    {
        // Inside walkTypeRef: the inner-label coalesce must prefer the inner
        // definition's shortName over the TypeRef's `name` (which is a FQN).
        // Coalesce-swap mutants would emit the FQN instead.
        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\Container\\BoxClass',
                'ShortBox',
                [new TypeParam('X')],
                new Class_(new Identifier('BoxClass')),
            ),
            $this->makeDefinition(
                'App\\P',
                'P',
                [
                    new TypeParam(
                        'T',
                        new BoundLeaf(new TypeRef('App\\Container\\BoxClass', [new TypeRef('T', isTypeParam: true)])),
                        variance: Variance::Covariant,
                    ),
                ],
                new Class_(new Identifier('P')),
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('via slot 0 of ShortBox');
        $registry->validateInnerVariance();
    }

    public function testInvariantDeclaredInInvariantPositionIsAccepted(): void
    {
        // class Container<X> {}       // X is Invariant
        // class P<U, +T> { function f(): Container<U> }   // U is Invariant
        // U is Invariant declared; outer pos Cov; inner slot Inv;
        // compose(Cov, Inv) = Inv; Invariant in {Inv} -> accept.
        // The other type-param +T gives buildVarianceMap a non-empty map so
        // the walk actually runs. Pins the
        // `Variance::Invariant => [Variance::Invariant]` allowed-list.
        $registry = $this->registryWith([
            $this->makeDefinition('App\\Container', 'Container', [new TypeParam('X')], new Class_(new Identifier('Container'))),
            $this->makeDefinition(
                'App\\P',
                'P',
                [
                    new TypeParam('U'),
                    new TypeParam('T', variance: Variance::Covariant),
                ],
                $this->classWithMethod(
                    'P',
                    'f',
                    [],
                    $this->genericName('App\\Container', [new TypeRef('U', isTypeParam: true)]),
                ),
            ),
        ]);

        $registry->validateInnerVariance();
        $this->assertTrue(true);
    }

    public function testInvariantDeclaredInCovariantPositionIsAccepted(): void
    {
        // class Producer<+X> {}
        // class P<U, +T> { function f(): Producer<U> }
        // U is Invariant declared; outer pos Cov; inner slot Cov;
        // compose(Cov, Cov) = Cov; Invariant in {Inv, Cov} -> accept.
        // Pins the second item of `Variance::Covariant => [Inv, Cov]` allowed-list.
        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\Producer',
                'Producer',
                [new TypeParam('X', variance: Variance::Covariant)],
                new Class_(new Identifier('Producer')),
            ),
            $this->makeDefinition(
                'App\\P',
                'P',
                [
                    new TypeParam('U'),
                    new TypeParam('T', variance: Variance::Covariant),
                ],
                $this->classWithMethod(
                    'P',
                    'f',
                    [],
                    $this->genericName('App\\Producer', [new TypeRef('U', isTypeParam: true)]),
                ),
            ),
        ]);

        $registry->validateInnerVariance();
        $this->assertTrue(true);
    }

    public function testInvariantDeclaredInContravariantPositionIsAccepted(): void
    {
        // class Producer<+X> {}
        // class P<U, +T> { function f(Producer<U> $x): void }
        // U is Invariant declared; outer pos Contra (param); inner Cov;
        // compose(Contra, Cov) = Contra; Invariant in {Inv, Contra} -> accept.
        // Pins the first item of `Variance::Contravariant => [Inv, Contra]` allowed-list.
        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\Producer',
                'Producer',
                [new TypeParam('X', variance: Variance::Covariant)],
                new Class_(new Identifier('Producer')),
            ),
            $this->makeDefinition(
                'App\\P',
                'P',
                [
                    new TypeParam('U'),
                    new TypeParam('T', variance: Variance::Covariant),
                ],
                $this->classWithMethod(
                    'P',
                    'f',
                    [new Param(
                        new \PhpParser\Node\Expr\Variable('x'),
                        type: $this->genericName('App\\Producer', [new TypeRef('U', isTypeParam: true)]),
                    )],
                    new Identifier('void'),
                ),
            ),
        ]);

        $registry->validateInnerVariance();
        $this->assertTrue(true);
    }

    public function testStaticMethodReturnTypeIsWalked(): void
    {
        // class Container<X> {}     // Invariant
        // class P<+T> { public static function f(): Container<T> }
        // Static methods walk the same as instance methods.
        $method = new ClassMethod(
            new Identifier('f'),
            [
                'flags' => \PhpParser\Modifiers::PUBLIC | \PhpParser\Modifiers::STATIC,
                'params' => [],
                'returnType' => $this->genericName('App\\Container', [new TypeRef('T', isTypeParam: true)]),
            ],
        );
        $registry = $this->registryWith([
            $this->makeDefinition('App\\Container', 'Container', [new TypeParam('X')], new Class_(new Identifier('Container'))),
            $this->makeDefinition(
                'App\\P',
                'P',
                [new TypeParam('T', variance: Variance::Covariant)],
                new Class_(new Identifier('P'), ['stmts' => [$method]]),
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invariant-only position');
        $registry->validateInnerVariance();
    }

    public function testPropertyInvariantPositionRejectsCovariantOuter(): void
    {
        // class P<+T> { public T $item; }
        // Property slot is Invariant for outer T directly (not even via inner).
        // The parse-time validator catches this; verify the new pass doesn't
        // double-throw (its error path uses a different framing).
        $registry = $this->registryWith([
            $this->makeDefinition(
                'App\\P',
                'P',
                [new TypeParam('T', variance: Variance::Covariant)],
                $this->classWithProperty('P', 'item', new Name(['T'])),
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invariant-only position');
        $registry->validateInnerVariance();
    }

    // ----- helpers ---------------------------------------------------------

    /**
     * @param list<GenericDefinitionFixture> $defs
     */
    private function registryWith(array $defs, ?DiagnosticCollector $collector = null): Registry
    {
        $registry = new Registry(diagnostics: $collector);
        foreach ($defs as $def) {
            $registry->recordDefinition(
                $def->fqn,
                $def->shortName,
                $def->typeParams,
                $def->classAst,
                '/test/' . $def->shortName . '.xphp',
            );
        }
        return $registry;
    }

    /**
     * @param list<TypeParam> $typeParams
     */
    private function makeDefinition(
        string $fqn,
        string $shortName,
        array $typeParams,
        Class_ $classAst,
    ): GenericDefinitionFixture {
        return new GenericDefinitionFixture($fqn, $shortName, $typeParams, $classAst);
    }

    /**
     * Build a class with a single method. `$returnType` may be null.
     *
     * @param list<Param> $params
     */
    private function classWithMethod(
        string $className,
        string $methodName,
        array $params,
        Identifier|Name|NullableType|UnionType|IntersectionType|ComplexType|null $returnType,
    ): Class_ {
        $method = new ClassMethod(
            new Identifier($methodName),
            [
                'params' => $params,
                'returnType' => $returnType,
            ],
        );
        return new Class_(new Identifier($className), ['stmts' => [$method]]);
    }

    private function classWithProperty(string $className, string $propName, Name $propType): Class_
    {
        $prop = new Property(
            \PhpParser\Modifiers::PUBLIC,
            [new PropertyProperty(new VarLikeIdentifier($propName))],
            type: $propType,
        );
        return new Class_(new Identifier($className), ['stmts' => [$prop]]);
    }

    /**
     * Build a `Name('Foo')` node with the xphp:genericArgs + xphp:templateFqn
     * attributes attached, matching what the scanner produces in real source.
     *
     * @param list<TypeRef> $args
     */
    private function genericName(string $fqn, array $args): Name
    {
        $parts = explode('\\', $fqn);
        $node = new Name($parts);
        $node->setAttribute(XphpSourceParser::ATTR_GENERIC_ARGS, $args);
        $node->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, $fqn);
        return $node;
    }
}

/**
 * Lightweight DTO so the helper signatures stay readable.
 *
 * @internal
 */
final readonly class GenericDefinitionFixture
{
    /**
     * @param list<TypeParam> $typeParams
     */
    public function __construct(
        public string $fqn,
        public string $shortName,
        public array $typeParams,
        public Class_ $classAst,
    ) {
    }
}
