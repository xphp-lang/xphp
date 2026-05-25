<?php

declare(strict_types=1);

namespace XPHP\Lsp;

use OutOfBoundsException;

/**
 * Byte-offset <-> LSP {line, character} conversion for a single in-memory document.
 *
 * The xphp pipeline (nikic/php-parser + the custom scanner) hands us byte offsets
 * and 1-based line numbers. LSP wants 0-based lines and 0-based UTF-16 code-unit
 * columns within each line. This class owns the conversion for one source string.
 *
 * Character index = UTF-16 code units, per the LSP spec.  Every Unicode
 * scalar in the Basic Multilingual Plane (UTF-8 1/2/3-byte sequences)
 * counts as one code unit; supplementary-plane scalars (UTF-8 4-byte,
 * codepoint > U+FFFF -- emoji, less-common CJK extensions) count as two
 * because UTF-16 represents them as surrogate pairs.  For ASCII source
 * this collapses to byte counts; for documents with a `🚀` in a comment
 * the columns past that character still match what the editor reports.
 */
final readonly class PositionMap
{
    /** @var list<int> Byte offsets of the start of each line (0-indexed by line number). */
    private array $lineOffsets;

    public function __construct(private string $source)
    {
        $offsets = [0];
        $length = strlen($source);
        for ($i = 0; $i < $length; $i++) {
            if ($source[$i] === "\n") {
                $offsets[] = $i + 1;
            }
        }
        $this->lineOffsets = $offsets;
    }

    /**
     * Convert a 1-based line number (as nikic reports) to a 0-based LSP line number.
     */
    public static function lspLineFromNikic(int $nikicLine): int
    {
        return max(0, $nikicLine - 1);
    }

    /**
     * Resolve an LSP {line, character} (both 0-based) to a byte offset for this
     * document. Inverse of offsetToPosition(). Used by handlers that receive a
     * cursor position from the client and need to walk the AST for the node
     * sitting under it.
     *
     * If the line is past EOF, returns the document length. If the character is
     * past EOL, returns the byte offset of the line's terminator (or document
     * length for the last line). These clamps mirror typical editor behavior so
     * a click just-past-the-end-of-line still resolves to the last token.
     */
    public function positionToOffset(int $line, int $character): int
    {
        $length = strlen($this->source);
        if ($line >= count($this->lineOffsets)) {
            return $length;
        }
        $lineStart = $this->lineOffsets[$line];
        // lineEnd is the byte just past the line's last visible character: the
        // terminator (\n) for any non-last line, or document length for the
        // last line. Computed branchlessly to keep mutation testing happy
        // (the ternary that used to live here had a dead-store on the
        // fallback constant — `?? $length + 1` — which surfaced as
        // un-killable equivalent mutations).
        $lineEnd = isset($this->lineOffsets[$line + 1])
            ? $this->lineOffsets[$line + 1] - 1
            : $length;
        $lineText = substr($this->source, $lineStart, $lineEnd - $lineStart);
        // Walk UTF-16 code units until we've consumed `$character` of them.
        // BMP chars (UTF-8 1/2/3-byte) count as 1 unit each; supplementary-
        // plane chars (UTF-8 4-byte) count as 2.  Stop short if the next
        // char would land us inside a surrogate pair -- editors usually
        // clamp to the codepoint boundary, mirroring that here.
        $consumed = 0;
        $bytes = 0;
        $lineBytes = strlen($lineText);
        while ($consumed < $character && $bytes < $lineBytes) {
            $byteLen = self::utf8CharLength($lineText[$bytes]);
            $units = $byteLen === 4 ? 2 : 1;
            if ($consumed + $units > $character) {
                break;
            }
            $bytes += $byteLen;
            $consumed += $units;
        }
        return $lineStart + $bytes;
    }

    private static function utf8CharLength(string $byte): int
    {
        $code = ord($byte);
        if ($code < 0x80) {
            return 1;
        }
        if (($code & 0xE0) === 0xC0) {
            return 2;
        }
        if (($code & 0xF0) === 0xE0) {
            return 3;
        }
        if (($code & 0xF8) === 0xF0) {
            return 4;
        }
        // Invalid leading byte — treat as a single byte rather than throwing,
        // so a malformed file doesn't blow up the hover handler.
        return 1;
    }

    /**
     * Resolve a byte offset to {line, character} (both 0-based) for this document.
     *
     * @return array{0: int, 1: int} [line, character]
     */
    public function offsetToPosition(int $byteOffset): array
    {
        if ($byteOffset < 0) {
            throw new OutOfBoundsException("Negative byte offset: {$byteOffset}");
        }
        $line = self::binarySearchLine($this->lineOffsets, $byteOffset);
        $lineStart = $this->lineOffsets[$line];
        $character = self::toLspCharacter(substr($this->source, $lineStart, $byteOffset - $lineStart));
        return [$line, $character];
    }

    /**
     * Resolve a [startByte, endByte) byte-range into an LSP range tuple
     * `[startLine, startCharacter, endLine, endCharacter]`. Convenience for
     * the two `offsetToPosition` calls handlers need when they want to pin a
     * diagnostic to an actual AST node's span rather than the whole line.
     *
     * Caller-supplied `$endByte` is treated as half-open (exclusive). If the
     * upstream API returns an inclusive end offset (e.g. nikic's
     * `Node::getEndFilePos()`), pass `$endFilePos + 1`.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    public function rangeFromOffsets(int $startByte, int $endByte): array
    {
        [$sl, $sc] = $this->offsetToPosition($startByte);
        [$el, $ec] = $this->offsetToPosition($endByte);
        return [$sl, $sc, $el, $ec];
    }

    /**
     * Resolve a 1-based nikic line number to the {line, character} range covering that whole line.
     *
     * Useful when a Registry RuntimeException carries only line information and we need to
     * produce a Diagnostic range — underlining the full line is the safe default.
     *
     * @return array{0: int, 1: int, 2: int, 3: int} [startLine, startChar, endLine, endChar]
     */
    public function fullLineRangeFromNikic(int $nikicLine): array
    {
        $line = self::lspLineFromNikic($nikicLine);
        if ($line >= count($this->lineOffsets)) {
            return [$line, 0, $line, 0];
        }
        $lineStart = $this->lineOffsets[$line];
        $nextLineStart = $this->lineOffsets[$line + 1] ?? strlen($this->source) + 1;
        $lineText = substr($this->source, $lineStart, $nextLineStart - $lineStart - 1);
        return [$line, 0, $line, self::toLspCharacter($lineText)];
    }

    /**
     * UTF-16 code-unit count -- the LSP wire encoding for column offsets.
     * BMP characters (UTF-8 1/2/3-byte) contribute 1 unit each;
     * supplementary-plane characters (UTF-8 4-byte) contribute 2 because
     * UTF-16 represents them as a surrogate pair.
     */
    private static function toLspCharacter(string $text): int
    {
        $units = 0;
        $len = strlen($text);
        for ($i = 0; $i < $len;) {
            $byteLen = self::utf8CharLength($text[$i]);
            $units += $byteLen === 4 ? 2 : 1;
            $i += $byteLen;
        }
        return $units;
    }

    /**
     * @param list<int> $offsets
     */
    private static function binarySearchLine(array $offsets, int $target): int
    {
        $low = 0;
        $high = count($offsets) - 1;
        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            if ($offsets[$mid] <= $target) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }
        return $low;
    }
}
