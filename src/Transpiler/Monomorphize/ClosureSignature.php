<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

/**
 * A parsed closure signature type — `Closure(int $x, string ...$rest): bool` — as
 * it appears in a parameter, return, or property type position. Erased to a bare
 * `\Closure` in the emitted PHP; the parsed shape is carried as an AST attribute
 * ({@see XphpSourceParser::ATTR_CLOSURE_SIG}) purely so the compile-time
 * conformance validator can read it.
 *
 * A `?Closure(...)` sets {@see $nullable}. An ABSENT return type is represented as
 * `$return === null`, kept distinct from an explicit `: mixed`: both accept any
 * candidate return, but the distinction (absent = gradual on the candidate side)
 * matters to the conformance rules.
 */
final readonly class ClosureSignature
{
    /**
     * @param list<ClosureSignatureParam> $params In source order. A variadic
     *        parameter, if present, is always the last (enforced at parse time).
     */
    public function __construct(
        public array $params,
        public ?SigType $return,
        public bool $nullable = false,
    ) {
    }
}
