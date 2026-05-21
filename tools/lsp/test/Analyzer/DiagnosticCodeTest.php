<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Analyzer;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\Lsp\Analyzer\DiagnosticCode;

/**
 * The triage helper is the central place that decides which code applies to a
 * `RuntimeException` from `Registry::recordInstantiation` — bound violation
 * vs. hash collision. Tested in isolation so a phrasing change in Registry's
 * error builders surfaces here loudly rather than via miscategorised
 * diagnostics in the editor.
 */
final class DiagnosticCodeTest extends TestCase
{
    public function testHashCollisionMessageMapsToCollisionCode(): void
    {
        // Phrasing must match Registry::collisionMessage's leading line.
        $e = new RuntimeException(
            "Hash collision detected while monomorphizing generics.\n"
            . 'Two distinct instantiations produced the same specialized FQCN...'
        );

        self::assertSame(
            DiagnosticCode::HashCollision,
            DiagnosticCode::fromRegistryRecordInstantiationException($e),
        );
    }

    public function testBoundViolationMessageMapsToBoundViolationCode(): void
    {
        // Phrasing must match Registry::validateBounds's leading line.
        $e = new RuntimeException(
            "Generic bound violated while instantiating App\\Box<int>.\n"
            . '  type parameter T is bounded by Stringable...'
        );

        self::assertSame(
            DiagnosticCode::BoundViolation,
            DiagnosticCode::fromRegistryRecordInstantiationException($e),
        );
    }

    public function testUnknownPrefixFallsBackToBoundViolation(): void
    {
        // Conservative default: an unfamiliar message phrasing is more likely
        // to be a bound issue than a collision (the latter is rare and
        // intentionally distinct in its phrasing). The fallback keeps the
        // diagnostic from looking dropped while flagging that Registry's
        // error catalog has grown a path we didn't categorise.
        $e = new RuntimeException('Something unexpected happened.');

        self::assertSame(
            DiagnosticCode::BoundViolation,
            DiagnosticCode::fromRegistryRecordInstantiationException($e),
        );
    }

    public function testEachCodeMapsToTheLspWireString(): void
    {
        // Locks the backed-enum values that the LSP wire format depends on
        // (via DiagnosticTranslator). Renaming a case without updating the
        // backed value would silently break editor-side code-pattern
        // matching ("xphp.bound" -> "xphp.boundViolation" e.g.).
        self::assertSame('xphp.parse', DiagnosticCode::Parse->value);
        self::assertSame('xphp.parse.internal', DiagnosticCode::ParseInternal->value);
        self::assertSame('xphp.definition', DiagnosticCode::Definition->value);
        self::assertSame('xphp.bound', DiagnosticCode::BoundViolation->value);
        self::assertSame('xphp.collision', DiagnosticCode::HashCollision->value);
    }
}
