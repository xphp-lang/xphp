<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

/**
 * The substituted-type view of a method-call site, used by hover to
 * render the method signature with the call site's type-args baked in.
 *
 *  `$users->first()`  where `$users: Collection<User>` and
 *  `Collection<T>::first(T $needle = null): ?T`
 *
 * yields:
 *  - `returnType` = "?App\\Models\\User"
 *  - `paramTypes` = ["needle" => "App\\Models\\User"]
 *
 * Either field may be null individually when that specific piece can't
 * be substituted (e.g. a method with no return-type annotation has
 * `returnType=null`; a parameter typed against a non-generic class has
 * no entry in `paramTypes`).  An entirely empty result means the resolver
 * found nothing -- caller falls back to its unsubstituted render.
 */
final readonly class MethodCallSubstitution
{
    /**
     * @param array<string, string> $paramTypes  paramName -> rendered type string
     */
    public function __construct(
        public ?string $returnType,
        public array $paramTypes,
    ) {
    }
}
