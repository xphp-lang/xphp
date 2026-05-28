<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Reflection;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use Phpactor\WorseReflection\Core\Exception\SourceNotFound;
use Phpactor\WorseReflection\Core\Name;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Reflection\WorkspaceSourceLocator;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class WorkspaceSourceLocatorTest extends TestCase
{
    public function testLocatesClassDeclaredInOpenXphpDocument(): void
    {
        $workspace = new PhpactorWorkspace();
        $source = <<<'XPHP'
        <?php
        namespace App\Models;
        class User { public function __construct(public string $name) {} }
        XPHP;
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, $source));

        $document = $this->newLocator($workspace)->locate(Name::fromString('App\\Models\\User'));

        self::assertStringEndsWith('/User.xphp', (string) $document->uri());
        self::assertStringContainsString('class User', (string) $document);
    }

    public function testLocatesFunctionDeclaredInOpenXphpDocument(): void
    {
        $workspace = new PhpactorWorkspace();
        $source = <<<'XPHP'
        <?php
        namespace App;
        function greet(string $name): string { return "hi {$name}"; }
        XPHP;
        $workspace->open(new TextDocumentItem('/funcs.xphp', 'xphp', 1, $source));

        $document = $this->newLocator($workspace)->locate(Name::fromString('App\\greet'));

        self::assertStringEndsWith('/funcs.xphp', (string) $document->uri());
    }

    public function testStripsGenericClausesFromTheReturnedSource(): void
    {
        // The whole point of this locator: worse-reflection sees PHP-shaped
        // source even though the workspace doc contains xphp <T> clauses.
        // Equal-length whitespace strip means byte offsets still match the
        // original .xphp file -- editor navigation lands on the right token.
        $workspace = new PhpactorWorkspace();
        $source = <<<'XPHP'
        <?php
        namespace App\Containers;
        class Box<T> { public T $item; }
        XPHP;
        $workspace->open(new TextDocumentItem('/Box.xphp', 'xphp', 1, $source));

        $document = $this->newLocator($workspace)->locate(Name::fromString('App\\Containers\\Box'));
        $text = (string) $document;

        // The `<T>` clause must NOT appear in the returned text -- it's been
        // replaced by whitespace.  But the `class Box` token AND the `T $item`
        // member still appear at their original byte offsets.
        self::assertStringNotContainsString('<T>', $text);
        self::assertSame(strpos($source, 'class Box'), strpos($text, 'class Box'));
        self::assertSame(strpos($source, '$item'), strpos($text, '$item'));
    }

    public function testThrowsSourceNotFoundForUnknownFqn(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/A.xphp', 'xphp', 1, "<?php\nnamespace App; class A {}"));

        $this->expectException(SourceNotFound::class);
        $this->newLocator($workspace)->locate(Name::fromString('App\\Unknown'));
    }

    public function testThrowsSourceNotFoundForEmptyName(): void
    {
        $this->expectException(SourceNotFound::class);
        $this->newLocator(new PhpactorWorkspace())->locate(Name::fromString(''));
    }

    public function testSkipsDocumentsThatFailToParse(): void
    {
        // Locks the `if ($result->ast === null) continue` guard. With it,
        // we walk past the broken file and find the good one. Without it,
        // we'd hit a null AST in the traverser and crash.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Broken.xphp', 'xphp', 1, "<?php\nfunction broken( {"));
        $workspace->open(new TextDocumentItem('/Clean.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass Plastic {}"));

        $document = $this->newLocator($workspace)->locate(Name::fromString('App\\Plastic'));
        self::assertStringEndsWith('/Clean.xphp', (string) $document->uri());
    }

    public function testReturnsClassDeclaredBeforeATrailingParseError(): void
    {
        // Prod-driven: cursor on `$x->|` keeps the strict parser from
        // finishing the file but the in-memory locator still has to
        // serve a fresh `class A` / `class B` reflection so completion
        // fan-out doesn't fall through to (stale) on-disk content.
        // The Analyzer's tolerant-parse fallback is what makes this
        // possible -- without it `result->ast === null` skips the doc.
        $source = <<<'XPHP'
        <?php
        namespace App\Demos;

        class A { public function foo(): string { return 'a'; } }
        class B {
            public function foo(): string { return 'b'; }
            public function run(): void { }
        }

        function pick(): A|B { return new A(); }

        $x = pick();
        $x->
        XPHP;
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Probe.xphp', 'xphp', 1, $source));

        $documentA = $this->newLocator($workspace)->locate(Name::fromString('App\\Demos\\A'));
        $documentB = $this->newLocator($workspace)->locate(Name::fromString('App\\Demos\\B'));

        self::assertStringEndsWith('/Probe.xphp', (string) $documentA->uri());
        self::assertStringEndsWith('/Probe.xphp', (string) $documentB->uri());
        // Source must include B's newly-added `run` method, proving the
        // locator served the in-memory text rather than a stale snapshot.
        self::assertStringContainsString('public function run', (string) $documentB);
    }

    public function testHandlesLeadingBackslashOnFqn(): void
    {
        // Worse-reflection sometimes hands us names with a leading backslash
        // (fully-qualified marker); strip before comparing against the
        // namespace-qualified form we derive from the AST.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Plain.xphp', 'xphp', 1, "<?php\nclass Plain {}"));

        $document = $this->newLocator($workspace)->locate(Name::fromString('\\Plain'));
        self::assertStringEndsWith('/Plain.xphp', (string) $document->uri());
    }

    private function newLocator(PhpactorWorkspace $workspace): WorkspaceSourceLocator
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        return new WorkspaceSourceLocator($workspace, $cache, $parser);
    }
}
