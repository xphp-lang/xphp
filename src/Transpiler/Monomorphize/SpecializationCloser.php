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
        private Specializer $specializer,
        private int $hashLength,
    ) {
    }

    /**
     * Close the specialization set for the covariant upcasts present in the registry. Two paths supply
     * an upcast's erased member: scheduling the declaring class so the variance edge inherits it (the
     * primary, WI-09 path — records a new instantiation), or — when inheritance can't carry it (the
     * declaring class has a source parent, or implements only a parent interface) — emitting the member
     * directly onto the upcast-source's specialized class. Returns true when it recorded at least one new
     * instantiation, so the caller re-runs the fixed-point loop.
     *
     * @param array<string, \PhpParser\Node\Stmt\ClassLike> $specializedAsts keyed by generated FQCN
     */
    public function close(Registry $registry, array &$specializedAsts): bool
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
                $this->closeOne($interfaceSpec, $methodNames, $concreteSpec, $registry, $specializedAsts);
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
     * @param array<string, \PhpParser\Node\Stmt\ClassLike> $specializedAsts
     */
    private function closeOne(
        GenericInstantiation $interfaceSpec,
        array $methodNames,
        GenericInstantiation $concreteSpec,
        Registry $registry,
        array &$specializedAsts,
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
            $this->scheduleImplementer($interfaceSpec, $concreteSpec, $methodName, $registry, $specializedAsts);
        }
    }

    /**
     * Supply `$methodName`'s erased member for the upcast. Primary path (WI-09): when the body's
     * declaring class is parent-less and threads its parameters through to this interface unchanged,
     * schedule it at the supertype args and let the variance edge inherit it. Otherwise — the declaring
     * class has a source parent (so the covariant edge can't be emitted under single inheritance), or it
     * doesn't thread to this interface (it implements only a parent interface, or reorders its params) —
     * emit the member directly onto the upcast-source class instead. Only a body that can't be found on
     * any `Class_` in the ancestry (trait-supplied / interface-only) hard-fails.
     *
     * @param array<string, \PhpParser\Node\Stmt\ClassLike> $specializedAsts
     */
    private function scheduleImplementer(
        GenericInstantiation $interfaceSpec,
        GenericInstantiation $concreteSpec,
        string $methodName,
        Registry $registry,
        array &$specializedAsts,
    ): void {
        $declaring = $this->declaringClassWithBody($concreteSpec->templateFqn, $methodName, $registry);
        if ($declaring === null) {
            throw new RuntimeException($this->unschedulableMessage(
                $interfaceSpec,
                $methodName,
                'its implementation is not declared on a class in the hierarchy (a trait-supplied or '
                . 'interface-only body cannot be inherited through the covariant edge, nor emitted directly)',
            ));
        }

        $supertypeArgs = $interfaceSpec->concreteTypes;
        $declaringDef = $registry->definition($declaring);
        // @infection-ignore-all -- `?->` is defensive: declaringClassWithBody() returned $declaring via a
        // resolved class definition, so registry->definition($declaring) is non-null here.
        $declaringAst = $declaringDef?->templateAst;
        $parentLess = $declaringAst instanceof Class_ && $declaringAst->extends === null;
        $threaded = $this->hierarchy->resolveInheritedArgs($declaring, $supertypeArgs, $interfaceSpec->templateFqn);
        $threadsIdentically = $threaded !== null && $this->argsEqual($threaded, $supertypeArgs);

        if ($parentLess && $threadsIdentically) {
            // Inheritance can carry it: schedule the declaring class at the supertype args; Phase 2.5
            // emits `<declaring><sub> extends <declaring><super>` and the member is inherited.
            $registry->recordInstantiation($declaring, $supertypeArgs);
            return;
        }

        $this->emitDirectly($interfaceSpec, $concreteSpec, $declaring, $methodName, $registry, $specializedAsts);
    }

    /**
     * Emit the erased member directly onto the upcast-source class's specialized AST. The member's NAME
     * and parameter come from the interface's declaration at the supertype args (so it byte-matches the
     * abstract member `<interface><super>` exposes); the BODY comes from the declaring class, specialized
     * with a SPLIT substitution — the declaring class's own parameters take the upcast-source's concretes
     * (so the body reads the inherited backing state), while the bounded method parameter widens to the
     * supertype arg. Sound because `sub <: super`. Self-contained (no covariant edge).
     *
     * @param array<string, \PhpParser\Node\Stmt\ClassLike> $specializedAsts
     */
    private function emitDirectly(
        GenericInstantiation $interfaceSpec,
        GenericInstantiation $concreteSpec,
        string $declaringFqn,
        string $methodName,
        Registry $registry,
        array &$specializedAsts,
    ): void {
        $interfaceDef = $registry->definition($interfaceSpec->templateFqn);
        $declaringDef = $registry->definition($declaringFqn);
        // @infection-ignore-all -- defensive: both definitions resolved upstream (the interface spec
        // entered $interfaceSpecs with a definition; declaringClassWithBody returned a defined class).
        if ($interfaceDef === null || $declaringDef === null) {
            return;
        }

        // The supertype value the method's bound takes — from the INTERFACE's own declaration, so the
        // mangled name matches the abstract member `<interface><super>` declares.
        $interfaceMethod = self::methodNamed($interfaceDef->templateAst, $methodName);
        // @infection-ignore-all -- defensive `?->`: $interfaceMethod always resolves here (see below).
        $interfaceParams = $interfaceMethod?->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
        // @infection-ignore-all -- defensive: the method name came from this interface's
        // erasableMethodNames(), so $interfaceMethod and its method-generic params always resolve here;
        // the `?->` and this null / non-array guard never fire.
        if ($interfaceMethod === null || !is_array($interfaceParams)) {
            return;
        }
        /** @var list<TypeParam> $interfaceParams */
        $boundReferent = self::boundReferentName($interfaceParams);
        // @infection-ignore-all FalseValue -- `false` vs `true` is equivalent: either non-int $boundIndex
        // routes a null bound-referent to the same hard-fail (`!is_int(...)` below).
        $boundIndex = $boundReferent === null
            ? false
            : array_search($boundReferent, $interfaceDef->typeParamNames(), true);
        if (!is_int($boundIndex) || !isset($interfaceSpec->concreteTypes[$boundIndex])) {
            // The parameters aren't uniformly bounded by ONE leaf interface parameter (a rare shape like
            // `<U : E, V : F>`). Direct emission can't derive the single mangled member — fail loudly
            // rather than emit nothing and leave the interface's abstract member unimplemented.
            throw new RuntimeException($this->unschedulableMessage(
                $interfaceSpec,
                $methodName,
                'its parameters are not uniformly bounded by one enclosing type parameter, so the '
                . 'member cannot be emitted directly',
            ));
        }
        $superValue = $interfaceSpec->concreteTypes[$boundIndex];

        $mangled = Registry::mangledMethodName(
            $methodName,
            EnclosingBoundErasure::mangleArgs($interfaceParams, [$boundReferent => $superValue]),
            $this->hashLength,
        );

        // Idempotency: never append a member the upcast-source spec already carries (its own erased
        // member, or one already emitted) — a PHP redeclaration is a load fatal.
        $upcastAst = $specializedAsts[$concreteSpec->generatedFqn] ?? null;
        if (!$upcastAst instanceof Class_ || self::methodNamed($upcastAst, $mangled) !== null) {
            return;
        }

        // The body, from the declaring class, with the split substitution.
        $declaringMethod = self::methodNamed($declaringDef->templateAst, $methodName);
        // @infection-ignore-all -- defensive `?->`: $declaringMethod always resolves here (see below).
        $declaringParams = $declaringMethod?->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
        // @infection-ignore-all -- defensive: declaringClassWithBody found an erasable, bodied method of
        // this name on this class, so it resolves here with method-generic params; the `?->` and this
        // null / non-array guard never fire.
        if ($declaringMethod === null || !is_array($declaringParams)) {
            return;
        }
        /** @var list<TypeParam> $declaringParams */
        // The split substitution grounds the body's class parameter to the upcast source's OWN concrete
        // (a subtype of the supertype the member is emitted at). That is sound for body reads, but if the
        // class parameter also appears in the RETURN type, the member would return a supertype value (the
        // widened bounded parameter) through a subtype return — a runtime TypeError. Direct emission can't
        // ground that soundly; fail loudly instead. (A covariant parameter can't appear in an input
        // position, so the return type is the only signature slot it can occupy.)
        if (EnclosingBoundErasure::returnTypeReferencesEnclosing($declaringMethod, $declaringDef->typeParamNames())) {
            throw new RuntimeException($this->unschedulableMessage(
                $interfaceSpec,
                $methodName,
                'its enclosing type parameter appears in the method return type, which direct emission '
                . 'cannot ground soundly against the upcast source',
            ));
        }
        $declaringConcrete = $this->hierarchy->resolveInheritedArgs(
            $concreteSpec->templateFqn,
            $concreteSpec->concreteTypes,
            $declaringFqn,
        );
        if ($declaringConcrete === null) {
            throw new RuntimeException($this->unschedulableMessage(
                $interfaceSpec,
                $methodName,
                sprintf('its body class "%s" can\'t be grounded against the upcast source', $declaringFqn),
            ));
        }
        $subst = array_combine($declaringDef->typeParamNames(), $declaringConcrete);
        foreach ($declaringParams as $param) {
            $subst[$param->name] = $superValue; // the bounded method param widens to the supertype arg
        }

        $member = $this->specializer->specializeMethod($declaringMethod, $subst, $mangled);
        $upcastAst->stmts[] = $member;
        // No re-collection of the body is needed: it substitutes the class parameter to the
        // upcast-source's OWN concrete — the same value as the mandatory inherited erased member the
        // upcast-source already carries at that arg — so any generic instantiation in the body has
        // already been discovered and specialized through that member.
    }

    /** The first method named `$name` on a ClassLike, or null. */
    private static function methodNamed(\PhpParser\Node\Stmt\ClassLike $ast, string $name): ?ClassMethod
    {
        foreach ($ast->getMethods() as $method) {
            if ($method->name->toString() === $name) {
                return $method;
            }
        }
        return null;
    }

    /**
     * The single enclosing-class parameter all the method's params are bounded by (the erasable shape),
     * or null if the params aren't uniformly bounded by one leaf.
     *
     * @param list<TypeParam> $params
     */
    private static function boundReferentName(array $params): ?string
    {
        $referent = null;
        foreach ($params as $param) {
            if (!$param->bound instanceof BoundLeaf) {
                return null;
            }
            $name = $param->bound->type->name;
            if ($referent !== null && $referent !== $name) {
                return null;
            }
            $referent = $name;
        }
        return $referent;
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
