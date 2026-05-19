<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use InvalidArgumentException;
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
     * Default hash length (in hex chars) of the sha256 digest used in specialized class names.
     * 64 hex chars = full 256-bit digest — no truncation, no birthday-collision risk.
     * Configurable via the XPHP_HASH_LENGTH env var (read at CLI boot).
     */
    public const DEFAULT_HASH_HEX_LENGTH = 64;
    public const MIN_HASH_HEX_LENGTH = 16;
    public const MAX_HASH_HEX_LENGTH = 64;

    /** @var array<string, GenericDefinition> Keyed by template FQN. */
    private array $definitions = [];

    /** @var array<string, GenericInstantiation> Keyed by full generated FQCN. */
    private array $instantiations = [];

    public function __construct(private readonly int $hashLength = self::DEFAULT_HASH_HEX_LENGTH)
    {
        self::validateHashLength($this->hashLength);
    }

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
     * Detects hash collisions: if a different `(template, args)` pair has already produced the same
     * generated FQCN, throws with a self-contained error message explaining how to raise XPHP_HASH_LENGTH.
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

        $generatedFqn = self::generatedFqn($templateFqn, $args, $this->hashLength);
        $template = ltrim($templateFqn, '\\');

        if (isset($this->instantiations[$generatedFqn])) {
            $existing = $this->instantiations[$generatedFqn];
            if (!$this->isSameInstantiation($existing, $template, $args)) {
                throw new RuntimeException($this->collisionMessage($existing, $template, $args, $generatedFqn));
            }
            return $existing;
        }

        $this->instantiations[$generatedFqn] = new GenericInstantiation(
            $template,
            $args,
            $generatedFqn,
        );

        return $this->instantiations[$generatedFqn];
    }

    /**
     * @param list<TypeRef> $args
     */
    private function isSameInstantiation(GenericInstantiation $existing, string $template, array $args): bool
    {
        if (ltrim($existing->templateFqn, '\\') !== $template) {
            return false;
        }
        if (count($existing->concreteTypes) !== count($args)) {
            return false;
        }
        return self::canonicalArgList($existing->concreteTypes) === self::canonicalArgList($args);
    }

    /**
     * @param list<TypeRef> $args
     */
    private static function canonicalArgList(array $args): string
    {
        return implode('|', array_map(static fn (TypeRef $r): string => $r->canonical(), $args));
    }

    /**
     * @param list<TypeRef> $args
     */
    private function collisionMessage(
        GenericInstantiation $existing,
        string $template,
        array $args,
        string $generatedFqn,
    ): string {
        $suggested = min($this->hashLength * 2, self::MAX_HASH_HEX_LENGTH);
        if ($suggested <= $this->hashLength) {
            $suggested = self::MAX_HASH_HEX_LENGTH;
        }

        return sprintf(
            "Hash collision detected while monomorphizing generics.\n\n"
            . "Two distinct instantiations produced the same specialized FQCN:\n"
            . "  existing : %s\n"
            . "  new      : %s\n"
            . "  collision: %s\n\n"
            . "The current XPHP_HASH_LENGTH = %d is too short for this codebase.\n"
            . "Increase it (max %d, the full sha256 digest) and re-run, e.g.:\n\n"
            . "    XPHP_HASH_LENGTH=%d bin/xphp compile <source> <target> <cache>\n",
            self::formatInstantiation($existing->templateFqn, $existing->concreteTypes),
            self::formatInstantiation($template, $args),
            $generatedFqn,
            $this->hashLength,
            self::MAX_HASH_HEX_LENGTH,
            $suggested,
        );
    }

    /**
     * @param list<TypeRef> $args
     */
    private static function formatInstantiation(string $template, array $args): string
    {
        if ($args === []) {
            return $template;
        }
        $inner = implode(', ', array_map(static fn (TypeRef $r): string => $r->toDisplayString(), $args));
        return $template . '<' . $inner . '>';
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
     *   where <hash> is the first $hashLength hex chars of sha256 over the canonical arg-list string.
     *
     * Two examples illustrate why this is collision-safe:
     *   - App\Containers\Box<App\Models\Plastic>  →  XPHP\Generated\App\Containers\Box\T_<h1>
     *   - App\Other\Box<App\Models\Plastic>       →  XPHP\Generated\App\Other\Box\T_<h1>   (same hash; different namespace)
     *   - App\Containers\Box<App\Other\Plastic>   →  XPHP\Generated\App\Containers\Box\T_<h2>  (same namespace; different hash)
     *
     * @param list<TypeRef> $args
     */
    public static function generatedFqn(
        string $templateFqn,
        array $args,
        int $hashLength = self::DEFAULT_HASH_HEX_LENGTH,
    ): string {
        self::validateHashLength($hashLength);

        $template = ltrim($templateFqn, '\\');
        $canonical = implode('|', array_map(static fn (TypeRef $r): string => $r->canonical(), $args));
        $hash = substr(hash('sha256', $canonical), 0, $hashLength);

        return self::GENERATED_NAMESPACE_PREFIX . '\\' . $template . '\\T_' . $hash;
    }

    /**
     * Read XPHP_HASH_LENGTH from the environment, falling back to the default.
     * Throws on garbage values (non-numeric, out of range) so misconfiguration fails loud at boot.
     */
    public static function resolveHashLengthFromEnv(): int
    {
        $raw = getenv('XPHP_HASH_LENGTH');
        if ($raw === false || $raw === '') {
            return self::DEFAULT_HASH_HEX_LENGTH;
        }
        if (!ctype_digit($raw)) {
            throw new InvalidArgumentException(sprintf(
                'XPHP_HASH_LENGTH must be a positive integer; got "%s".',
                $raw,
            ));
        }
        $length = (int) $raw;
        self::validateHashLength($length);
        return $length;
    }

    private static function validateHashLength(int $length): void
    {
        if ($length < self::MIN_HASH_HEX_LENGTH || $length > self::MAX_HASH_HEX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Hash length must be between %d and %d (sha256 hex output); got %d.',
                self::MIN_HASH_HEX_LENGTH,
                self::MAX_HASH_HEX_LENGTH,
                $length,
            ));
        }
    }
}
