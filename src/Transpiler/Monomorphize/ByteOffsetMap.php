<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

/**
 * Translates byte offsets from the stripped (post-xphp-rewrite) source back
 * to the original xphp source.
 *
 * Most replacements in `XphpSourceParser::scanAndStrip` use equal-length
 * spaces -- the `<...>` clauses on class headers, instantiation sites, and
 * method headers all keep byte positions identical to the original, so AST
 * offsets round-trip cleanly without any map.
 *
 * The exception is the `T[]` array-suffix sugar: original `T[]` (3 bytes)
 * is rewritten to `array` (5 bytes) so the post-strip parser sees a valid
 * PHP type-hint.  That shift breaks position round-tripping for every node
 * positioned to the right of a `T[]` token in the same file.  The
 * `documentSymbol` outline, hover spans, definition ranges -- anything
 * that emits a position back to the LSP client -- needs to translate the
 * AST's stripped-source offset into the original-source offset before
 * handing it off to `PositionMap`.
 *
 * Identity case (no length-changing replacement) is `O(1)` to construct
 * and trivially returns `strippedPos` -- the common case stays free.
 */
final class ByteOffsetMap
{
    /**
     * @var list<array{int, int, int, int}>
     *   Each segment: [strippedStart, strippedEnd, originalStart, deltaAfter].
     *   `strippedEnd` is exclusive.  `deltaAfter` is the cumulative
     *   `(originalLen - strippedLen)` after this segment -- positions >=
     *   strippedEnd map via `strippedPos + deltaAfter`.
     *
     *   Sorted ascending by strippedStart; segments don't overlap.
     */
    private function __construct(private readonly array $segments)
    {
    }

    public static function identity(): self
    {
        return new self([]);
    }

    /**
     * Build a map from the same `[origStart, origLen, replacementText]`
     * tuples `XphpSourceParser` already accumulates.  Same-length
     * replacements (the `<...>` -> spaces case) contribute nothing.
     *
     * @param list<array{int, int, string}> $replacements
     */
    public static function fromReplacements(array $replacements): self
    {
        $shifts = [];
        foreach ($replacements as [$origStart, $origLen, $replText]) {
            $replLen = strlen($replText);
            if ($replLen === $origLen) {
                continue;
            }
            $shifts[] = [$origStart, $origLen, $replLen];
        }
        if ($shifts === []) {
            return self::identity();
        }

        usort($shifts, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $segments = [];
        $cumDelta = 0;
        foreach ($shifts as [$origStart, $origLen, $replLen]) {
            $strippedStart = $origStart - $cumDelta;
            $strippedEnd = $strippedStart + $replLen;
            $cumDelta += $origLen - $replLen;
            $segments[] = [$strippedStart, $strippedEnd, $origStart, $cumDelta];
        }
        return new self($segments);
    }

    /**
     * Translate a stripped-source byte offset to the original-source byte
     * offset.  Positions falling inside a replacement segment (i.e. between
     * the replacement's stripped-start and stripped-end) snap to the
     * replacement's original-start -- there's no precise mapping for the
     * synthetic bytes the rewrite introduced.
     */
    public function toOriginal(int $strippedPos): int
    {
        $priorDelta = 0;
        foreach ($this->segments as [$strippedStart, $strippedEnd, $originalStart, $deltaAfter]) {
            if ($strippedPos < $strippedStart) {
                break;
            }
            if ($strippedPos < $strippedEnd) {
                return $originalStart;
            }
            $priorDelta = $deltaAfter;
        }
        return $strippedPos + $priorDelta;
    }
}
