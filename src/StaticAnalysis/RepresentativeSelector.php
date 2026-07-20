<?php

declare(strict_types=1);

namespace XPHP\StaticAnalysis;

use XPHP\Transpiler\Monomorphize\GenericInstantiation;
use XPHP\Transpiler\Monomorphize\Registry;
use XPHP\Transpiler\Monomorphize\TypeRef;

/**
 * Picks one {@see Representative} specialization per template from the live
 * registry — the first by sorted generated FQN, so the choice (and therefore
 * the analysed file set and any resulting diagnostics) is deterministic across
 * runs regardless of source-file or hash-map ordering.
 */
final class RepresentativeSelector
{
    /**
     * @param string $generatedDir absolute path to `cache/Generated`
     * @return list<Representative>
     */
    public static function select(Registry $registry, string $generatedDir): array
    {
        /** @var array<string, list<GenericInstantiation>> $byTemplate */
        $byTemplate = [];
        foreach ($registry->instantiations() as $instantiation) {
            $byTemplate[$instantiation->templateFqn][] = $instantiation;
        }

        $representatives = [];
        foreach ($byTemplate as $templateFqn => $instantiations) {
            // Invariant: the parser emits template FQNs without a leading backslash, so
            // the instantiation's templateFqn matches the key recordDefinition stored
            // under — this lookup hits for every defined template.
            $definition = $registry->definition($templateFqn);
            if ($definition === null) {
                // Instantiated-but-never-defined: there's no declaration to map a
                // finding back to. The generic checks already report this; skip it
                // here rather than emit a finding with no source location.
                continue;
            }

            usort(
                $instantiations,
                static fn (GenericInstantiation $a, GenericInstantiation $b): int
                    => strcmp($a->generatedFqn, $b->generatedFqn),
            );
            $chosen = $instantiations[0];

            $representatives[] = new Representative(
                $chosen->generatedFqn,
                self::fqnToPath($chosen->generatedFqn, $generatedDir),
                $templateFqn,
                self::label($chosen),
                $definition->sourceFile,
                $definition->templateAst->getStartLine(),
            );
        }

        usort(
            $representatives,
            static fn (Representative $a, Representative $b): int => strcmp($a->generatedFqn, $b->generatedFqn),
        );

        return $representatives;
    }

    private static function fqnToPath(string $generatedFqn, string $generatedDir): string
    {
        $prefix = Registry::GENERATED_NAMESPACE_PREFIX . '\\';
        $relative = str_starts_with($generatedFqn, $prefix)
            ? substr($generatedFqn, strlen($prefix))
            : $generatedFqn;

        return rtrim($generatedDir, '/') . '/' . str_replace('\\', '/', $relative) . '.php';
    }

    private static function label(GenericInstantiation $instantiation): string
    {
        $args = array_map(
            static fn (TypeRef $type): string => $type->toDisplayString(),
            $instantiation->concreteTypes,
        );

        return $instantiation->templateFqn . '<' . implode(', ', $args) . '>';
    }
}
