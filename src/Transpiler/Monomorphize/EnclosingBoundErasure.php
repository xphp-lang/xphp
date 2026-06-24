<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\UnionType;
use PhpParser\NodeFinder;

/**
 * Decides whether a generic method whose type parameter is bounded by an enclosing class type
 * parameter (`class Box<+E> { contains<U : E>(U $value): bool }`) can be lowered by **erasing `U`
 * to its bound `E`** — i.e. specialized once per class instantiation (`contains_<Fruit>(Fruit)`)
 * instead of once per call-site turbofish (`contains_<Banana>(Banana)`).
 *
 * Erasure is sound only when every enclosing-bounded type parameter appears **exclusively** as a
 * top-level input parameter (`U $value`). If it appears nested (`Box<U>`), in the return type, or
 * structurally in the body (`new U`, `instanceof U`), the concrete `U` is observable and erasing it
 * to `E` would change behaviour — those keep the per-`U` lowering. A type parameter *forwarded* in a
 * turbofish self-call (`$this->m::<U>()`) is fine: it lives in `ATTR_METHOD_GENERIC_ARGS`, an
 * attribute the AST `Name` walk never visits, so it's correctly invisible here.
 *
 * Conservative by construction: any uncertainty answers "not erasable", which falls back to the
 * existing per-`U` path (and the compile error for an unspecializable forward) — never to unsound
 * erasure.
 */
final class EnclosingBoundErasure
{
    /**
     * @param list<TypeParam> $methodParams the method's own generic parameters (with their bounds)
     * @param list<string> $classParamNames the enclosing class's type-parameter names
     */
    public static function isErasable(ClassMethod $method, array $methodParams, array $classParamNames): bool
    {
        // Every type parameter must be enclosing-bounded (`<U : E>`). This rejects both a method with
        // no enclosing-bounded parameter at all (`<T>`, `<U : \Stringable>` → empty `$bounded`) and a
        // mixed method (`<U : E, W : \Stringable>`) whose `W` needs real per-`W` specialization.
        $bounded = self::enclosingBoundedNames($methodParams, $classParamNames);
        if (count($bounded) !== count($methodParams)) {
            return false;
        }

        // Each parameter is either a bare bounded name (`U $value` — the only allowed occurrence) or
        // must not reference a bounded name at all (a nested `Box<U>` makes the concrete U observable).
        $seenAsInput = [];
        foreach ($method->params as $param) {
            $type = $param->type;
            if ($type instanceof Name
                && count($type->getParts()) === 1
                && in_array($type->toString(), $bounded, true)
            ) {
                $seenAsInput[] = $type->toString();
                continue;
            }
            if (self::typeReferencesBounded($type, $bounded)) {
                return false;
            }
        }

        // Every bounded parameter must be used as a direct input at least once (else it is only
        // structural / unused — not the erasable shape).
        foreach ($bounded as $name) {
            if (!in_array($name, $seenAsInput, true)) {
                return false;
            }
        }

        // The return type must not mention a bounded name, and the body must not use one structurally
        // (a forwarded turbofish lives in an attribute and is invisible to this Name search).
        if (self::typeReferencesBounded($method->returnType, $bounded)) {
            return false;
        }
        return !self::bodyUsesBounded($method, $bounded);
    }

    /**
     * Whether a type node (a parameter/return type: a Name, a nullable/union/intersection of them)
     * mentions a bounded name — bare (`U`) or nested in another type's args (`Box<U>`).
     *
     * @param list<string> $bounded
     */
    private static function typeReferencesBounded(?Node $type, array $bounded): bool
    {
        if ($type instanceof NullableType) {
            return self::typeReferencesBounded($type->type, $bounded);
        }
        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            return self::anyTypeReferencesBounded($type->types, $bounded);
        }
        if ($type instanceof Name) {
            if (count($type->getParts()) === 1 && in_array($type->toString(), $bounded, true)) {
                return true;
            }
            return self::genericArgsReferenceBounded($type, $bounded);
        }
        return false; // scalar Identifier, or no type
    }

    /**
     * @param array<Node\Identifier|Node\IntersectionType|Node\Name|Node\UnionType> $members
     * @param list<string> $bounded
     */
    private static function anyTypeReferencesBounded(array $members, array $bounded): bool
    {
        foreach ($members as $member) {
            if (self::typeReferencesBounded($member, $bounded)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether any AST `Name` in the method body mentions a bounded name structurally — `new U`,
     * `instanceof U`, `U::CONST`, or a nested `new Box::<U>()`. A turbofish forward (`$this->m::<U>()`)
     * keeps its args in `ATTR_METHOD_GENERIC_ARGS`, which is not an AST child, so it is not found here.
     *
     * @param list<string> $bounded
     */
    private static function bodyUsesBounded(ClassMethod $method, array $bounded): bool
    {
        if ($method->stmts === null) {
            return false;
        }
        /** @var list<Name> $names */
        $names = (new NodeFinder())->findInstanceOf($method->stmts, Name::class);
        foreach ($names as $name) {
            if (count($name->getParts()) === 1 && in_array($name->toString(), $bounded, true)) {
                return true;
            }
            if (self::genericArgsReferenceBounded($name, $bounded)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a Name's `ATTR_GENERIC_ARGS` (the `<...>` of a generic type reference) contains a
     * bounded type-parameter reference (`Box<U>` / `Map<K, U>`).
     *
     * @param list<string> $bounded
     */
    private static function genericArgsReferenceBounded(Name $name, array $bounded): bool
    {
        $args = $name->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
        if (!is_array($args)) {
            return false;
        }
        /** @var list<TypeRef> $args */
        foreach ($args as $arg) {
            if (self::refTreeHasBounded($arg, $bounded)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The TypeRef list an erasable method is mangled on: each enclosing-bounded parameter contributes
     * the **concrete** value of its bound's class parameter (`U : E` on `Box<Fruit>` → `Fruit`). The
     * call site and the class specialization both compute this from the same `$classConcrete` map, so
     * they produce the byte-identical mangled name (`contains_T_<hash>`) — the cross-cutting invariant.
     *
     * @param list<TypeParam> $methodParams
     * @param array<string, TypeRef> $classConcrete class-parameter name → its concrete TypeRef
     * @return list<TypeRef>
     */
    public static function mangleArgs(array $methodParams, array $classConcrete): array
    {
        $out = [];
        foreach ($methodParams as $param) {
            if ($param->bound instanceof BoundLeaf && isset($classConcrete[$param->bound->type->name])) {
                $out[] = $classConcrete[$param->bound->type->name];
            }
        }
        return $out;
    }

    /**
     * Whether a TypeRef tree contains a type-parameter reference to one of $bounded (the `U` in
     * `Box<U>` / `Map<K, U>`).
     *
     * @param list<string> $bounded
     */
    public static function refTreeHasBounded(TypeRef $ref, array $bounded): bool
    {
        if ($ref->isTypeParam && in_array($ref->name, $bounded, true)) {
            return true;
        }
        foreach ($ref->args as $arg) {
            if (self::refTreeHasBounded($arg, $bounded)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The names of the method's type parameters whose bound references an enclosing class parameter.
     *
     * @param list<TypeParam> $methodParams
     * @param list<string> $classParamNames
     * @return list<string>
     */
    private static function enclosingBoundedNames(array $methodParams, array $classParamNames): array
    {
        $out = [];
        foreach ($methodParams as $param) {
            if ($param->bound !== null && self::boundIsEnclosingLeaf($param->bound, $classParamNames)) {
                $out[] = $param->name;
            }
        }
        return $out;
    }

    /**
     * Whether the bound is *exactly* a single enclosing class type parameter (`<U : E>`). A compound
     * bound (`<U : \Stringable & E>`) is deliberately excluded: erasing `U` to `E` would drop the
     * `\Stringable` half, so it must keep the per-`U` lowering (where the whole bound is checked).
     *
     * @param list<string> $classParamNames
     */
    private static function boundIsEnclosingLeaf(BoundExpr $bound, array $classParamNames): bool
    {
        return $bound instanceof BoundLeaf
            && $bound->type->isTypeParam
            && in_array($bound->type->name, $classParamNames, true);
    }
}
