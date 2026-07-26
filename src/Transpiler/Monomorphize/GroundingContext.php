<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;

/**
 * Context for grounding method-generic turbofish markers inside one freshly specialized
 * class ({@see GenericMethodCompiler::groundSpecializedClass}).
 *
 * A specialized class body may carry call markers that the class substitution just made
 * concrete (`self::gen::<T>` → `self::gen::<int>`). Grounding them re-uses the Phase-1a
 * rewrite machinery, but three decisions differ from a user-file walk and are driven by
 * this context:
 *
 *  - **identity**: the walked ClassLike is a nameless clone; `templateFqn` stands in as
 *    the current class FQN so `self::` resolution and method-template lookup key against
 *    the retained Phase-1a index, and `classArgs` are the instantiation's concrete type
 *    arguments (parallel to the template's declared parameters) for composing the class
 *    substitution into method specialization.
 *  - **append target**: a member specialized from the spec's own template lands on the
 *    spec itself (`spec`), deduped per specialization via `generatedFqn` — never on the
 *    template class, which lowers to a marker interface in emitted output.
 *  - **external collection**: members appended onto OTHER containers (a non-generic user
 *    class, a function namespace) are recorded in `externalAppends` so the fixed-point
 *    loop can collect their nested instantiation needs — user files were collected before
 *    these members existed.
 */
final class GroundingContext
{
    /** @var list<Node\Stmt> members appended onto containers other than the spec */
    public array $externalAppends = [];

    /**
     * @param list<TypeRef> $classArgs concrete instantiation args, parallel to the
     *                                 template's declared type parameters
     */
    public function __construct(
        public readonly ClassLike $spec,
        public readonly string $generatedFqn,
        public readonly string $templateFqn,
        public readonly array $classArgs,
    ) {
    }
}
