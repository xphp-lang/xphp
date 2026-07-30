<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use XPHP\Diagnostics\SourceLocation;

/**
 * A deferred obligation to check a used type alias's parameter bounds against its concrete arguments.
 *
 * Alias expansion runs per file during parsing, before the whole-program {@see TypeHierarchy} exists,
 * so a bound like `T : Stringable` cannot be verified at the point of use. Instead the resolved
 * parameters and the (padded, ground) arguments are captured here and verified in one pass once the
 * hierarchy is built — see {@see AliasBoundValidator}.
 */
final readonly class AliasBoundObligation
{
    /**
     * @param list<TypeParam> $typeParams the alias's resolved parameters (name + bound), in order
     * @param list<TypeRef>   $args       the concrete, padded type arguments supplied at the use site
     */
    public function __construct(
        public array $typeParams,
        public array $args,
        public string $label,
        public SourceLocation $location,
    ) {
    }
}
