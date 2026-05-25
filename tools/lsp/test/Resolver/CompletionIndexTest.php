<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Resolver;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\WorkspaceSymbols;
use XPHP\Lsp\Resolver\CompletionIndex;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class CompletionIndexTest extends TestCase
{
    private string $stubsDir;

    protected function setUp(): void
    {
        $this->stubsDir = sys_get_temp_dir() . '/xphp-ci-' . bin2hex(random_bytes(6));
        mkdir($this->stubsDir, 0o755, true);
        file_put_contents(
            $this->stubsDir . '/stubs.php',
            "<?php\nclass StubClass {}\nfunction stub_func() {}\n",
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->stubsDir)) {
            foreach (scandir($this->stubsDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                unlink($this->stubsDir . '/' . $entry);
            }
            rmdir($this->stubsDir);
        }
    }

    public function testMergesWorkspaceAndStubClasses(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Models;
        class User {}
        class Plastic {}
        XPHP));

        $index = $this->index($workspace);
        $classes = $index->classFqns();

        self::assertContains('App\\Models\\User', $classes);
        self::assertContains('App\\Models\\Plastic', $classes);
        self::assertContains('StubClass', $classes);
    }

    public function testMergesWorkspaceAndStubFunctions(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/funcs.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        function greet(string $n): string { return $n; }
        XPHP));

        $index = $this->index($workspace);
        $functions = $index->functionFqns();

        self::assertContains('App\\greet', $functions);
        self::assertContains('stub_func', $functions);
    }

    public function testStubsAbsenceLeavesWorkspaceOnlyResults(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/A.xphp', 'xphp', 1, "<?php\nclass A {}\n"));

        $cache = new ParsedDocumentCache(new Analyzer(new XphpSourceParser((new ParserFactory())->createForHostVersion())));
        $symbols = new WorkspaceSymbols($workspace, $cache);
        $index = new CompletionIndex($symbols, '/path/that/does/not/exist');

        $classes = $index->classFqns();
        self::assertContains('A', $classes);
        self::assertNotContains('StubClass', $classes);
    }

    private function index(PhpactorWorkspace $workspace): CompletionIndex
    {
        $cache = new ParsedDocumentCache(new Analyzer(new XphpSourceParser((new ParserFactory())->createForHostVersion())));
        $symbols = new WorkspaceSymbols($workspace, $cache);
        return new CompletionIndex($symbols, $this->stubsDir);
    }
}
