<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Stmt\Class_;
use RuntimeException;

final class Registry
{
    /**
     * All specialized classes live under this namespace prefix; the full target FQCN
     * mirrors the original template's namespace and ends with a hash-based class name.
     * Example: App\Containers\Box<App\Models\Plastic> → XPHP\Generated\App\Containers\Box\T_<hash>
     */
    public const GENERATED_NAMESPACE_PREFIX = 'XPHP\\Generated';

    /**
     * Length (in hex chars) of the truncated sha256 used in specialized class names.
     * 16 hex chars = 64 bits — collision probability is ~N²/2^65 for N specializations;
     * negligible for any practical codebase.
     */
    private const HASH_HEX_LENGTH = 16;

    /** @var array<string, GenericDefinition> Keyed by template FQN. */
    private array $definitions = [];

    /** @var array<string, GenericInstantiation> Keyed by full generated FQCN. */
    private array $instantiations = [];

    /**
     * @param list<string> $typeParams
     */
    public function recordDefinition(
        string $templateFqn,
        string $templateShortName,
        array $typeParams,
        Class_ $templateAst,
        string $sourceFile,
    ): void {
        if (isset($this->definitions[$templateFqn])) {
            throw new RuntimeException(sprintf(
                'Generic template "%s" already declared (in %s); duplicate declaration in %s.',
                $templateFqn,
                $this->definitions[$templateFqn]->sourceFile,
                $sourceFile,
            ));
        }

        $this->definitions[$templateFqn] = new GenericDefinition(
            $templateFqn,
            $templateShortName,
            $typeParams,
            $templateAst,
            $sourceFile,
        );
    }

    /**
     * Recursively record an instantiation along with every nested generic sub-instantiation in its args.
     *
     * @param list<TypeRef> $args
     */
    public function recordInstantiation(string $templateFqn, array $args): GenericInstantiation
    {
        foreach ($args as $arg) {
            if ($arg->isGeneric()) {
                $this->recordInstantiation($arg->name, $arg->args);
            }
        }

        $generatedFqn = self::generatedFqn($templateFqn, $args);

        if (!isset($this->instantiations[$generatedFqn])) {
            $this->instantiations[$generatedFqn] = new GenericInstantiation(
                $templateFqn,
                $args,
                $generatedFqn,
            );
        }

        return $this->instantiations[$generatedFqn];
    }

    /**
     * @return array<string, GenericDefinition>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    public function definition(string $templateFqn): ?GenericDefinition
    {
        return $this->definitions[$templateFqn] ?? null;
    }

    /**
     * @return array<string, GenericInstantiation>
     */
    public function instantiations(): array
    {
        return $this->instantiations;
    }

    /**
     * @return array{definitions: list<array{name: string, typeParams: list<string>, sourceFile: string}>, instantiations: list<array{template: string, concreteTypes: list<string>, generatedFqn: string}>}
     */
    public function toArray(): array
    {
        $defs = [];
        foreach ($this->definitions as $def) {
            $defs[] = [
                'name'       => $def->templateFqn,
                'typeParams' => $def->typeParams,
                'sourceFile' => $def->sourceFile,
            ];
        }

        $inst = [];
        foreach ($this->instantiations as $i) {
            $inst[] = [
                'template'      => $i->templateFqn,
                'concreteTypes' => array_map(static fn (TypeRef $r): string => $r->toDisplayString(), $i->concreteTypes),
                'generatedFqn'  => $i->generatedFqn,
            ];
        }

        return ['definitions' => $defs, 'instantiations' => $inst];
    }

    /**
     * Compute the full target FQCN for a specialized class.
     *
     * Layout: XPHP\Generated\<template-fqcn>\T_<hash>
     *   where <hash> is the first 16 hex chars of sha256 over the canonical arg-list string.
     *
     * Two examples illustrate why this is collision-safe:
     *   - App\Containers\Box<App\Models\Plastic>  →  XPHP\Generated\App\Containers\Box\T_<h1>
     *   - App\Other\Box<App\Models\Plastic>       →  XPHP\Generated\App\Other\Box\T_<h1>   (same hash; different namespace)
     *   - App\Containers\Box<App\Other\Plastic>   →  XPHP\Generated\App\Containers\Box\T_<h2>  (same namespace; different hash)
     *
     * @param list<TypeRef> $args
     */
    public static function generatedFqn(string $templateFqn, array $args): string
    {
        $template = ltrim($templateFqn, '\\');
        $canonical = implode('|', array_map(static fn (TypeRef $r): string => $r->canonical(), $args));
        $hash = substr(hash('sha256', $canonical), 0, self::HASH_HEX_LENGTH);

        return self::GENERATED_NAMESPACE_PREFIX . '\\' . $template . '\\T_' . $hash;
    }
}
