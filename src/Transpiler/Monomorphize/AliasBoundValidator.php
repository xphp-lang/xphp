<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use XPHP\Diagnostics\DiagnosticCollector;

/**
 * Verifies the type-alias parameter-bound obligations captured during parsing, once the whole-program
 * {@see TypeHierarchy} exists. Each obligation runs through {@see Registry::checkAliasBounds} — the
 * same check a regular generic instantiation uses — so an alias-parameter bound violation reports an
 * identical `xphp.bound_violation`: thrown in compile mode (no collector), collected in check mode.
 */
final readonly class AliasBoundValidator
{
    public static function validate(
        AliasBoundObligationCollector $obligations,
        TypeHierarchy $hierarchy,
        ?DiagnosticCollector $diagnostics = null,
    ): void {
        foreach ($obligations->all() as $obligation) {
            Registry::checkAliasBounds(
                $obligation->typeParams,
                $obligation->args,
                $hierarchy,
                $obligation->label,
                $diagnostics,
                $obligation->location,
            );
        }
    }
}
