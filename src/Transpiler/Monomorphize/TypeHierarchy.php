<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * A direct-ancestor map used to validate generic bounds at compile time.
 *
 * For each ClassLike (class/interface/trait) found in a parsed source set, this records the
 * fully-qualified names of its direct ancestors (extends + implements + use-trait). The
 * `isSubtype` check walks the transitive closure to answer "does class A satisfy bound B?".
 *
 * Built-in PHP interfaces (Stringable, Countable, …) are sentinel entries with no parents —
 * they're "known leaves" so a user class that explicitly implements one of them resolves the
 * bound without the hierarchy needing to model PHP's internal class table.
 *
 * Returns nullable bools from `isSubtype` because the unknown case is genuinely different from
 * "proven not a subtype": for unknown concretes the caller may want to either widen the source
 * set or relax the bound, rather than treating the concrete as wrong.
 */
final readonly class TypeHierarchy
{
    /**
     * PHP built-in interfaces/classes that user code can implement/extend without us having
     * to model them. Bound resolution against any of these works because the user's class
     * declares `implements \Stringable` (or similar) which we record as a direct ancestor.
     *
     * `public` rather than `private` because the inner-class visitor below is a separate
     * class for PHP visibility purposes and would otherwise hit a constant-access error.
     */
    public const BUILTIN_TYPES = [
        'Stringable',
        'Countable',
        'Iterator',
        'IteratorAggregate',
        'Traversable',
        'ArrayAccess',
        'JsonSerializable',
        'Throwable',
        'Exception',
        'Error',
        'BackedEnum',
        'UnitEnum',
    ];

    /**
     * `$ancestors` powers the erased `isSubtype`/`ancestorChain` queries. `$superTypeArgs` and
     * `$typeParamNames` additionally model the *parameterized* supertype edges — the type
     * arguments each `extends`/`implements` clause passes (`implements Collection<E>`) and each
     * class's own declared parameter names — so `resolveInheritedArgs` can thread a receiver's
     * concrete arguments up the chain to a method's declaring class. Both default to empty: a
     * hierarchy built without them (e.g. hand-constructed in a test, or from a non-xphp AST) still
     * answers the erased queries, and `resolveInheritedArgs` simply finds nothing to ground.
     *
     * @param array<string, list<string>> $ancestors map<fqn, list<direct-ancestor-fqn>>
     * @param array<string, list<TypeRef>> $superTypeArgs map<fqn, list<parameterized direct supertype>>
     * @param array<string, list<string>> $typeParamNames map<fqn, list<own type-param name>>
     */
    public function __construct(
        private array $ancestors,
        private array $superTypeArgs = [],
        private array $typeParamNames = [],
    ) {
    }

    /**
     * Build a hierarchy by walking the parsed ASTs of every source file in the set.
     *
     * @param array<string, list<Node\Stmt>> $astPerFile keyed by filepath, value is the top-level AST
     */
    public static function fromAstPerFile(array $astPerFile): self
    {
        $ancestors = [];
        $superTypeArgs = [];
        $typeParamNames = [];
        foreach ($astPerFile as $ast) {
            self::collectFromAst($ast, $ancestors, $superTypeArgs, $typeParamNames);
        }
        return new self($ancestors, $superTypeArgs, $typeParamNames);
    }

    /**
     * Returns:
     *   - true  : $concrete extends/implements $bound (directly or transitively), or they're equal.
     *   - false : $concrete is known to the hierarchy and does NOT have $bound in its closure
     *             (also returned for scalars vs class/interface bounds — scalars can't satisfy them).
     *   - null  : $concrete is unknown — neither in the hierarchy nor in the built-in whitelist,
     *             so the compiler can't prove satisfaction either way.
     */
    public function isSubtype(string $concrete, string $bound): ?bool
    {
        $concrete = ltrim($concrete, '\\');
        $bound = ltrim($bound, '\\');

        if ($concrete === $bound) {
            return true;
        }

        if (in_array($concrete, XphpSourceParser::SCALAR_TYPES, true)) {
            return false;
        }

        $known = isset($this->ancestors[$concrete])
            || in_array($concrete, self::BUILTIN_TYPES, true);
        if (!$known) {
            return null;
        }

        // BFS over the ancestor map. Cycles are pathological in PHP type hierarchies but the
        // visited set keeps us safe regardless.
        $visited = [];
        $queue = [$concrete];
        while ($queue !== []) {
            $cur = array_shift($queue);
            if (isset($visited[$cur])) {
                continue;
            }
            $visited[$cur] = true;
            if ($cur === $bound) {
                return true;
            }
            foreach ($this->ancestors[$cur] ?? [] as $anc) {
                $queue[] = $anc;
            }
        }
        return false;
    }

    /**
     * Whether $fqn names a type the source set knows about: a class/interface/trait
     * declared in a scanned `.xphp` file (every such ClassLike is a key in the
     * ancestor map) or a built-in PHP interface/class. Used by the
     * undeclared-type-parameter check to tell a real (in-project or built-in) type
     * from a name that resolves to nothing.
     */
    public function isDeclared(string $fqn): bool
    {
        $fqn = ltrim($fqn, '\\');

        return isset($this->ancestors[$fqn]) || in_array($fqn, self::BUILTIN_TYPES, true);
    }

    /**
     * Whether $fqn names a built-in PHP interface/class ({@see BUILTIN_TYPES}).
     * These are `isDeclared`, but the hierarchy models none of their ancestor
     * edges (it seeds edges only from scanned source), so an `isSubtype` verdict
     * of `false` against a built-in target is unprovable — callers that treat a
     * `false` as a proof must exclude a built-in target first.
     */
    public function isBuiltin(string $fqn): bool
    {
        return in_array(ltrim($fqn, '\\'), self::BUILTIN_TYPES, true);
    }

    /**
     * True when $fqn's ENTIRE ancestry is modeled from scanned source and is
     * built-in-free: $fqn itself and every member of its ancestor chain is a
     * user type declared in the source set. Under such a CLOSED WORLD an
     * {@see isSubtype} verdict of `false` is a real proof even against a
     * built-in target — no unmodeled built-in edge can exist, because every
     * edge of the chain was collected from source and none leads outside it.
     * An unknown ancestor (chain member that is no map key) or ANY built-in in
     * the chain opens the world and returns false.
     */
    public function hasClosedUserAncestry(string $fqn): bool
    {
        $fqn = ltrim($fqn, '\\');
        if (!isset($this->ancestors[$fqn]) || $this->isBuiltin($fqn)) {
            return false;
        }
        foreach ($this->ancestorChain($fqn) as $ancestor) {
            if (!isset($this->ancestors[$ancestor]) || $this->isBuiltin($ancestor)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Transitive ancestors of $fqn, nearest-first and de-duplicated, excluding
     * $fqn itself. Breadth-first over the direct-ancestor map, so the closest
     * declaring class is visited before its grandparents.
     *
     * Powers inherited generic-method resolution: when `$sub->m::<X>()` can't
     * bind `m` on the receiver's own class, the caller walks this chain and
     * binds to the nearest ancestor that declares the generic method. An
     * unknown $fqn yields an empty list.
     *
     * @return list<string>
     */
    public function ancestorChain(string $fqn): array
    {
        $fqn = ltrim($fqn, '\\');

        $seen = [];
        $chain = [];
        $queue = $this->ancestors[$fqn] ?? [];
        while ($queue !== []) {
            $next = array_shift($queue);
            if (isset($seen[$next])) {
                continue;
            }
            $seen[$next] = true;
            $chain[] = $next;
            foreach ($this->ancestors[$next] ?? [] as $grandAncestor) {
                $queue[] = $grandAncestor;
            }
        }

        return $chain;
    }

    /**
     * Thread a receiver's concrete type arguments up the parameterized supertype chain.
     *
     * Given a receiver of static type `$subFqn<$subArgs>` and a `$superFqn` reachable through its
     * `extends`/`implements` clauses, returns the type arguments that `$superFqn`'s OWN parameters
     * are bound to as witnessed from that receiver. For `class ArrayList<out E> implements Collection<E>`,
     * `resolveInheritedArgs('App\ArrayList', [Product], 'App\Collection')` yields `[Product]`. At each
     * hop the current class's parameters are substituted with the current arguments into the supertype
     * clause's arguments (so nested clauses like `implements Foo<Bar<E>>` ground throughout).
     *
     * Returns null on any gap — an unreachable target, a non-parameterized/arity-mismatched hop, or a
     * cyclic/expansive edge — and on ambiguity: when more than one path grounds the target to
     * non-equal arguments. The caller reads null as "cannot ground; fall back to lenient". A direct
     * hit (`$subFqn === $superFqn`) returns `$subArgs` unchanged.
     *
     * @param list<TypeRef> $subArgs
     * @return list<TypeRef>|null
     */
    public function resolveInheritedArgs(string $subFqn, array $subArgs, string $superFqn): ?array
    {
        /** @var array<string, list<TypeRef>> $groundings canonical-args => the grounded args (dedup) */
        $groundings = [];
        $this->groundPaths(ltrim($subFqn, '\\'), $subArgs, ltrim($superFqn, '\\'), [], $groundings);

        // 0 groundings = unreachable; >1 distinct = ambiguous (conflicting paths). Either way: null.
        if (count($groundings) !== 1) {
            return null;
        }

        return array_values($groundings)[0];
    }

    /**
     * Depth-first walk of the parameterized supertype edges, accumulating every distinct grounding of
     * `$superFqn` into `$groundings` (keyed by canonical args, so a diamond that agrees collapses to
     * one and a diamond that conflicts yields two). `$onPath` is the set of FQNs on the current path,
     * passed by value so siblings stay independent (diamonds work) while a repeat on one path — a
     * regular cycle or an expansive `A<T> implements A<Box<T>>` recursion — terminates that path.
     *
     * @param list<TypeRef> $args
     * @param array<string, true> $onPath
     * @param array<string, list<TypeRef>> $groundings
     */
    private function groundPaths(string $fqn, array $args, string $superFqn, array $onPath, array &$groundings): void
    {
        if ($fqn === $superFqn) {
            $groundings[self::argsKey($args)] = $args;
            return;
        }
        if (isset($onPath[$fqn])) {
            return; // cycle / expansive recursion on this path — a gap, not a grounding.
        }
        $params = $this->typeParamNames[$fqn] ?? [];
        if (count($params) !== count($args)) {
            return; // arity mismatch (incl. a non-parameterized hop carrying args) — a gap.
        }
        $subst = [];
        foreach ($params as $i => $name) {
            $subst[$name] = $args[$i];
        }
        // @infection-ignore-all TrueValue -- the on-path guard keys on isset() (existence, not
        // value), so the assigned literal is immaterial; the cycle/expansive tests pin termination.
        $onPath[$fqn] = true;
        foreach ($this->superTypeArgs[$fqn] ?? [] as $clause) {
            $nextArgs = array_map(
                static fn (TypeRef $a): TypeRef => Specializer::substituteTypeRef($a, $subst),
                $clause->args,
            );
            $this->groundPaths(ltrim($clause->name, '\\'), $nextArgs, $superFqn, $onPath, $groundings);
        }
    }

    /**
     * Canonical key for an argument list, used to dedup groundings and detect conflict.
     *
     * @param list<TypeRef> $args
     */
    private static function argsKey(array $args): string
    {
        return implode(',', array_map(static fn (TypeRef $a): string => $a->canonical(), $args));
    }

    /**
     * @param list<Node\Stmt> $ast
     * @param array<string, list<string>> $ancestors out-param accumulator
     * @param array<string, list<TypeRef>> $superTypeArgs out-param: parameterized direct supertypes
     * @param array<string, list<string>> $typeParamNames out-param: each class's own param names
     * @param-out array<string, list<string>> $ancestors
     * @param-out array<string, list<TypeRef>> $superTypeArgs
     * @param-out array<string, list<string>> $typeParamNames
     */
    private static function collectFromAst(array $ast, array &$ancestors, array &$superTypeArgs, array &$typeParamNames): void
    {
        // @infection-ignore-all — the inner visitor is a flat AST walk over namespace/use
        // /classlike nodes; mutations on its `?->`, `??` lastSegment fallback, the
        // `ltrim('\\')` defensives and the `is_array` attribute guards all toggle paths that
        // are masked by nikic's representation (FQ names come without a leading backslash,
        // anonymous namespaces aren't part of any fixture, and a non-generic clause simply
        // carries no ATTR_GENERIC_ARGS). The parameterized-supertype + param-name capture is
        // end-to-end covered by TypeHierarchyTest (incl. a real-parser, aliased-arg case); the
        // load-bearing grounding logic lives in resolveInheritedArgs, which IS mutation-tested.
        $visitor = new class extends NodeVisitorAbstract {
            /** @var array<string, list<string>> */
            public array $collected = [];
            /** @var array<string, list<TypeRef>> parameterized direct supertypes per class */
            public array $superArgs = [];
            /** @var array<string, list<string>> own type-param names per class */
            public array $paramNames = [];
            private string $currentNamespace = '';
            /** @var array<string, string> alias => FQN */
            private array $useMap = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    $this->useMap = [];
                }
                if ($node instanceof Use_) {
                    foreach ($node->uses as $u) {
                        // @phpstan-ignore-next-line instanceof.alwaysTrue — defensive guard against nikic/php-parser PHPDoc-narrowed Use_::$uses (pre-5.x emitted UseUse, current emits UseItem).
                        if (!$u instanceof UseItem) {
                            continue;
                        }
                        $fqn = $u->name->toString();
                        $alias = $u->alias?->toString() ?? self::lastSegment($fqn);
                        $this->useMap[$alias] = $fqn;
                    }
                }
                if ($node instanceof ClassLike && $node->name !== null) {
                    $selfFqn = $this->qualify($node->name->toString());
                    /** @var list<Name> $clauses extends + implements clause names */
                    $clauses = [];
                    if ($node instanceof Class_) {
                        if ($node->extends !== null) {
                            $clauses[] = $node->extends;
                        }
                        foreach ($node->implements as $interface) {
                            $clauses[] = $interface;
                        }
                    } elseif ($node instanceof Interface_) {
                        foreach ($node->extends as $interface) {
                            $clauses[] = $interface;
                        }
                    } elseif ($node instanceof Enum_) {
                        // Enums carry their `implements` clauses like classes do, PLUS
                        // PHP's implicit built-in edges: every enum is a UnitEnum, and a
                        // backed enum is also a BackedEnum. Without these an enum looks
                        // like a parentless plain class — a "closed world" it is not —
                        // and closed-world reasoning would falsely prove it unrelated
                        // to built-in interfaces it genuinely implements at runtime.
                        foreach ($node->implements as $interface) {
                            $clauses[] = $interface;
                        }
                    }
                    // Trait_ has no formal ancestors — uses-of-traits are statements inside the body
                    // and would only matter for shared-method bounds, which we don't model.
                    $directAncestors = [];
                    if ($node instanceof Enum_) {
                        $directAncestors[] = 'UnitEnum';
                        if ($node->scalarType !== null) {
                            $directAncestors[] = 'BackedEnum';
                        }
                    }
                    $parameterized = [];
                    foreach ($clauses as $clause) {
                        $fqn = $this->resolveName($clause);
                        $directAncestors[] = $fqn;
                        // The clause Name carries the xphp parser's resolved generic args
                        // (`implements Collection<E>` → [TypeRef(E, isTypeParam)]); a non-generic
                        // clause has none. The head FQN comes from resolveName so it keys the same
                        // way as the bare-ancestor map.
                        $rawArgs = $clause->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
                        $clauseArgs = [];
                        foreach (is_array($rawArgs) ? $rawArgs : [] as $arg) {
                            if ($arg instanceof TypeRef) {
                                $clauseArgs[] = $arg;
                            }
                        }
                        $parameterized[] = new TypeRef($fqn, $clauseArgs);
                    }
                    $this->collected[$selfFqn] = $directAncestors;
                    $this->superArgs[$selfFqn] = $parameterized;
                    $rawParams = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
                    $paramNames = [];
                    foreach (is_array($rawParams) ? $rawParams : [] as $param) {
                        if ($param instanceof TypeParam) {
                            $paramNames[] = $param->name;
                        }
                    }
                    $this->paramNames[$selfFqn] = $paramNames;
                }
                return null;
            }

            private function qualify(string $shortName): string
            {
                return $this->currentNamespace !== ''
                    ? $this->currentNamespace . '\\' . $shortName
                    : $shortName;
            }

            private function resolveName(Name $name): string
            {
                $raw = $name->toString();
                if ($name->isFullyQualified() || str_starts_with($raw, '\\')) {
                    return ltrim($raw, '\\');
                }
                $first = self::firstSegment($raw);
                if (isset($this->useMap[$first])) {
                    $rest = substr($raw, strlen($first));
                    return $this->useMap[$first] . $rest;
                }
                // Special case: built-in interfaces have no namespace; if the raw name matches a
                // known built-in we resolve as-is rather than appending the current namespace.
                if (in_array($raw, TypeHierarchy::BUILTIN_TYPES, true)) {
                    return $raw;
                }
                return $this->qualify($raw);
            }

            private static function firstSegment(string $name): string
            {
                $pos = strpos($name, '\\');
                return $pos === false ? $name : substr($name, 0, $pos);
            }

            private static function lastSegment(string $name): string
            {
                $pos = strrpos($name, '\\');
                return $pos === false ? $name : substr($name, $pos + 1);
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        foreach ($visitor->collected as $fqn => $direct) {
            $ancestors[$fqn] = $direct;
        }
        foreach ($visitor->superArgs as $fqn => $supers) {
            $superTypeArgs[$fqn] = $supers;
        }
        foreach ($visitor->paramNames as $fqn => $names) {
            $typeParamNames[$fqn] = $names;
        }
    }
}
