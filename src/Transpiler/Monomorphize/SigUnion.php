<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

/**
 * A closure-signature leaf that is a union type — `A|B`, or a nullable `?A`
 * modelled as `A|null`. Each member is itself a {@see SigType}, so a member may
 * be a plain {@see SigTypeRef}, a nested {@see SigClosure}, or (on the candidate
 * side, from nikic's already-nested AST) a {@see SigIntersection} for a DNF
 * member like the `A&B` in `(A&B)|C`.
 *
 * Subtyping (both arms sound — {@see ClosureSignatureConformance}):
 *   - as the SUPER (`X <: A|B`): X conforms to some member — provably-not iff X
 *     is provably not a subtype of *every* member.
 *   - as the SUB (`A|B <: Y`): every member conforms to Y — provably-not iff
 *     *some* member is provably not a subtype of Y.
 */
final readonly class SigUnion extends SigType
{
    /**
     * @param list<SigType> $members Two or more, in source order. Order is not
     *        significant to conformance (the rules quantify over the set).
     */
    public function __construct(
        public array $members,
    ) {
    }
}
