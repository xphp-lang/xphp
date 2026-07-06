<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\UnionType;

/**
 * Extracts the {@see ClosureSignature} a closure LITERAL (`fn` / `function`)
 * actually declares, so {@see ClosureSignatureConformance} can check it against a
 * `Closure(...)` target. The candidate types are resolved to the same FQN /
 * scalar space the parser resolved the target's types into, using the enclosing
 * file's {@see NamespaceContext}.
 *
 * Gradual by construction: an untyped parameter becomes `mixed` (the top type,
 * always conforms), an absent return stays absent (gradual), and a
 * nullable / union / intersection leaf is carried as {@see SigRaw} (gradual until
 * WI-03) — so extraction never manufactures a false mismatch.
 */
final class ClosureLiteralSignature
{
    public static function extract(Closure|ArrowFunction $literal, NamespaceContext $ctx): ClosureSignature
    {
        $params = array_map(
            static fn (Param $p): ClosureSignatureParam => new ClosureSignatureParam(
                self::resolveParamType($p->type, $ctx),
                $p->byRef,
                $p->variadic,
                $p->default !== null,
            ),
            $literal->params,
        );

        // Candidate closures are never nullable (a `?Closure` is a target concept);
        // the flag defaults false and is not read for a candidate.
        return new ClosureSignature($params, self::resolveType($literal->returnType, $ctx));
    }

    /**
     * A parameter always has a type slot: an untyped parameter is `mixed` (the
     * top type — always conforms under contravariance).
     */
    private static function resolveParamType(?Node $type, NamespaceContext $ctx): SigType
    {
        // No isScalar flag: the engine classifies `mixed` by name (as gradual),
        // so the flag would be dead here.
        return self::resolveType($type, $ctx) ?? new SigTypeRef(new TypeRef('mixed'));
    }

    /**
     * Resolve a nikic type node to a {@see SigType}, or null when the slot is
     * absent (an untyped return ⇒ gradual). A compound type (`?X`, union,
     * intersection) is carried raw.
     */
    private static function resolveType(?Node $type, NamespaceContext $ctx): ?SigType
    {
        // @infection-ignore-all — removing this early return is equivalent: a null
        // node matches none of the branches below and falls through to the same
        // bottom `return null`.
        if ($type === null) {
            return null;
        }
        if ($type instanceof NullableType || $type instanceof UnionType || $type instanceof IntersectionType) {
            return new SigRaw(self::rawText($type));
        }
        // @infection-ignore-all — a param/return type node is only ever null, a
        // compound (handled above), or a simple Identifier/Name, so the negated
        // predicate is unreachable-different: nothing else reaches this line.
        if ($type instanceof Identifier || $type instanceof Name) {
            $name = $type->toString();
            // @infection-ignore-all UnwrapStrToLower is killed by a capital-cased
            // scalar test; the case-fold is load-bearing (`Int` ⇒ `int`).
            $lower = strtolower($name);
            if (in_array($lower, XphpSourceParser::SCALAR_TYPES, true)) {
                return new SigTypeRef(new TypeRef($lower, [], isScalar: true));
            }
            return new SigTypeRef(new TypeRef($ctx->resolveAgainstContext($name)));
        }
        // An unrecognized type-node shape (should not occur for a well-formed
        // literal) is treated as absent ⇒ gradual, never a false mismatch.
        return null;
    }

    /**
     * A readable rendering of a compound type for the raw leaf.
     *
     * @infection-ignore-all — display-only: a SigRaw leaf is always accepted
     * gradually, so this text never surfaces in a diagnostic; and each
     * union/intersection member is a plain Name/Identifier that renders as itself,
     * so the array_map is equivalent to a direct implode.
     */
    private static function rawText(Node $type): string
    {
        if ($type instanceof NullableType) {
            return '?' . self::rawText($type->type);
        }
        if ($type instanceof UnionType) {
            return implode('|', array_map(self::rawText(...), $type->types));
        }
        if ($type instanceof IntersectionType) {
            return implode('&', array_map(self::rawText(...), $type->types));
        }
        if ($type instanceof Identifier || $type instanceof Name) {
            return $type->toString();
        }
        return '?';
    }
}
