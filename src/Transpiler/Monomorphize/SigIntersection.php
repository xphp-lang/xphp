<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

/**
 * A closure-signature leaf that is an intersection type — `A&B`. Each member is
 * a {@see SigType} (in practice a {@see SigTypeRef} class leaf; a scalar member
 * would make the type invalid PHP and is kept as {@see SigRaw} instead).
 *
 * Subtyping is checked on the SUPER side only ({@see ClosureSignatureConformance}):
 *   - as the SUPER (`X <: A&B`): X conforms to *every* member — provably-not iff
 *     X is provably not a subtype of *some* member.
 *   - as the SUB (`A&B <: Y`): the sound rule is `A <: Y` OR `B <: Y`, but an
 *     intersection of mutually-incompatible members is *uninhabited* (`never`),
 *     and `never` is a subtype of everything — the engine has no inhabitation
 *     check, so decomposing the sub side would false-reject a valid `never`
 *     parameter. A sub-position intersection is therefore left GRADUAL (accepted);
 *     the inhabited-intersection reject is a tracked follow-up.
 */
final readonly class SigIntersection extends SigType
{
    /**
     * @param list<SigType> $members Two or more, in source order (order is not
     *        significant to conformance).
     */
    public function __construct(
        public array $members,
    ) {
    }
}
