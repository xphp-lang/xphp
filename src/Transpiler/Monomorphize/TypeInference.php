<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;

/**
 * Infers a generic call/instantiation's type arguments from its ordinary arguments' static types,
 * so the `::<>` turbofish becomes optional wherever the argument values determine it.
 *
 * The algorithm is the structural inverse of {@see Specializer::substituteTypeRef}: substitution
 * takes a template type (`Box<T>`) plus a binding (`T => int`) and produces a concrete type
 * (`Box<int>`); inference takes the template parameter type (`Box<T>`) plus the concrete argument
 * type (`Box<int>`) and recovers the binding (`T => int`). {@see unify} walks the two trees in
 * lock-step, binding each type-parameter leaf to the corresponding concrete sub-type; {@see infer}
 * pairs each argument with its parameter, unifies, and assembles the bindings into a
 * declaration-ordered type-argument list.
 *
 * This class is pure and context-free: everything flow-dependent (what type a `$var` or a call
 * return actually has) is delegated to an injected {@see ExpressionTyper}. That keeps the load-
 * bearing logic — conflict detection, subtype threading, the prefix/gap rules — in one small
 * unit-testable place, deliberately outside the monomorphizer's anonymous NodeVisitor classes
 * (Infection's blind spot).
 *
 * Soundness is by construction: inference only ever produces the exact concrete tuple an explicit
 * turbofish would have carried, and downstream (bounds, variance edges, mangling, specialization)
 * treats the two identically. Anything it cannot resolve to a complete, unambiguous, concrete tuple
 * yields null, and the caller leaves the site bare — falling back to today's exact behaviour.
 */
final class TypeInference
{
    public function __construct(private readonly TypeHierarchy $hierarchy)
    {
    }

    /**
     * Infer the type arguments for a generic callee from its call-site arguments.
     *
     * Returns the inferred type arguments in declaration order — a *complete* tuple, or a concrete
     * prefix whose omitted tail is entirely defaulted (so {@see Registry::padArgsWithDefaults}
     * fills it exactly as it would for a partial explicit turbofish). Returns null — meaning "leave
     * the site bare, fall back to the explicit-turbofish path" — when inference is:
     *
     *  - impossible: a required (non-defaulted) type parameter has no argument witness, or an
     *    argument's static type is unknown so its parameter stays unconstrained;
     *  - ambiguous: an argument is a subtype reaching the parameter's type through conflicting
     *    supertype paths;
     *  - conflicting: a type parameter used in two parameters is witnessed as two different types
     *    (`pair<T>(T $a, T $b)` called `pair(1, 'x')`);
     *  - a "hole": an inferred parameter follows an un-inferred one (not a clean prefix).
     *
     * @param list<TypeParam>  $typeParams the callee's generic parameters, in declaration order
     * @param list<Param>      $params     the callee's value parameters, from the template AST
     * @param list<Node\Arg|Node\VariadicPlaceholder> $args the call-site arguments
     * @return list<TypeRef>|null
     */
    public function infer(array $typeParams, array $params, array $args, ExpressionTyper $typer): ?array
    {
        // A non-generic callee (empty $typeParams) needs no guard here: assemblePrefix() returns
        // null for an empty parameter list anyway.
        $nameSet = [];
        foreach ($typeParams as $param) {
            // @infection-ignore-all TrueValue -- $nameSet is a set: membership is tested with
            // isset() in paramTypeRef(), so the stored value is immaterial (same rationale as the
            // on-path sentinel in TypeHierarchy::groundPaths).
            $nameSet[$param->name] = true;
        }

        /** @var array<string, TypeRef> $bindings type-param name => inferred concrete type */
        $bindings = [];
        foreach (self::pairArgsToParams($params, $args) as [$param, $value]) {
            $paramType = self::paramTypeRef($param->type, $nameSet);
            if ($paramType === null || !self::mentionsTypeParam($paramType)) {
                // The parameter's declared type constrains no type parameter — it contributes
                // nothing to inference (and, crucially, leaves all-defaults templates untouched).
                continue;
            }
            $argType = $typer->typeOf($value);
            if ($argType === null) {
                // Unknown argument type: leave this parameter's type variables unconstrained.
                continue;
            }
            if (!$this->unify($paramType, $argType, $bindings)) {
                return null;
            }
        }

        return self::assemblePrefix($typeParams, $bindings);
    }

    /**
     * Bind the type-parameter leaves of `$paramType` from the concrete `$argType`, the inverse of
     * {@see Specializer::substituteTypeRef}. Accumulates bindings by reference and returns whether
     * the two types are unifiable. Failure modes:
     *
     *  - a type-parameter leaf against a non-concrete argument (nothing concrete to bind);
     *  - a type parameter already bound to a different type (multi-occurrence conflict);
     *  - a parametric head the argument neither shares nor reaches as a supertype, or reaches
     *    ambiguously, or with mismatched arity.
     *
     * A plain (non-generic, non-type-param) parameter leaf imposes no constraint and unifies
     * vacuously — inference derives type arguments; it does not re-check argument assignability
     * (that is the compiler's separate job, run identically for inferred and explicit turbofishes).
     *
     * @param array<string, TypeRef> $bindings
     * @param-out array<string, TypeRef> $bindings
     */
    public function unify(TypeRef $paramType, TypeRef $argType, array &$bindings): bool
    {
        if ($paramType->isTypeParam) {
            if (!$argType->isConcrete()) {
                return false;
            }
            $existing = $bindings[$paramType->name] ?? null;
            if ($existing !== null) {
                return $existing->canonical() === $argType->canonical();
            }
            $bindings[$paramType->name] = $argType;
            return true;
        } elseif (!$paramType->isGeneric()) {
            // A plain concrete leaf imposes no constraint. Kept as an `elseif` deliberately: were
            // it a separate `if`, dropping the type-param branch's `return true` above would fall
            // through to this same `true` (an equivalent mutant); the chain routes that fall-through
            // into the parametric block below instead, where a type-param head is rejected.
            return true;
        }
        // A parametric head: view the argument as this head (directly, or threaded up its supertype
        // chain when the argument is a subtype) and unify the type arguments pairwise.
        $argArgs = $this->hierarchy->resolveInheritedArgs($argType->name, $argType->args, $paramType->name);
        if ($argArgs === null || count($argArgs) !== count($paramType->args)) {
            return false;
        }
        foreach ($paramType->args as $i => $sub) {
            if (!$this->unify($sub, $argArgs[$i], $bindings)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Convert a parameter's declared type AST node into a {@see TypeRef}, marking every leaf whose
     * name is one of the callee's type parameters (`$typeParamNames`) as a type-param leaf. A
     * generic parameter type (`Box<T>`) reuses the type arguments the parser already resolved and
     * attached (with their own leaves flagged), so nesting to any depth is handled by the parser's
     * own resolution. Returns null for a shape inference does not model — a union, an intersection,
     * or a missing type — so its parameter contributes no constraint.
     *
     * Public so the `new`-inference pass can reuse the exact same parameter→TypeRef mapping over a
     * constructor's parameters.
     *
     * @param array<string, true> $typeParamNames
     */
    public static function paramTypeRef(?Node $type, array $typeParamNames): ?TypeRef
    {
        if ($type instanceof NullableType) {
            $type = $type->type;
        }
        if ($type instanceof Identifier) {
            // A scalar or keyword type (`int`, `string`, `array`, ...) — concrete, no type params.
            return new TypeRef(strtolower($type->name), isScalar: true);
        }
        if ($type instanceof Name) {
            $name = $type->toString();
            if (isset($typeParamNames[$name])) {
                return new TypeRef($name, isTypeParam: true);
            }
            $args = $type->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
            $resolved = $type->getAttribute(XphpSourceParser::ATTR_RESOLVED_FQN);
            /** @var list<TypeRef> $argRefs */
            $argRefs = is_array($args) ? $args : [];
            return new TypeRef(is_string($resolved) ? $resolved : $name, $argRefs);
        }
        return null;
    }

    /**
     * Assemble the bindings into a declaration-ordered type-argument list, or null when they do not
     * form a "concrete prefix + defaulted tail": every bound parameter must precede every unbound
     * one (no hole), and every unbound parameter must be defaultable. An empty result (nothing
     * inferred) is null too — the site stays bare.
     *
     * @param list<TypeParam>        $typeParams
     * @param array<string, TypeRef> $bindings
     * @return list<TypeRef>|null
     */
    private static function assemblePrefix(array $typeParams, array $bindings): ?array
    {
        $prefix = [];
        $sawUnbound = false;
        foreach ($typeParams as $param) {
            $bound = $bindings[$param->name] ?? null;
            if ($bound !== null) {
                if ($sawUnbound) {
                    return null;
                }
                $prefix[] = $bound;
                continue;
            }
            if ($param->default === null) {
                return null;
            }
            $sawUnbound = true;
        }
        return $prefix === [] ? null : $prefix;
    }

    private static function mentionsTypeParam(TypeRef $ref): bool
    {
        if ($ref->isTypeParam) {
            return true;
        }
        foreach ($ref->args as $arg) {
            if (self::mentionsTypeParam($arg)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Pair each call argument with the callee parameter it binds, mirroring PHP's own rules:
     * a named argument binds by parameter name; a positional argument binds by position (a trailing
     * variadic absorbs the overflow); a spread (`...$xs`) stops positional pairing, since it
     * rebinds every following slot at runtime; and a first-class-callable placeholder carries no
     * value and is skipped. Arguments with no matching parameter are dropped.
     *
     * @param list<Param> $params
     * @param list<Node\Arg|Node\VariadicPlaceholder> $args
     * @return list<array{0: Param, 1: Node\Expr}>
     */
    private static function pairArgsToParams(array $params, array $args): array
    {
        $pairs = [];
        $position = 0;
        $sawSpread = false;
        foreach ($args as $arg) {
            if (!$arg instanceof Arg) {
                continue;
            }
            if ($arg->name instanceof Identifier) {
                $param = self::paramByName($params, $arg->name->toString());
                if ($param !== null) {
                    $pairs[] = [$param, $arg->value];
                }
                continue;
            }
            if ($sawSpread) {
                continue;
            }
            if ($arg->unpack) {
                $sawSpread = true;
                continue;
            }
            $param = self::paramForPosition($params, $position);
            if ($param !== null) {
                $pairs[] = [$param, $arg->value];
            }
            $position++;
        }
        return $pairs;
    }

    /**
     * The parameter a named argument binds, or null when no parameter has that name.
     *
     * @param list<Param> $params
     */
    private static function paramByName(array $params, string $name): ?Param
    {
        foreach ($params as $param) {
            if ($param->var instanceof Variable && $param->var->name === $name) {
                return $param;
            }
        }
        return null;
    }

    /**
     * The parameter a positional argument at `$index` binds: the parameter at that index, or a
     * trailing variadic that absorbs everything past the fixed arity, or null when the call
     * over-supplies a non-variadic list.
     *
     * @param list<Param> $params
     */
    private static function paramForPosition(array $params, int $index): ?Param
    {
        if (isset($params[$index])) {
            return $params[$index];
        }
        $last = $params === [] ? null : $params[array_key_last($params)];
        return $last !== null && $last->variadic ? $last : null;
    }
}
