<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use InvalidArgumentException;

/**
 * An immutable type-parameter substitution: a map from type-parameter name to the
 * concrete {@see TypeRef} it grounds to (`T => int`, `E => App\Book`). This is the
 * value threaded through specialization and closure-signature grounding — everywhere
 * a `Closure(E): R` or a `Box<T>` reference is lowered to its concrete form.
 *
 * The map is read-only and pass-by-value throughout: it is only ever *built* (from a
 * template's parameters and a call/instantiation's concrete arguments), never mutated
 * once threaded. Merging a class-level substitution with a method-level one follows
 * PHP's inner-scope-wins rule — see {@see withOverrides()}.
 */
final readonly class Substitution
{
    /** @param array<string, TypeRef> $map */
    private function __construct(private array $map)
    {
    }

    /** The empty substitution (a static context, a non-generic callee, or a failed resolution). */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Wrap an already-built name→TypeRef map. The escape hatch for the irregular build
     * shapes (a conditional/partial overlay) the zip factories below don't cover.
     *
     * @param array<string, TypeRef> $map
     */
    public static function of(array $map): self
    {
        return new self($map);
    }

    /**
     * Zip a template's type parameters against a call/instantiation's concrete arguments,
     * in declaration order. The two lists MUST be the same length — every call site
     * guarantees this (arity-guarded resolution / all-concrete gates); a mismatch is a
     * bug, not a tolerated case.
     *
     * @param list<TypeParam> $params
     * @param list<TypeRef> $args
     */
    public static function fromParams(array $params, array $args): self
    {
        if (count($params) !== count($args)) {
            throw new InvalidArgumentException(
                'Substitution::fromParams expects one argument per type parameter, got '
                . count($args) . ' for ' . count($params) . ' parameter(s).',
            );
        }
        $map = [];
        foreach ($params as $i => $param) {
            $map[$param->name] = $args[$i];
        }
        return new self($map);
    }

    /**
     * Zip bare parameter NAMES against concrete arguments. Uses {@see array_combine},
     * so — like the raw call sites it replaces — a length mismatch raises a `ValueError`
     * (the compile-mode backstop where the caller's own arity guard is skipped).
     *
     * @param list<string> $names
     * @param list<TypeRef> $args
     */
    public static function fromNames(array $names, array $args): self
    {
        return new self(array_combine($names, $args));
    }

    /** The concrete type bound to `$name`, or null when the name is not substituted. */
    public function get(string $name): ?TypeRef
    {
        return $this->map[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->map[$name]);
    }

    public function isEmpty(): bool
    {
        return $this->map === [];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->map);
    }

    /**
     * Overlay `$other` on top of this substitution: on a name collision **`$other` wins**.
     * This encodes class-then-method layering — a method-level type parameter shadows a
     * class-level one of the same name, matching PHP's inner-scope-wins.
     */
    public function withOverrides(self $other): self
    {
        return new self(array_merge($this->map, $other->map));
    }

    /**
     * The underlying map. Interop escape hatch for the few raw-array consumers not yet
     * migrated; prefer the typed accessors.
     *
     * @return array<string, TypeRef>
     */
    public function toArray(): array
    {
        return $this->map;
    }
}
