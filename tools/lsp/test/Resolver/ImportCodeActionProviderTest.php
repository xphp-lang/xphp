<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Resolver;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\CodeAction;
use Phpactor\LanguageServerProtocol\CodeActionKind;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Resolver\ImportCodeActionProvider;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class ImportCodeActionProviderTest extends TestCase
{
    public function testImportActionInsertsUseLineAfterNamespace(): void
    {
        $consumer = "<?php\nnamespace App\\Demos;\nfunction make(): void { new User(); }\n";
        $workspace = $this->workspaceWith([
            '/Models/User.xphp' => "<?php\nnamespace App\\Models;\nclass User {}\n",
            '/Demos/Make.xphp' => $consumer,
        ]);
        $provider = $this->newProvider($workspace);

        $offset = strpos($consumer, 'new User()') + 4;
        $actions = $provider->actionsAt('/Demos/Make.xphp', 1, $consumer, $offset);

        self::assertCount(1, $actions);
        self::assertSame('Import App\\Models\\User', $actions[0]->title);
        self::assertSame(CodeActionKind::REFACTOR_REWRITE, $actions[0]->kind);

        $edits = $actions[0]->edit?->documentChanges[0]?->edits;
        self::assertNotNull($edits);
        self::assertCount(1, $edits);
        self::assertSame("use App\\Models\\User;\n", $edits[0]->newText);
        // Insertion should land on a line AFTER `namespace App\Demos;`.
        self::assertGreaterThan(1, $edits[0]->range->start->line);
    }

    public function testImportActionSuppressedWhenAlreadyImported(): void
    {
        $consumer = "<?php\nnamespace App\\Demos;\nuse App\\Models\\User;\nfunction make(): void { new User(); }\n";
        $workspace = $this->workspaceWith([
            '/Models/User.xphp' => "<?php\nnamespace App\\Models;\nclass User {}\n",
            '/Demos/Make.xphp' => $consumer,
        ]);
        $provider = $this->newProvider($workspace);

        $offset = strpos($consumer, 'new User()') + 4;
        $actions = $provider->actionsAt('/Demos/Make.xphp', 1, $consumer, $offset);

        self::assertSame([], $actions);
    }

    public function testImportActionSuppressedWhenSameNamespaceDeclaresClass(): void
    {
        // `App\Demos\User` already exists; bare `User` in `namespace App\Demos`
        // resolves there natively -- no import needed.
        $consumer = "<?php\nnamespace App\\Demos;\nfunction take(User \$u): void {}\n";
        $workspace = $this->workspaceWith([
            '/Demos/User.xphp' => "<?php\nnamespace App\\Demos;\nclass User {}\n",
            '/Demos/Take.xphp' => $consumer,
        ]);
        $provider = $this->newProvider($workspace);

        $offset = strpos($consumer, 'User $u');
        $actions = $provider->actionsAt('/Demos/Take.xphp', 1, $consumer, $offset);

        self::assertSame([], $actions);
    }

    public function testImportActionEmitsOnePerCandidateWhenShortNameIsAmbiguous(): void
    {
        $consumer = "<?php\nnamespace App\\Demos;\nfunction make(): void { new Token(); }\n";
        $workspace = $this->workspaceWith([
            '/Auth/Token.xphp' => "<?php\nnamespace App\\Auth;\nclass Token {}\n",
            '/Crypto/Token.xphp' => "<?php\nnamespace App\\Crypto;\nclass Token {}\n",
            '/Demos/Make.xphp' => $consumer,
        ]);
        $provider = $this->newProvider($workspace);

        $offset = strpos($consumer, 'new Token()') + 4;
        $actions = $provider->actionsAt('/Demos/Make.xphp', 1, $consumer, $offset);

        $titles = array_map(static fn (CodeAction $a): string => $a->title, $actions);
        self::assertContains('Import App\\Auth\\Token', $titles);
        self::assertContains('Import App\\Crypto\\Token', $titles);
    }

    public function testSimplifyActionReplacesFqnAndInsertsUse(): void
    {
        $consumer = "<?php\nnamespace App\\Demos;\nfunction take(\\App\\Models\\User \$u): void {}\n";
        $workspace = $this->workspaceWith([
            '/Models/User.xphp' => "<?php\nnamespace App\\Models;\nclass User {}\n",
            '/Demos/Take.xphp' => $consumer,
        ]);
        $provider = $this->newProvider($workspace);

        $offset = strpos($consumer, '\\App\\Models\\User') + 1;
        $actions = $provider->actionsAt('/Demos/Take.xphp', 1, $consumer, $offset);

        self::assertCount(1, $actions);
        self::assertSame('Simplify App\\Models\\User', $actions[0]->title);
        $edits = $actions[0]->edit?->documentChanges[0]?->edits;
        self::assertCount(2, $edits);
        $newTexts = array_map(static fn ($e) => $e->newText, $edits);
        self::assertContains('User', $newTexts);
        self::assertContains("use App\\Models\\User;\n", $newTexts);
    }

    public function testSimplifyActionSuppressedWhenShortNameBoundToDifferentFqn(): void
    {
        // `use Other\User` is already in scope; shortening `\App\Models\User`
        // to `User` would silently swap which class is referenced.
        $consumer = "<?php\nnamespace App\\Demos;\nuse Other\\User;\nfunction take(\\App\\Models\\User \$u): void {}\n";
        $workspace = $this->workspaceWith([
            '/Models/User.xphp' => "<?php\nnamespace App\\Models;\nclass User {}\n",
            '/Other/User.xphp' => "<?php\nnamespace Other;\nclass User {}\n",
            '/Demos/Take.xphp' => $consumer,
        ]);
        $provider = $this->newProvider($workspace);

        $offset = strpos($consumer, '\\App\\Models\\User \$u') + 1;
        $actions = $provider->actionsAt('/Demos/Take.xphp', 1, $consumer, $offset);

        self::assertSame([], $actions);
    }

    public function testReturnsEmptyWhenCursorIsNotOnANameNode(): void
    {
        $consumer = "<?php\nnamespace App\\Demos;\n\$x = 1;\n";
        $workspace = $this->workspaceWith(['/Demos/Take.xphp' => $consumer]);
        $provider = $this->newProvider($workspace);

        $offset = strpos($consumer, '$x');
        $actions = $provider->actionsAt('/Demos/Take.xphp', 1, $consumer, $offset);

        self::assertSame([], $actions);
    }

    public function testReturnsEmptyForReservedScalarLikeName(): void
    {
        // `string`, `int`, etc. round-trip through nikic as Name nodes,
        // but ClassFqnPredicate filters them out before we even ask
        // FqnIndex.
        $consumer = "<?php\nnamespace App\\Demos;\nfunction give(): string { return 'ok'; }\n";
        $workspace = $this->workspaceWith(['/Demos/Give.xphp' => $consumer]);
        $provider = $this->newProvider($workspace);

        $offset = strpos($consumer, ': string') + 2;
        $actions = $provider->actionsAt('/Demos/Give.xphp', 1, $consumer, $offset);

        self::assertSame([], $actions);
    }

    public function testInsertionPositionFallsBackToPostPhpTagWhenNoNamespace(): void
    {
        $consumer = "<?php\nfunction make(): void { new User(); }\n";
        $workspace = $this->workspaceWith([
            '/Models/User.xphp' => "<?php\nnamespace App\\Models;\nclass User {}\n",
            '/Make.xphp' => $consumer,
        ]);
        $provider = $this->newProvider($workspace);

        $offset = strpos($consumer, 'new User()') + 4;
        $actions = $provider->actionsAt('/Make.xphp', 1, $consumer, $offset);

        self::assertNotEmpty($actions);
        $edits = $actions[0]->edit?->documentChanges[0]?->edits;
        // Insert on line 1 (after `<?php`) when there's no namespace.
        self::assertSame(1, $edits[0]->range->start->line);
    }

    public function testInsertionPositionAppendsAfterExistingUseStatements(): void
    {
        $consumer = "<?php\nnamespace App\\Demos;\nuse Some\\Other;\nfunction make(): void { new User(); }\n";
        $workspace = $this->workspaceWith([
            '/Models/User.xphp' => "<?php\nnamespace App\\Models;\nclass User {}\n",
            '/Demos/Make.xphp' => $consumer,
        ]);
        $provider = $this->newProvider($workspace);

        $offset = strpos($consumer, 'new User()') + 4;
        $actions = $provider->actionsAt('/Demos/Make.xphp', 1, $consumer, $offset);

        self::assertNotEmpty($actions);
        $edits = $actions[0]->edit?->documentChanges[0]?->edits;
        // `use Some\Other;` is on line 2; insertion should be on line 3.
        self::assertSame(3, $edits[0]->range->start->line);
    }

    /**
     * @param array<string, string> $files  URI => source
     */
    private function workspaceWith(array $files): PhpactorWorkspace
    {
        $workspace = new PhpactorWorkspace();
        foreach ($files as $uri => $source) {
            $workspace->open(new TextDocumentItem($uri, 'xphp', 1, $source));
        }
        return $workspace;
    }

    private function newProvider(PhpactorWorkspace $workspace): ImportCodeActionProvider
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $root = sys_get_temp_dir() . '/xphp-codeaction-provider-' . bin2hex(random_bytes(4));
        @mkdir($root, 0o755, true);
        $fqnIndex = new FqnIndex($workspace, $cache, $parser, $root);
        return new ImportCodeActionProvider($fqnIndex, $cache);
    }
}
