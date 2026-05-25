<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\WorkspaceSymbols;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class WorkspaceSymbolsTest extends TestCase
{
    public function testCollectsFunctionFqnsAcrossOpenDocuments(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/funcs.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        function greet(string $n): string { return $n; }
        function farewell(): void {}
        XPHP));
        $workspace->open(new TextDocumentItem('/util.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        function global_fn() {}
        XPHP));

        $fqns = $this->newSymbols($workspace)->allFunctionFqns();

        self::assertContains('App\\greet', $fqns);
        self::assertContains('App\\farewell', $fqns);
        self::assertContains('global_fn', $fqns);
    }

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

    public function testFindClassByNameLocatesNamespaceQualifiedClass(): void
    {
        $userSource = "<?php\nnamespace App\\Models;\nclass User {}";
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, $userSource));

        $location = $this->newSymbols($workspace)->findClassByName('User');

        self::assertNotNull($location);
        self::assertSame('/User.xphp', $location->uri);
        // Range targets the `User` identifier token, not the whole class
        // body.  In `class User {}`, `User` starts at column 6 (`strlen('class ')`).
        self::assertSame(2, $location->range->start->line);
        self::assertSame(strlen('class '), $location->range->start->character);
    }

    public function testFindClassByNameReturnsNullForUnknown(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Other.xphp', 'xphp', 1, "<?php\nclass Other {}"));

        self::assertNull($this->newSymbols($workspace)->findClassByName('User'));
    }

    public function testFindClassByNameReturnsNullForEmptyName(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Any.xphp', 'xphp', 1, "<?php\nclass Any {}"));

        self::assertNull($this->newSymbols($workspace)->findClassByName(''));
    }

    public function testFindClassByNameMatchesShortNameAcrossDocuments(): void
    {
        // Two documents declaring different classes; the lookup walks
        // documents in workspace insertion order and returns the first hit.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Unrelated.xphp', 'xphp', 1, "<?php\nclass Helper {}"));
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass User {}"));

        $location = $this->newSymbols($workspace)->findClassByName('User');

        self::assertNotNull($location);
        self::assertSame('/User.xphp', $location->uri);
    }

    public function testFindClassByNameTieBreakPrefersNonFixtureCandidate(): void
    {
        // Phase 3 polish: when the same short name appears in BOTH a
        // fixture path and a canonical path, the canonical wins.
        // Without the tie-break, the first document iterated would
        // shadow the real source -- breaking GTD into actual code.
        $workspace = new PhpactorWorkspace();
        // Fixture opens first -- without the tie-break, this would win.
        $workspace->open(new TextDocumentItem('/tests/Fixtures/User.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass User {}"));
        $workspace->open(new TextDocumentItem('/src/Models/User.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass User {}"));

        $location = $this->newSymbols($workspace)->findClassByName('User');

        self::assertNotNull($location);
        self::assertSame('/src/Models/User.xphp', $location->uri, 'canonical src/ path must outrank tests/Fixtures');
    }

    public function testFindClassByNameTieBreakPrefersNonVendorCandidate(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/vendor/acme/lib/User.xphp', 'xphp', 1, "<?php\nnamespace Acme;\nclass User {}"));
        $workspace->open(new TextDocumentItem('/src/Models/User.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass User {}"));

        $location = $this->newSymbols($workspace)->findClassByName('User');

        self::assertNotNull($location);
        self::assertSame('/src/Models/User.xphp', $location->uri, 'app code must outrank vendor copy');
    }

    private function newSymbols(PhpactorWorkspace $workspace): WorkspaceSymbols
    {
        $cache = new ParsedDocumentCache(
            new Analyzer(new XphpSourceParser((new ParserFactory())->createForHostVersion())),
        );
        return new WorkspaceSymbols($workspace, $cache);
    }
}
