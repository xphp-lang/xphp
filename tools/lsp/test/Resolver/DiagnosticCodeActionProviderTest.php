<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Resolver;

use Phpactor\LanguageServerProtocol\CodeAction;
use Phpactor\LanguageServerProtocol\CodeActionKind;
use Phpactor\LanguageServerProtocol\Diagnostic;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\DiagnosticCode;
use XPHP\Lsp\Resolver\DiagnosticCodeActionProvider;

final class DiagnosticCodeActionProviderTest extends TestCase
{
    public function testOffersNullFixForNulTypo(): void
    {
        $source = "<?php\n\$x = nul;\n";
        $needle = strpos($source, 'nul');
        $diagnostic = $this->diagnostic($source, $needle, 3, DiagnosticCode::UndefinedName);

        $actions = (new DiagnosticCodeActionProvider())->actionsFor('/x.xphp', 1, $source, [$diagnostic]);

        $titles = array_map(static fn (CodeAction $a): string => $a->title, $actions);
        self::assertContains('Change to "null"', $titles);

        $nullAction = $this->findActionByTitle($actions, 'Change to "null"');
        self::assertSame(CodeActionKind::QUICK_FIX, $nullAction->kind);
        self::assertTrue($nullAction->isPreferred);
        $edit = $nullAction->edit?->documentChanges[0]?->edits[0];
        self::assertSame('null', $edit?->newText);
    }

    public function testOffersFalseFixForFlaseTypo(): void
    {
        $source = "<?php\n\$x = flase;\n";
        $needle = strpos($source, 'flase');
        $diagnostic = $this->diagnostic($source, $needle, 5, DiagnosticCode::UndefinedName);

        $actions = (new DiagnosticCodeActionProvider())->actionsFor('/x.xphp', 1, $source, [$diagnostic]);

        $titles = array_map(static fn (CodeAction $a): string => $a->title, $actions);
        self::assertContains('Change to "false"', $titles);
    }

    public function testOffersAllCandidatesWithinDistanceTwo(): void
    {
        // `trun` is 2 from `true`, 2 from `null` -- both should
        // appear; the lower-distance candidate (which here is tied)
        // gets `isPreferred`.
        $source = "<?php\n\$x = trun;\n";
        $needle = strpos($source, 'trun');
        $diagnostic = $this->diagnostic($source, $needle, 4, DiagnosticCode::UndefinedName);

        $actions = (new DiagnosticCodeActionProvider())->actionsFor('/x.xphp', 1, $source, [$diagnostic]);

        $titles = array_map(static fn (CodeAction $a): string => $a->title, $actions);
        self::assertContains('Change to "true"', $titles);
    }

    public function testSuppressesActionsForUnknownDiagnosticCodes(): void
    {
        // Parse diagnostics are NOT auto-fixable.
        $source = "<?php\n\$x = nul;\n";
        $needle = strpos($source, 'nul');
        $diagnostic = $this->diagnostic($source, $needle, 3, DiagnosticCode::Parse);

        $actions = (new DiagnosticCodeActionProvider())->actionsFor('/x.xphp', 1, $source, [$diagnostic]);

        self::assertSame([], $actions);
    }

    public function testSuppressesWhenNoCandidateIsWithinDistanceTwo(): void
    {
        // `xyzzy` is far from any of `null` / `true` / `false`.
        $source = "<?php\n\$x = xyzzy;\n";
        $needle = strpos($source, 'xyzzy');
        $diagnostic = $this->diagnostic($source, $needle, 5, DiagnosticCode::UndefinedName);

        $actions = (new DiagnosticCodeActionProvider())->actionsFor('/x.xphp', 1, $source, [$diagnostic]);

        self::assertSame([], $actions);
    }

    public function testReplaceEditCarriesDiagnosticRange(): void
    {
        $source = "<?php\n\$x = nul;\n";
        $needle = strpos($source, 'nul');
        $diagnostic = $this->diagnostic($source, $needle, 3, DiagnosticCode::UndefinedName);

        $actions = (new DiagnosticCodeActionProvider())->actionsFor('/x.xphp', 1, $source, [$diagnostic]);

        $action = $this->findActionByTitle($actions, 'Change to "null"');
        $edit = $action->edit?->documentChanges[0]?->edits[0];
        self::assertSame($diagnostic->range, $edit?->range);
    }

    public function testEmptyDiagnosticListReturnsEmpty(): void
    {
        $actions = (new DiagnosticCodeActionProvider())->actionsFor('/x.xphp', 1, "<?php\n", []);
        self::assertSame([], $actions);
    }

    /**
     * @param list<CodeAction> $actions
     */
    private function findActionByTitle(array $actions, string $title): CodeAction
    {
        foreach ($actions as $action) {
            if ($action->title === $title) {
                return $action;
            }
        }
        self::fail("No action with title \"$title\" was found");
    }

    private function diagnostic(string $source, int $byteOffset, int $length, DiagnosticCode $code): Diagnostic
    {
        // Single-line fixture; convert byte offset to line/character
        // by counting `\n`s.
        $line = substr_count(substr($source, 0, $byteOffset), "\n");
        $lineStart = $line === 0 ? 0 : (int) strrpos(substr($source, 0, $byteOffset), "\n") + 1;
        $startChar = $byteOffset - $lineStart;
        return new Diagnostic(
            range: new Range(
                new Position($line, $startChar),
                new Position($line, $startChar + $length),
            ),
            message: 'Undefined constant',
            code: $code->value,
        );
    }
}
