<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

/**
 * "Could this string plausibly be a class FQN that `reflectClassLike`
 * should be asked to resolve?"
 *
 * worse-reflection's `Type::__toString()` returns the canonical
 * source-language form for the inferred type -- which for intersection /
 * union / scalar / literal types is NOT a class FQN.  Examples seen
 * in 2026-05-27 prod logs:
 *
 *   (PhpParser\Node&PhpParser\Node\Expr\MethodCall)|(...\NullsafeMethodCall)
 *   PhpParser\Node\Stmt\ClassMethod|PhpParser\Node\Stmt\Function_
 *   ?App\Models\User                           (still a class -- accept)
 *   0   1                                      (integer literal types)
 *   ''                                         (empty string literal type)
 *   <missing>                                  (worse-reflection's "no inference")
 *
 * Feeding any of those to `reflectClassLike` causes a `SourceNotFound`
 * after a wasted locator walk + a stderr `[xphp-lsp locator] miss …`
 * line.  Worse: `PhpCompletionResolver` and friends sometimes catch
 * the resulting Throwable but ALSO fatal when the union's first
 * `instanceof ReflectionInterface` branch lacks `properties()`.
 *
 * Originally introduced as `PhpDefinitionResolver::isClassFqn` in
 * commit 4f22c4a (Phase 6, Fix 1).  Cycle C of the 2026-05-28 open
 * backlog promotes it to a shared utility so every `reflectClassLike`
 * caller short-circuits on non-class strings.
 *
 * Accepted shapes:
 *   - Single PHP identifier (with optional leading `\` and `?`)
 *   - Backslash-separated namespaced identifier
 *
 * Rejected shapes:
 *   - empty / `<missing>` (worse-reflection's "no type")
 *   - contains `|` (union)
 *   - contains `&` (intersection)
 *   - contains `(` `)` (compound type with explicit grouping)
 *   - first non-`\?` char is a digit (numeric literal type)
 *   - first non-`\?` char is a quote / dash / other non-identifier byte
 */
final class ClassFqnPredicate
{
    public static function is(string $typeName): bool
    {
        if ($typeName === '' || $typeName === '<missing>') {
            return false;
        }
        // Compound types (union / intersection / grouped) -- our locator
        // can't dispatch on them and `reflectClassLike` would throw.
        if (strpbrk($typeName, '|&()') !== false) {
            return false;
        }
        // Strip the leading nullable marker + leading backslash so the
        // first-character check inspects the actual identifier head.
        $head = ltrim($typeName, '\\?');
        if ($head === '') {
            return false;
        }
        // Class names must start with a letter or underscore -- never a
        // digit, quote, or operator.  This catches numeric-literal
        // types ("0", "1"), string-literal types ("'foo'"), and any
        // other oddball __toString output worse-reflection might emit.
        if (!preg_match('/^[A-Za-z_]/', $head)) {
            return false;
        }
        return true;
    }
}
