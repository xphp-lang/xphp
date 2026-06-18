<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

/**
 * Recursive representation of a type expression after parsing + name resolution.
 *
 * Four flavors:
 *  - Scalar:       TypeRef('string',                [], isScalar: true)
 *  - Class:        TypeRef('App\\Models\\Plastic',  [])
 *  - Generic:      TypeRef('App\\Containers\\List', [TypeRef('App\\Models\\Plastic')])
 *  - Type param:   TypeRef('T',                     [], isTypeParam: true)  — only appears inside template bodies before substitution.
 */
final readonly class TypeRef
{
    /**
     * @param list<TypeRef> $args Empty for non-generic types.
     */
    public function __construct(
        public string $name,
        public array $args = [],
        public bool $isScalar = false,
        public bool $isTypeParam = false,
        // Set by the parser for a bare, single-segment, non-imported bound/default
        // name used inside a generic context that is NOT a declared type parameter —
        // the bound/default analogue of XphpSourceParser::ATTR_SUSPECT_UNDECLARED_TYPE
        // (TypeRefs carry no AST attributes). The undeclared-type validator flags it
        // when `name` resolves to no declared/built-in type.
        public bool $suspectUndeclared = false,
    ) {
    }

    public function isGeneric(): bool
    {
        return $this->args !== [];
    }

    /**
     * True iff this TypeRef and all nested TypeRefs are concrete (no unresolved type-param references).
     */
    public function isConcrete(): bool
    {
        if ($this->isTypeParam) {
            return false;
        }
        foreach ($this->args as $a) {
            if (!$a->isConcrete()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Canonical, deterministic string form used as the hash input for collision-safe name mangling.
     * Uses `<`, `>`, and `,` as delimiters — all illegal in PHP class names so the encoding is unambiguous.
     * Leading backslashes are stripped so `App\Foo` and `\App\Foo` produce the same canonical form.
     */
    public function canonical(): string
    {
        $name = ltrim($this->name, '\\');
        if (!$this->isGeneric()) {
            return $name;
        }
        $inner = implode(',', array_map(static fn (TypeRef $a): string => $a->canonical(), $this->args));
        return $name . '<' . $inner . '>';
    }

    /**
     * Flat human-readable form for serialization (registry.json).
     */
    public function toDisplayString(): string
    {
        if (!$this->isGeneric()) {
            return $this->name;
        }
        $inner = array_map(static fn (TypeRef $a): string => $a->toDisplayString(), $this->args);
        return $this->name . '<' . implode(', ', $inner) . '>';
    }
}
