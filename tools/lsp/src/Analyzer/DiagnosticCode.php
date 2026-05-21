<?php

declare(strict_types=1);

namespace XPHP\Lsp\Analyzer;

use RuntimeException;

/**
 * Categorical identifier for a diagnostic. Backed by the string the LSP wire
 * format expects in `Diagnostic.code`, so handlers can pass `$code->value`
 * straight through to phpactor's Diagnostic without translation.
 *
 * Codes intentionally use dotted notation (`xphp.<category>[.<subcategory>]`)
 * so an editor's code-aware config can pattern-match (e.g. "downgrade
 * xphp.parse.internal to info") without parsing message text.
 *
 * `fromRegistryRecordInstantiationException` is the central place that decides
 * which code applies to a `RuntimeException` thrown from
 * `Registry::recordInstantiation`. That method has two distinct error paths
 * (bound violation vs. hash collision) which were previously both surfaced as
 * `xphp.bound`. Mis-coding a collision as a bound violation pointed users at
 * the wrong fix (look at bounds vs. raise XPHP_HASH_LENGTH). Centralising the
 * triage here means future Registry exception paths slot in without
 * smearing magic strings across catch blocks.
 */
enum DiagnosticCode: string
{
    /** nikic/php-parser threw — the source isn't valid PHP after angle-stripping. */
    case Parse = 'xphp.parse';

    /** XphpSourceParser threw RuntimeException — internal contract violation, rare. */
    case ParseInternal = 'xphp.parse.internal';

    /** Registry::recordDefinition rejected a duplicate template declaration. */
    case Definition = 'xphp.definition';

    /** Registry::recordInstantiation rejected an arg list against its declared bound. */
    case BoundViolation = 'xphp.bound';

    /** Two distinct instantiations hashed to the same generated FQCN — raise XPHP_HASH_LENGTH. */
    case HashCollision = 'xphp.collision';

    /**
     * Map a RuntimeException raised by Registry::recordInstantiation to its
     * diagnostic code. The Registry doesn't (currently) use a typed exception
     * hierarchy, so we triage by the error message's leading phrase. The
     * Registry's error builders (Registry::collisionMessage,
     * Registry::validateBounds) use stable prefixes documented in their
     * docblocks — if those phrasings shift, this triage breaks and the bound
     * fallback kicks in.
     */
    public static function fromRegistryRecordInstantiationException(RuntimeException $e): self
    {
        if (str_starts_with($e->getMessage(), 'Hash collision')) {
            return self::HashCollision;
        }
        return self::BoundViolation;
    }
}
