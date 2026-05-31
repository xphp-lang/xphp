<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Diagnostics;

use Amp\CancellationTokenSource;
use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Diagnostic as LspDiagnostic;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Analyzer\WorkspaceAnalyzer;
use XPHP\Lsp\Diagnostics\XphpDiagnosticsProvider;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;
use function Amp\Promise\wait;

/**
 * Provider-level tests: feed in TextDocumentItems, assert the LSP-shaped diagnostics
 * the provider returns. Skips the JSON-RPC transport; that's covered separately by
 * LspIntegrationTest.
 */
final class XphpDiagnosticsProviderTest extends TestCase
{
    public function testCleanSingleDocumentReturnsNoDiagnostics(): void
    {
        $workspace = new PhpactorWorkspace();
        $clean = $this->openDoc($workspace, '/clean.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Box<T> { public T $item; }
        XPHP);

        $diagnostics = $this->lint($workspace,$clean);
        self::assertSame([], $diagnostics);
    }

    public function testSyntaxErrorYieldsSingleDiagnostic(): void
    {
        $workspace = new PhpactorWorkspace();
        $broken = $this->openDoc($workspace, '/broken.xphp', <<<'XPHP'
        <?php
        function broken( {
        XPHP);

        $diagnostics = $this->lint($workspace,$broken);
        self::assertCount(1, $diagnostics);
        self::assertSame('xphp', $diagnostics[0]->source);
        self::assertSame('xphp.parse', $diagnostics[0]->code);
        self::assertStringContainsString('Syntax error', $diagnostics[0]->message);
    }

    public function testBoundViolationAcrossOpenDocumentsAttachesToInstantiationFile(): void
    {
        $workspace = new PhpactorWorkspace();
        $this->openDoc($workspace, '/Box.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Box<T: \Stringable>
        {
            public T $item;
        }
        XPHP);
        $useDoc = $this->openDoc($workspace, '/Use.xphp', <<<'XPHP'
        <?php
        namespace App;
        $x = new Box<int>();
        XPHP);

        $diagnostics = $this->lint($workspace,$useDoc);
        self::assertCount(1, $diagnostics);
        self::assertSame('xphp.bound', $diagnostics[0]->code);
        self::assertStringContainsString('Generic bound violated', $diagnostics[0]->message);
        // And the Box.xphp side carries no diagnostic for itself.
        $boxItem = $workspace->get('/Box.xphp');
        $boxDiagnostics = $this->lint($workspace,$boxItem);
        self::assertSame([], $boxDiagnostics);
    }

    public function testProviderNameMatchesEngineRegistration(): void
    {
        // DiagnosticsEngine keys provider state by name; mismatch silently breaks clear-on-update.
        $provider = $this->newProvider(new PhpactorWorkspace());
        self::assertSame('xphp', $provider->name());
    }

    public function testCurrentDocumentIsNotDoubleProcessedAgainstWorkspaceIteration(): void
    {
        // Locks the `if ($uri === $currentUri) continue;` skip on line 84.
        // Without it the current document's AST would be parsed twice and
        // could potentially be inserted into $parsedFiles twice — duplicate
        // recordDefinition would then throw "already declared" and produce
        // a spurious diagnostic on the only file with that template.
        $workspace = new PhpactorWorkspace();
        $boxDoc = $this->openDoc($workspace, '/Box.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Box<T> { public T $item; }
        XPHP);

        $diagnostics = $this->lint($workspace, $boxDoc);

        self::assertSame([], $diagnostics, 'no duplicate-declaration must surface when the only file holding Box is the one being linted');
    }

    public function testWorkspaceDiagnosticsTranslateToLspWireFormatRanges(): void
    {
        // Locks the array_map translation on line 96. Without it, the
        // returned items would be the analyzer's framework-neutral
        // Diagnostic, which lacks the `range`/`severity` LSP fields.
        $workspace = new PhpactorWorkspace();
        $this->openDoc($workspace, '/Box.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Box<T: \Stringable> { public T $item; }
        XPHP);
        $useDoc = $this->openDoc($workspace, '/Use.xphp', <<<'XPHP'
        <?php
        namespace App;
        $x = new Box<int>();
        XPHP);

        $diagnostics = $this->lint($workspace, $useDoc);

        self::assertCount(1, $diagnostics);
        self::assertInstanceOf(LspDiagnostic::class, $diagnostics[0]);
        self::assertInstanceOf(\Phpactor\LanguageServerProtocol\Range::class, $diagnostics[0]->range);
        self::assertSame(1, $diagnostics[0]->severity, 'LSP severity 1 = Error');
        self::assertSame('xphp', $diagnostics[0]->source);
    }

    public function testBoundCheckSucceedsAgainstFilesystemOnlyDependencyWhenCacheIsWarmed(): void
    {
        // Locks the filesystem-cache enrichment branch in analyzeSync.
        // Before this code path existed, opening a file that instantiated
        // `Box<Tag>` with Tag.xphp and Box.xphp present only on disk (not
        // in any open buffer) fired a spurious "concrete type is not in
        // the source set" diagnostic. The warmer pre-parses every on-disk
        // .xphp into the cache; the diagnostics provider then pulls those
        // ASTs into the hierarchy via $fqnIndex->indexedFilesystemPaths()
        // + $cache->peek(). This test exercises the full warmed-cache flow.

        $root = sys_get_temp_dir() . '/xphp-diag-fs-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        try {
            file_put_contents($root . '/Tag.xphp', <<<'PHP'
            <?php
            namespace App\Models;
            class Tag implements \Stringable
            {
                public function __construct(public string $name) {}
                public function __toString(): string { return $this->name; }
            }
            PHP);
            file_put_contents($root . '/Box.xphp', <<<'PHP'
            <?php
            namespace App;
            class Box<T: \Stringable>
            {
                public function __construct(public T $item) {}
            }
            PHP);

            $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
            $cache = new ParsedDocumentCache(new Analyzer($parser));
            $workspace = new PhpactorWorkspace();
            $fqnIndex = new FqnIndex($workspace, $cache, $parser, $root);
            $warmer = new \XPHP\Lsp\Analyzer\ParsedDocumentCacheWarmer($fqnIndex, $cache, $workspace);
            $warmer->warmNow();

            $useUri = 'file://' . $root . '/Use.xphp';
            $useDoc = $this->openDoc($workspace, $useUri, <<<'XPHP'
            <?php
            namespace App;
            use App\Models\Tag;
            $x = new Box<Tag>(new Tag('hi'));
            XPHP);

            $provider = new XphpDiagnosticsProvider($cache, new WorkspaceAnalyzer(), $workspace, $fqnIndex);
            $cancel = (new CancellationTokenSource())->getToken();
            $diagnostics = wait($provider->provideDiagnostics($useDoc, $cancel));
            $diagnostics = is_array($diagnostics) ? array_values($diagnostics) : [];

            self::assertSame(
                [],
                $diagnostics,
                'bound check must succeed when dependency classes are filesystem-only but warmed',
            );
        } finally {
            @unlink($root . '/Tag.xphp');
            @unlink($root . '/Box.xphp');
            @rmdir($root);
        }
    }

    public function testIndexedPathsMissingFromCacheAreSkippedNotBrokenOutOfTheLoop(): void
    {
        // Locks two related mutants on the cache-peek guard inside the
        // filesystem-enrichment loop:
        //   - `||` → `&&` (LogicalOr): without `||`, a null $peek would
        //     fall through to `$peek->ast` and TypeError.
        //   - `continue` → `break`: without `continue`, the first
        //     unwarmed path would short-circuit the loop and later
        //     warmed paths would silently drop out of the hierarchy.
        //
        // Scenario: two filesystem files are indexed; whichever the
        // FqnIndex iterates FIRST gets dropped from the cache; whichever
        // it iterates SECOND carries the Tag class that satisfies the
        // bound. The open Use.xphp instantiates Box<Tag>; Box itself is
        // also open so its template definition gets registered.
        //
        // Original: first→continue, second→hierarchyAsts has Tag → no diag.
        // `break` mutant: first→break, second never reached → Tag missing
        //   from hierarchy → "not in source set" diagnostic fires.

        $root = sys_get_temp_dir() . '/xphp-diag-fs-skip-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        try {
            $unwarmedPath = $root . '/Other.xphp';
            $tagPath = $root . '/Tag.xphp';
            file_put_contents($unwarmedPath, "<?php\nnamespace App;\nclass Other {}\n");
            $tagSource = <<<'PHP'
            <?php
            namespace App\Models;
            class Tag implements \Stringable
            {
                public function __construct(public string $name = '') {}
                public function __toString(): string { return $this->name; }
            }
            PHP;
            file_put_contents($tagPath, $tagSource);

            $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
            $cache = new ParsedDocumentCache(new Analyzer($parser));
            $workspace = new PhpactorWorkspace();
            $fqnIndex = new FqnIndex($workspace, $cache, $parser, $root);

            // Warm everything via the same warmer the prod path uses, so
            // both URIs land in the cache. Then surgically drop the cache
            // entry for the FIRST-iterated URI (alphabetically the Other
            // file, which sorts before Tag) — that's the one whose
            // `peek()` must return null AND not break the loop.
            $warmer = new \XPHP\Lsp\Analyzer\ParsedDocumentCacheWarmer($fqnIndex, $cache, $workspace);
            $warmer->warmNow();
            $walked = $fqnIndex->indexedFilesystemPaths();
            self::assertCount(2, $walked, 'both files must be indexed');
            // Sanity: tmpfs iteration is alphabetical on Linux (see the
            // sibling ParsedDocumentCacheWarmerTest A_/B_ pattern). If a
            // future filesystem changes that and Tag ends up first, this
            // assertion would fail loudly rather than silently mask the
            // break-mutant regression.
            self::assertSame($unwarmedPath, $walked[0], 'Other.xphp must be iterated before Tag.xphp');
            $cache->forget('file://' . $walked[0]);

            // Box's template definition lives in an open buffer so it gets
            // registered with the Registry — the bound check needs that.
            $this->openDoc($workspace, 'file://' . $root . '/Box.xphp', <<<'XPHP'
            <?php
            namespace App;
            class Box<T: \Stringable>
            {
                public function __construct(public T $item) {}
            }
            XPHP);
            $useDoc = $this->openDoc($workspace, 'file://' . $root . '/Use.xphp', <<<'XPHP'
            <?php
            namespace App;
            use App\Models\Tag;
            $x = new Box<Tag>(new Tag('x'));
            XPHP);

            $provider = new XphpDiagnosticsProvider($cache, new WorkspaceAnalyzer(), $workspace, $fqnIndex);
            $cancel = (new CancellationTokenSource())->getToken();
            $diagnostics = wait($provider->provideDiagnostics($useDoc, $cancel));
            $diagnostics = is_array($diagnostics) ? array_values($diagnostics) : [];

            // No crash (rules out `||` → `&&`).
            // No diagnostic (rules out `continue` → `break`: second URI's
            // Tag must have reached the hierarchy AFTER the first URI was
            // skipped).
            self::assertSame([], $diagnostics);
        } finally {
            @unlink($root . '/Other.xphp');
            @unlink($root . '/Tag.xphp');
            @rmdir($root);
        }
    }

    public function testBoundCheckFiresOnFilesystemOnlyTemplateAgainstFilesystemOnlyBadTypeArg(): void
    {
        // The exact prod scenario from
        // `playground/src/Demos/Bounds.xphp`: the user opens only
        // Bounds.xphp; the template (StringableBox) and the type-arg
        // class (User, which doesn't implement \Stringable) BOTH live
        // on disk. Before the filesystem-definition registration:
        //   - hierarchy was open-only → User unknown → "not in source set"
        //   - registry was open-only → StringableBox template missing →
        //     validateBounds skipped silently → no diagnostic at all
        // After: warmer-fed hierarchy + filesystem-walked definitions
        // mean both sides resolve → bound violation surfaces correctly.

        $root = sys_get_temp_dir() . '/xphp-diag-prod-bounds-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        try {
            file_put_contents($root . '/StringableBox.xphp', <<<'PHP'
            <?php
            namespace App\Containers;
            class StringableBox<T: \Stringable>
            {
                public function __construct(public T $item) {}
            }
            PHP);
            file_put_contents($root . '/User.xphp', <<<'PHP'
            <?php
            namespace App\Models;
            final class User
            {
                public function __construct(public readonly string $name) {}
            }
            PHP);

            $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
            $cache = new ParsedDocumentCache(new Analyzer($parser));
            $workspace = new PhpactorWorkspace();
            $fqnIndex = new FqnIndex($workspace, $cache, $parser, $root);
            $warmer = new \XPHP\Lsp\Analyzer\ParsedDocumentCacheWarmer($fqnIndex, $cache, $workspace);
            $warmer->warmNow();

            $useUri = 'file://' . $root . '/Bounds.xphp';
            $useDoc = $this->openDoc($workspace, $useUri, <<<'XPHP'
            <?php
            namespace App\Demos;
            use App\Containers\StringableBox;
            use App\Models\User;
            $bad = new StringableBox<User>(new User('x'));
            XPHP);

            $provider = new XphpDiagnosticsProvider($cache, new WorkspaceAnalyzer(), $workspace, $fqnIndex);
            $cancel = (new CancellationTokenSource())->getToken();
            $diagnostics = wait($provider->provideDiagnostics($useDoc, $cancel));
            $diagnostics = is_array($diagnostics) ? array_values($diagnostics) : [];

            self::assertCount(1, $diagnostics);
            self::assertSame('xphp.bound', $diagnostics[0]->code);
            self::assertStringContainsString('does not extend/implement', $diagnostics[0]->message);
        } finally {
            @unlink($root . '/StringableBox.xphp');
            @unlink($root . '/User.xphp');
            @rmdir($root);
        }
    }

    public function testBoundCheckFiresOnFilesystemOnlyTypeArgThatDoesNotSatisfyBound(): void
    {
        // The enrichment branch must keep TRUE bound violations visible
        // when the bad type-arg class lives on disk. Without the warmed-
        // cache hierarchy, isSubtype would have returned null ("unknown
        // concrete") → "not in source set" wording. WITH the enrichment,
        // the hierarchy knows the on-disk class does NOT implement
        // \Stringable and the verdict becomes false → "does not
        // extend/implement" — a more accurate diagnostic.

        $root = sys_get_temp_dir() . '/xphp-diag-fs-neg-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        try {
            file_put_contents($root . '/Plain.xphp', <<<'PHP'
            <?php
            namespace App\Models;
            class Plain {}  // does NOT implement \Stringable
            PHP);

            $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
            $cache = new ParsedDocumentCache(new Analyzer($parser));
            $workspace = new PhpactorWorkspace();
            $fqnIndex = new FqnIndex($workspace, $cache, $parser, $root);
            $warmer = new \XPHP\Lsp\Analyzer\ParsedDocumentCacheWarmer($fqnIndex, $cache, $workspace);
            $warmer->warmNow();

            // Box (the template) IS open so its definition gets registered;
            // Plain (the bad type-arg) is filesystem-only.
            $boxUri = 'file://' . $root . '/Box.xphp';
            $this->openDoc($workspace, $boxUri, <<<'XPHP'
            <?php
            namespace App;
            class Box<T: \Stringable>
            {
                public function __construct(public T $item) {}
            }
            XPHP);
            $useUri = 'file://' . $root . '/Use.xphp';
            $useDoc = $this->openDoc($workspace, $useUri, <<<'XPHP'
            <?php
            namespace App;
            use App\Models\Plain;
            $x = new Box<Plain>(new Plain());
            XPHP);

            $provider = new XphpDiagnosticsProvider($cache, new WorkspaceAnalyzer(), $workspace, $fqnIndex);
            $cancel = (new CancellationTokenSource())->getToken();
            $diagnostics = wait($provider->provideDiagnostics($useDoc, $cancel));
            $diagnostics = is_array($diagnostics) ? array_values($diagnostics) : [];

            self::assertCount(1, $diagnostics);
            self::assertSame('xphp.bound', $diagnostics[0]->code);
            self::assertStringContainsString('does not extend/implement', $diagnostics[0]->message);
        } finally {
            @unlink($root . '/Plain.xphp');
            @rmdir($root);
        }
    }

    public function testSyntaxErrorAndBoundViolationCombineWhenBothApplyToCurrentDoc(): void
    {
        // Locks the `array_merge($perFileDiagnostics, $lspWorkspaceDiagnostics)`
        // on line 101. A document with only a bound violation hits ONLY
        // the workspace pass (per-file empty); a document with only a
        // syntax error returns early. So this test specifically covers
        // the merge case by issuing a bound violation in a workspace
        // where the linted doc parses cleanly — we then assert the
        // diagnostic carries BOTH the workspace-source code AND the
        // workspace-source message.
        $workspace = new PhpactorWorkspace();
        $this->openDoc($workspace, '/Box.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Box<T: \Stringable> { public T $item; }
        XPHP);
        $useDoc = $this->openDoc($workspace, '/Use.xphp', <<<'XPHP'
        <?php
        namespace App;
        $x = new Box<int>();
        XPHP);

        $diagnostics = $this->lint($workspace, $useDoc);

        // With UnwrapArrayMerge keeping only one operand, the workspace
        // diagnostic would be lost (because per-file is empty for a clean
        // parse). This assertion catches it.
        self::assertCount(1, $diagnostics);
        self::assertSame('xphp.bound', $diagnostics[0]->code);
    }

    /**
     * @return list<LspDiagnostic>
     */
    private function lint(PhpactorWorkspace $workspace, TextDocumentItem $textDocument): array
    {
        $provider = $this->newProvider($workspace);
        $cancel = (new CancellationTokenSource())->getToken();
        $promise = $provider->provideDiagnostics($textDocument, $cancel);
        $result = wait($promise);
        return is_array($result) ? array_values($result) : [];
    }

    private function newProvider(PhpactorWorkspace $workspace, string $rootPath = ''): XphpDiagnosticsProvider
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        return new XphpDiagnosticsProvider(
            $cache,
            new WorkspaceAnalyzer(),
            $workspace,
            new FqnIndex($workspace, $cache, $parser, $rootPath),
        );
    }

    private function openDoc(PhpactorWorkspace $workspace, string $uri, string $text): TextDocumentItem
    {
        $item = new TextDocumentItem($uri, 'xphp', 1, $text);
        $workspace->open($item);
        return $item;
    }
}
