<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

/**
 * The identity of a generic-method template: an (owning-class FQN, method-name) pair and the
 * single source of truth for the `"$classFqn::$method"` string under which those templates are
 * indexed. Composing the string in one place — used by both the builder that writes the map and
 * {@see TemplateIndex::methodTemplate()} that reads it — keeps the two sides provably in step,
 * and {@see parse()} is its exact inverse for the strip pass that walks the map back.
 */
final readonly class MethodKey
{
    public function __construct(
        public string $classFqn,
        public string $method,
    ) {
    }

    /** The composed map key. The one place the `::` join lives. */
    public function __toString(): string
    {
        return $this->classFqn . '::' . $this->method;
    }

    /** Split a composed map key back into its parts — the inverse of {@see __toString()}. */
    public static function parse(string $key): self
    {
        // @infection-ignore-all — explode limit 2 vs 3 is equivalent: a method key holds exactly
        // one `::` (FQNs join on `\`, method names are bare identifiers), so a third capture is
        // always empty and the limit never changes the result.
        [$classFqn, $method] = explode('::', $key, 2);
        return new self($classFqn, $method);
    }
}
