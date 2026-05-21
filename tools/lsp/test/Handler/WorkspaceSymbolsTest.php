<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Handler\WorkspaceSymbols;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class WorkspaceSymbolsTest extends TestCase
{
    public function testCollectsClassFqnsAcrossOpenDocuments(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Models.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Models;
        class Plastic {}
        class Metal {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Containers.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Containers;
        class Box<T> {}
        interface Holder<T> {}
        trait HasOne<T> {}
        XPHP));

        $fqns = $this->newSymbols($workspace)->allClassFqns();

        self::assertContains('App\\Models\\Plastic', $fqns);
        self::assertContains('App\\Models\\Metal', $fqns);
        self::assertContains('App\\Containers\\Box', $fqns);
        self::assertContains('App\\Containers\\Holder', $fqns);
        self::assertContains('App\\Containers\\HasOne', $fqns);
    }

    public function testFqnsAreDeduplicatedAcrossDocuments(): void
    {
        // Locks the `$fqns[$fqn] = true` assignment on line 44. Using the
        // FQN as the array KEY (with a sentinel `true` value) is what
        // dedupes. If the value is anything else, dedup still works (PHP
        // collapses duplicate keys), so this mutation may be equivalent —
        // but we exercise the path explicitly.
        $workspace = new PhpactorWorkspace();
        // Same FQN declared in TWO documents (would never happen in a sane
        // workspace, but our collector must dedupe gracefully).
        $workspace->open(new TextDocumentItem('/A.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Same {}
        XPHP));
        $workspace->open(new TextDocumentItem('/B.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Same {}
        XPHP));

        $fqns = $this->newSymbols($workspace)->allClassFqns();
        self::assertSame(
            ['App\\Same'],
            $fqns,
            'duplicate class declarations must collapse to a single entry',
        );
    }

    public function testSkipsDocumentsThatFailToParse(): void
    {
        // Locks the `continue;` on line 41 when the AST is null. Without it,
        // we'd dereference null and crash.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Broken.xphp', 'xphp', 1, "<?php\nfunction broken( {"));
        $workspace->open(new TextDocumentItem('/Clean.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass Plastic {}"));

        $fqns = $this->newSymbols($workspace)->allClassFqns();
        self::assertSame(['App\\Plastic'], $fqns, 'broken doc must be skipped, clean doc still collected');
    }

    public function testAnonymousNamespaceProducesShortNameOnly(): void
    {
        // Locks the `$node->name?->toString() ?? ''` nullsafe call on line 65.
        // An anonymous `namespace { ... }` block has null on Namespace_.name —
        // the nullsafe avoids a fatal, and we record the class with no
        // namespace prefix.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Anon.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace {
            class Loose {}
        }
        XPHP));

        $fqns = $this->newSymbols($workspace)->allClassFqns();
        self::assertContains('Loose', $fqns);
    }

    private function newSymbols(PhpactorWorkspace $workspace): WorkspaceSymbols
    {
        $analyzer = new Analyzer(new XphpSourceParser((new ParserFactory())->createForHostVersion()));
        return new WorkspaceSymbols($workspace, $analyzer);
    }
}
