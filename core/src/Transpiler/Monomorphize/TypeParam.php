<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

/**
 * A single type parameter on a generic template definition.
 *
 * `bound` is the optional upper bound: when present, every concrete instantiation must
 * satisfy it (concrete class extends / implements / equals the bound). The compiler
 * validates this at `Registry::recordInstantiation` time so violations fail the build
 * rather than waiting for a runtime TypeError.
 *
 * The bound is stored as a fully-qualified class/interface name with no leading
 * backslash — resolution against the source file's namespace + use statements happens
 * inside `XphpSourceParser::resolveAndAttach`.
 */
final readonly class TypeParam
{
    public function __construct(
        public string $name,
        public ?string $boundFqn = null,
    ) {
    }
}
