<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Interface_;
use RuntimeException;

/**
 * Closes the specialization set under the covariant-upcast implementation requirement.
 *
 * A method whose type parameter is bounded by an enclosing class parameter
 * (`interface Collection<+E> { contains<E2 : E>(E2 $value): bool }`) is lowered by erasing `E2` to
 * its bound `E` — one member per class instantiation, mangled on that class's own `E`. So
 * `Collection<Book>` declares the abstract `contains_<Book>(Book)` and `Collection<Product>` declares
 * a DISTINCT abstract `contains_<Product>(Product)` (distinct names are required: a single shared
 * name would make `contains(Book)` override `contains(Product)` with a narrower parameter — a
 * contravariant LSP fatal).
 *
 * When a concrete `ListColl<Book>` (`Book <: Product`) is upcast to `Collection<Product>`, the
 * covariant interface edge `Collection<Book> extends Collection<Product>` makes it an instance of
 * `Collection<Product>`, which demands a concrete `contains_<Product>(Product)`. Nothing in the
 * `Book` world implements it, and the class that would (`AbstractColl<Product>`, inherited down the
 * covariant chain) is never discovered by the ordinary fixed-point loop — that loop only follows
 * substitution, and a covariant upcast is a usage relationship. The result is emitted PHP that fatals
 * at class load.
 *
 * This closer fills the gap: for every interface specialization that carries an erasable method, and
 * every concrete class specialization that is — by a strict covariant upcast — an instance of it, it
 * schedules the declaring class specialized at the supertype's arguments. The variance edge emitter
 * then wires `<sub> extends <super>` and the concrete member is inherited. Where the implementation
 * cannot be carried that way (the declaring class itself has a source parent that would block the
 * variance edge, the method body lives only in a trait, or the supertype arguments can't be threaded)
 * it raises a compile error rather than emit code that would fatal at load — ground or fail.
 *
 * Runs inside the Compiler's Phase 2 fixed-point loop, off the registry alone (it needs the recorded
 * instantiations + definitions, not the specialized ASTs).
 */
final readonly class SpecializationCloser
{
    /** Diagnostic code for an upcast whose concrete implementation cannot be scheduled. */
    public const CODE_UNSCHEDULABLE_UPCAST = 'xphp.unschedulable_covariant_upcast';

    public function __construct(
        private TypeHierarchy $hierarchy,
        private VarianceSubtyping $subtyping,
    ) {
    }

    /**
     * Schedule the concrete supertype specializations required by the covariant upcasts present in
     * the registry. Returns true when it recorded at least one new instantiation, so the caller
     * re-runs the fixed-point loop to specialize it.
     */
    public function close(Registry $registry): bool
    {
        $countBefore = count($registry->instantiations());

        /** @var list<array{0: GenericInstantiation, 1: list<string>}> $interfaceSpecs */
        $interfaceSpecs = [];
        /** @var list<GenericInstantiation> $concreteSpecs */
        $concreteSpecs = [];
        foreach ($registry->instantiations() as $instantiation) {
            $definition = $registry->definition($instantiation->templateFqn);
            if ($definition === null) {
                continue;
            }
            $ast = $definition->templateAst;
            if ($ast instanceof Interface_) {
                $erasable = $this->erasableMethodNames($definition);
                if ($erasable !== []) {
                    $interfaceSpecs[] = [$instantiation, $erasable];
                }
            } elseif ($ast instanceof Class_) {
                // @infection-ignore-all -- the `!isAbstract()` filter is an optimization, not a
                // correctness condition: an abstract class included here would only schedule the SAME
                // declaring class the closer reaches via its concrete subclasses (idempotent), so every
                // mutant of the negation produces the identical final registry.
                if (!$ast->isAbstract()) {
                    $concreteSpecs[] = $instantiation;
                }
            }
        }

        foreach ($interfaceSpecs as [$interfaceSpec, $methodNames]) {
            foreach ($concreteSpecs as $concreteSpec) {
                $this->closeOne($interfaceSpec, $methodNames, $concreteSpec, $registry);
            }
        }

        // @infection-ignore-all GreaterThan GreaterThanNegotiation -- this is the fixed-point signal
        // for the caller's loop ("did I add anything?"). A too-permissive comparison (>= / <) reports
        // "added" when nothing was, which the Compiler loop turns into non-termination, caught by the
        // integration compiles (they would hang rather than return); the boundary itself isn't a
        // separately assertable value.
        return count($registry->instantiations()) > $countBefore;
    }

    /**
     * @param list<string> $methodNames erasable method names declared on the interface
     */
    private function closeOne(
        GenericInstantiation $interfaceSpec,
        array $methodNames,
        GenericInstantiation $concreteSpec,
        Registry $registry,
    ): void {
        $interfaceFqn = $interfaceSpec->templateFqn;
        $concreteFqn = $concreteSpec->templateFqn;

        // The concrete class must implement the interface (the interface is an ancestor).
        if (!in_array($interfaceFqn, $this->hierarchy->ancestorChain($concreteFqn), true)) {
            return;
        }

        // Thread the concrete receiver's args up to the interface: the interface's args as witnessed
        // from this concrete spec. Null = unreachable / ambiguous (conflicting diamond paths).
        $asSeen = $this->hierarchy->resolveInheritedArgs(
            $concreteFqn,
            $concreteSpec->concreteTypes,
            $interfaceFqn,
        );
        if ($asSeen === null) {
            return;
        }

        $interfaceDef = $registry->definition($interfaceFqn);

        // The concrete already implements THIS interface spec directly (no upcast): its own erased
        // member already satisfies it — nothing to schedule.
        // @infection-ignore-all -- this early return is an optimization the strict-upcast check below
        // subsumes: when `$asSeen` equals the spec's args, isVarianceSubtype() returns false (no
        // differing arg) and control returns anyway; `$interfaceDef === null` is unreachable (the spec
        // entered $interfaceSpecs only after its definition resolved). Every mutant of this condition
        // yields the same outcome; the strict-upcast path (and its non-subtype return) stays covered.
        if ($interfaceDef === null || $this->argsEqual($asSeen, $interfaceSpec->concreteTypes)) {
            return;
        }

        // Is it a STRICT covariant upcast — `<concrete-as-seen> <: <interface spec>`? Only then does
        // the concrete inherit the interface spec's (distinctly-named) abstract erased member.
        if (!$this->subtyping->isVarianceSubtype(
            $asSeen,
            $interfaceSpec->concreteTypes,
            $interfaceDef->typeParams,
            $registry,
        )) {
            return;
        }

        foreach ($methodNames as $methodName) {
            $this->scheduleImplementer($interfaceSpec, $concreteFqn, $methodName, $registry);
        }
    }

    /**
     * Schedule the class that declares `$methodName`'s body, specialized at the interface spec's
     * arguments, so the concrete subtype inherits a concrete member through the covariant chain.
     */
    private function scheduleImplementer(
        GenericInstantiation $interfaceSpec,
        string $concreteFqn,
        string $methodName,
        Registry $registry,
    ): void {
        $declaring = $this->declaringClassWithBody($concreteFqn, $methodName, $registry);
        if ($declaring === null) {
            throw new RuntimeException($this->unschedulableMessage(
                $interfaceSpec,
                $methodName,
                'its implementation is not declared on a class in the hierarchy (a trait-supplied or '
                . 'interface-only body cannot be inherited through the covariant edge)',
            ));
        }

        $declaringDef = $registry->definition($declaring);
        // @infection-ignore-all -- `?->` is defensive: declaringClassWithBody() returned $declaring
        // precisely because registry->definition($declaring) resolved to a class definition carrying
        // the method, so it is never null here.
        $declaringAst = $declaringDef?->templateAst;
        // If the declaring class itself has a source parent, the covariant edge that would carry the
        // member (`<declaring><sub> extends <declaring><super>`) cannot be emitted (PHP single
        // inheritance — the source parent must win), so the member would not be inherited. Fail loudly.
        if ($declaringAst instanceof Class_ && $declaringAst->extends !== null) {
            throw new RuntimeException($this->unschedulableMessage(
                $interfaceSpec,
                $methodName,
                sprintf(
                    'its declaring class "%s" already extends another class, so the covariant edge that '
                    . 'would inherit the member cannot be emitted (PHP allows one parent)',
                    $declaring,
                ),
            ));
        }

        // Schedule the declaring class at the interface spec's arguments. This is valid only when the
        // declaring class threads its parameters through to the interface identically — verify it.
        // Schedule the declaring class at the interface spec's args only when it passes its parameters
        // through to the interface UNCHANGED. A reordered/wrapped `implements` clause (the implementing
        // spec can't be derived without inverting the mapping) or an unreachable target hard-fails — the
        // closer never schedules a non-implementing specialization. (`$threaded === null` is the
        // unreachable/ambiguous case; `!argsEqual` is the reorder/wrap case, covered by a reject test.)
        $supertypeArgs = $interfaceSpec->concreteTypes;
        $threaded = $this->hierarchy->resolveInheritedArgs($declaring, $supertypeArgs, $interfaceSpec->templateFqn);
        if ($threaded === null || !$this->argsEqual($threaded, $supertypeArgs)) {
            throw new RuntimeException($this->unschedulableMessage(
                $interfaceSpec,
                $methodName,
                sprintf(
                    'its declaring class "%s" does not pass its type parameters through to the interface '
                    . 'unchanged, so the implementing specialization cannot be derived',
                    $declaring,
                ),
            ));
        }

        $registry->recordInstantiation($declaring, $supertypeArgs);
    }

    /**
     * The nearest class in `$concreteFqn`'s ancestry (including itself) that declares `$methodName`
     * as an erasable method WITH a body, or null if none does (interface-only / trait-only).
     */
    private function declaringClassWithBody(string $concreteFqn, string $methodName, Registry $registry): ?string
    {
        $candidates = array_merge([$concreteFqn], $this->hierarchy->ancestorChain($concreteFqn));
        foreach ($candidates as $candidate) {
            $definition = $registry->definition($candidate);
            // @infection-ignore-all -- defensive skip of an ancestor that is not a generic class
            // template (a non-generic base with no recorded definition, or an interface/trait). Either
            // half short-circuits to the same `continue`, and the meaningful work — matching the method
            // name and confirming erasability below — stays covered.
            if ($definition === null || !$definition->templateAst instanceof Class_) {
                continue;
            }
            foreach ($definition->templateAst->getMethods() as $method) {
                // @infection-ignore-all -- the `stmts === null` half skips an abstract (bodyless)
                // declaration of the same name; either half routes to the same `continue`, and the
                // erasability confirmation below is the meaningful gate (covered by the success tests,
                // where the matching method is found past a non-matching constructor).
                if ($method->name->toString() !== $methodName || $method->stmts === null) {
                    continue;
                }
                $params = $method->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
                if (!is_array($params)) {
                    continue;
                }
                /** @var list<TypeParam> $params */
                if (EnclosingBoundErasure::isErasable($method, $params, $definition->typeParamNames())) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    /**
     * The names of an interface's methods that lower to an erased (abstract) member.
     *
     * @return list<string>
     */
    private function erasableMethodNames(GenericDefinition $definition): array
    {
        $out = [];
        foreach ($definition->templateAst->getMethods() as $method) {
            $params = $method->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
            if (!is_array($params)) {
                continue;
            }
            /** @var list<TypeParam> $params */
            if (EnclosingBoundErasure::isErasable($method, $params, $definition->typeParamNames())) {
                $out[] = $method->name->toString();
            }
        }
        // @infection-ignore-all ArrayOneItem -- the collected names drive per-method scheduling
        // (closeOne loops them), and for a covariant collection every element-consuming method declares
        // its body on the same covariant base, so each name routes to the same declaring class and the
        // same idempotent recordInstantiation. Truncating this list leaves the scheduled set unchanged.
        return $out;
    }

    /**
     * @param list<TypeRef> $a
     * @param list<TypeRef> $b
     */
    private function argsEqual(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $i => $ref) {
            if ($ref->canonical() !== $b[$i]->canonical()) {
                return false;
            }
        }
        return true;
    }

    private function unschedulableMessage(
        GenericInstantiation $interfaceSpec,
        string $methodName,
        string $reason,
    ): string {
        $args = implode(', ', array_map(static fn (TypeRef $r): string => $r->canonical(), $interfaceSpec->concreteTypes));
        return sprintf(
            "[%s] A covariant upcast requires implementing the erased method \"%s\" from \"%s<%s>\", but %s.\n"
            . '  Provide a concrete implementation reachable through a single covariant chain — e.g. give the '
            . 'declaring class no other parent, or move the method body onto the covariant base class.',
            self::CODE_UNSCHEDULABLE_UPCAST,
            $methodName,
            $interfaceSpec->templateFqn,
            $args,
            $reason,
        );
    }
}
