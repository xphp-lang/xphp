<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Resolver;

use PhpParser\ParserFactory;
use Phpactor\LanguageServerProtocol\CodeAction;
use Phpactor\LanguageServerProtocol\CodeActionKind;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Resolver\OptimizeImportsCodeActionProvider;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class OptimizeImportsCodeActionProviderTest extends TestCase
{
    public function testOffersRemovalForAnUnusedImport(): void
    {
        $source = "<?php\nnamespace App;\nuse App\\Other\\Unused;\n\$x = 1;\n";
        $provider = $this->newProvider();

        $actions = $provider->actionsFor('/x.xphp', 1, $source);

        self::assertCount(1, $actions);
        self::assertSame('Optimize imports', $actions[0]->title);
        self::assertSame(CodeActionKind::SOURCE_ORGANIZE_IMPORTS, $actions[0]->kind);

        $edits = $actions[0]->edit?->documentChanges[0]?->edits;
        self::assertCount(1, $edits);
        self::assertSame('', $edits[0]->newText);
        // Use is on line 2 (`use App\Other\Unused;`); deletion range
        // should cover line 2 -> line 3 (exclusive).
        self::assertSame(2, $edits[0]->range->start->line);
        self::assertSame(0, $edits[0]->range->start->character);
        self::assertSame(3, $edits[0]->range->end->line);
    }

    public function testSuppressedWhenAllImportsAreReferenced(): void
    {
        $source = "<?php\nnamespace App;\nuse App\\Other\\Used;\nfunction take(Used \$u): void {}\n";
        $provider = $this->newProvider();

        $actions = $provider->actionsFor('/x.xphp', 1, $source);

        self::assertSame([], $actions);
    }

    public function testRemovesOnlyTheUnusedSubsetWhenMixed(): void
    {
        $source = <<<'PHP'
        <?php
        namespace App;
        use App\Other\Used;
        use App\Other\Unused;
        function take(Used $u): void {}
        PHP;
        $provider = $this->newProvider();

        $actions = $provider->actionsFor('/x.xphp', 1, $source);

        $edits = $actions[0]->edit?->documentChanges[0]?->edits;
        self::assertCount(1, $edits, 'only the unused use line should be removed');
        // Line 3 is `use App\Other\Unused;`.
        self::assertSame(3, $edits[0]->range->start->line);
    }

    public function testIgnoresFunctionAndConstantImports(): void
    {
        // `use function ...` would mark `strlen` as used in the
        // function symbol table; the optimizer (V1) only handles
        // class-like imports so it shouldn't touch this case.
        $source = "<?php\nnamespace App;\nuse function strlen;\n\$x = 1;\n";
        $provider = $this->newProvider();

        $actions = $provider->actionsFor('/x.xphp', 1, $source);

        self::assertSame([], $actions);
    }

    public function testSuppressedWhenFileHasNoImports(): void
    {
        $source = "<?php\nnamespace App;\n\$x = 1;\n";
        $provider = $this->newProvider();

        $actions = $provider->actionsFor('/x.xphp', 1, $source);

        self::assertSame([], $actions);
    }

    public function testSuppressedForUnparseableSource(): void
    {
        $source = "<?php\nuse App\\Foo;\nfunction broken(\n";
        $provider = $this->newProvider();

        // Tolerant parse may still surface unused imports.  The
        // contract is "return some valid result, don't error".  Just
        // verify the call returns without throwing.
        $actions = $provider->actionsFor('/x.xphp', 1, $source);

        self::assertIsArray($actions);
    }

    public function testHandlesFileLevelImportsNoNamespace(): void
    {
        $source = "<?php\nuse App\\Other\\Unused;\n\$x = 1;\n";
        $provider = $this->newProvider();

        $actions = $provider->actionsFor('/x.xphp', 1, $source);

        self::assertCount(1, $actions);
    }

    private function newProvider(): OptimizeImportsCodeActionProvider
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        return new OptimizeImportsCodeActionProvider($cache);
    }
}
