<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

/**
 * Split worse-reflection's `Type::__toString()` output into the
 * constituent class FQNs the LSP can navigate to / hover on / find
 * usages for.
 *
 * Grammar (the subset worse-reflection produces for receiver types):
 *
 *   union        := intersection ('|' intersection)*
 *   intersection := atom ('&' atom)*
 *   atom         := '?'? '\\'? Name        (e.g. `?\App\Models\User`)
 *                 | '(' intersection ')'   (parenthesized for precedence)
 *                 | 'null'                 (dropped from results)
 *                 | scalar / literal       (rejected via ClassFqnPredicate)
 *
 * Output shape (`TypeUnionParts`):
 *
 *   list<list<string>>
 *
 * Outer list = union arms (OR).  Inner list = intersection components
 * (AND) within each arm.  Single-class types decompose to `[[Fqn]]`.
 *
 * Examples:
 *   `App\User`                           -> `[['App\User']]`
 *   `?App\User`                          -> `[['App\User']]`
 *   `A|B`                                -> `[['A'], ['B']]`
 *   `A&B`                                -> `[['A', 'B']]`
 *   `(A&B)|C`                            -> `[['A', 'B'], ['C']]`
 *   `(A&B)|(C&D)|null`                   -> `[['A', 'B'], ['C', 'D']]`
 *   `<missing>` / `0` / `'foo'` / ''     -> `[]` (no class FQNs)
 *
 * Callers decide how to consume the structure:
 *   - GTD / Hover / FindUsages: fan out and merge results across every
 *     FQN.  The union/intersection distinction is mostly cosmetic for
 *     these (you still navigate to all referenced classes).
 *   - Completion: union arms are OR-of-instances, so the popup is the
 *     UNION of each arm's members (the user-specified UX: more
 *     options).  Intersection components within an arm are AND-of-
 *     interfaces, so the arm's members are the INTERSECTION of each
 *     constituent's members (the user-specified UX: hide arm-unique
 *     members).
 */
final class TypeUnionSplitter
{
    /**
     * @return list<list<string>>
     */
    public static function split(string $typeName): array
    {
        $name = trim($typeName);
        if ($name === '' || $name === '<missing>') {
            return [];
        }
        // Strip a single leading nullable marker -- `?A|B` is shorthand
        // for `A|B|null`, semantically equivalent for navigation.
        if (str_starts_with($name, '?')) {
            $name = ltrim(substr($name, 1));
        }

        // Single-class fast path: no union / intersection / grouping
        // operators present.  Validate as a class FQN before yielding.
        if (strpbrk($name, '|&()') === false) {
            return self::isClassAtom($name) ? [[ltrim($name, '\\')]] : [];
        }

        $arms = [];
        foreach (self::splitTopLevel($name, '|') as $arm) {
            $components = self::collectIntersectionComponents($arm);
            if ($components === []) {
                continue;
            }
            // Dedup repeated FQNs within the same intersection arm.
            $arms[] = array_values(array_unique($components));
        }
        return $arms;
    }

    /**
     * Walk one union-arm string, recursively unwrapping any `(...)`
     * groups, and collect the leaf class-FQN atoms.  Returns `[]` if
     * the arm contains no class atom (e.g. pure `null`, `(A|null)`
     * where worse-reflection's nesting drops everything).
     *
     * @return list<string>
     */
    private static function collectIntersectionComponents(string $arm): array
    {
        $arm = trim($arm);
        // Recursive paren unwrap.  `((A&B))` -> `(A&B)` -> `A&B`.
        while (str_starts_with($arm, '(') && str_ends_with($arm, ')')) {
            $inner = trim(substr($arm, 1, -1));
            if ($inner === '' || $inner === $arm) {
                break;
            }
            $arm = $inner;
        }
        if ($arm === '') {
            return [];
        }
        $components = [];
        foreach (self::splitTopLevel($arm, '&') as $component) {
            $atom = trim($component);
            // The `&`-split may have left a nested `(...)` if the
            // arm was `(A&B)&C` -- unwrap each piece too.
            while (str_starts_with($atom, '(') && str_ends_with($atom, ')')) {
                $inner = trim(substr($atom, 1, -1));
                if ($inner === '' || $inner === $atom) {
                    break;
                }
                $atom = $inner;
            }
            // A nested intersection survived the unwrap (e.g. atom is
            // now `A&B`).  Recurse to collect its components.
            if (strpbrk($atom, '|&()') !== false) {
                foreach (self::collectIntersectionComponents($atom) as $nested) {
                    $components[] = $nested;
                }
                continue;
            }
            $atom = ltrim($atom, '?');
            if (!self::isClassAtom($atom)) {
                continue;
            }
            $components[] = ltrim($atom, '\\');
        }
        return $components;
    }

    /**
     * Split `$source` on the top-level occurrences of `$delimiter`
     * (i.e. not inside `( ... )` groups).  worse-reflection produces
     * canonical forms where intersection is only ever inside parens
     * within a union (`(A&B)|C`), so we only need single-character
     * delimiter handling, no string escapes.
     *
     * @return list<string>
     */
    private static function splitTopLevel(string $source, string $delimiter): array
    {
        $parts = [];
        $depth = 0;
        $buffer = '';
        $len = strlen($source);
        for ($i = 0; $i < $len; $i++) {
            $ch = $source[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($ch === $delimiter && $depth === 0) {
                $parts[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }
        if ($buffer !== '') {
            $parts[] = $buffer;
        }
        return $parts;
    }

    /**
     * Is `$atom` a single class-FQN-shaped string (after `?` / `(`
     * stripping)?  Reuses {@see ClassFqnPredicate::is} so the
     * accepted/rejected shape is exactly what the rest of the LSP
     * agrees is a navigable class.
     *
     * `null` is the most common non-class atom in unions and is
     * explicitly rejected: `Type::__toString()` emits the literal
     * string `null` (lowercase), which fails the
     * `^[A-Za-z_]` head test only when the regex is anchored
     * case-sensitively... actually `null` starts with `n` which IS
     * in `[A-Za-z]`, so the predicate would accept it.  Filter
     * `null` (and the equivalent `mixed` / `void` / scalar names)
     * explicitly.
     */
    private static function isClassAtom(string $atom): bool
    {
        $atom = trim($atom);
        if ($atom === '') {
            return false;
        }
        // Scalar / pseudo-type aliases that pass the leading-letter
        // shape test in ClassFqnPredicate but are not real classes.
        // The full set worse-reflection emits via `Type::__toString()`:
        // `null`, `mixed`, `void`, `bool`, `true`, `false`, `int`,
        // `float`, `string`, `array`, `object`, `iterable`, `callable`,
        // `never`, `static`, `self`, `parent`.
        $reserved = [
            'null', 'mixed', 'void', 'bool', 'true', 'false', 'int',
            'float', 'string', 'array', 'object', 'iterable', 'callable',
            'never', 'static', 'self', 'parent',
        ];
        if (in_array(strtolower(ltrim($atom, '\\')), $reserved, true)) {
            return false;
        }
        return ClassFqnPredicate::is($atom);
    }
}
