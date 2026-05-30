<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Analyzer;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\DiagnosticCode;
use XPHP\Lsp\Analyzer\WorkspaceAnalyzer;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class WorkspaceAnalyzerTest extends TestCase
{
    public function testBoundViolationOnScalarConcreteProducesDiagnosticOnInstantiationFile(): void
    {
        $files = $this->parseFiles([
            '/Box.xphp' => <<<'PHP'
            <?php
            namespace App;
            class Box<T: \Stringable>
            {
                public T $item;
            }
            PHP,
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            $x = new Box<int>();
            PHP,
        ]);

        $diagnostics = (new WorkspaceAnalyzer())->analyze($files);

        self::assertSame([], $diagnostics['/Box.xphp'], 'template file itself has no violation');
        self::assertCount(1, $diagnostics['/Use.xphp'], 'instantiation file should carry one bound-violation diagnostic');
        $d = $diagnostics['/Use.xphp'][0];
        self::assertStringContainsString('Generic bound violated', $d->message);
        self::assertSame(DiagnosticCode::BoundViolation, $d->code);
        // Column accuracy: the diagnostic must point at the offending `Box`
        // Name node (3 characters wide), not span the whole line. Locks the
        // `rangeFromOffsets` path against regression to the old
        // full-line behaviour.
        self::assertSame(3, $d->endCharacter - $d->startCharacter, 'range must span just the `Box` identifier');
        self::assertGreaterThan(0, $d->startCharacter, 'must not start at column 0 (whole-line) anymore');
    }

    public function testBoundViolationOnUnknownClassReportsDistinctMessage(): void
    {
        $files = $this->parseFiles([
            '/Box.xphp' => <<<'PHP'
            <?php
            namespace App;
            class Box<T: \Stringable>
            {
                public T $item;
            }
            PHP,
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            $x = new Box<Unknown\Thing>();
            PHP,
        ]);

        $diagnostics = (new WorkspaceAnalyzer())->analyze($files);

        self::assertCount(1, $diagnostics['/Use.xphp']);
        self::assertStringContainsString(
            'not in the source set',
            $diagnostics['/Use.xphp'][0]->message,
            'unknown concrete should hit the "compiler cannot prove satisfaction" branch',
        );
    }

    public function testNonGenericClassesDoNotProduceDefinitionDiagnostics(): void
    {
        // Locks the visitor's `!is_array($params) || $params === []` early
        // return. A non-generic ClassLike has no ATTR_GENERIC_PARAMS — the
        // visitor must skip recording the definition. With the guard
        // weakened, plain classes would be passed to recordDefinition with
        // empty params, which would throw or produce spurious diagnostics.
        $files = $this->parseFiles([
            '/Plain.xphp' => <<<'PHP'
            <?php
            namespace App;
            class Plain { public string $name; }
            PHP,
        ]);

        $diagnostics = (new WorkspaceAnalyzer())->analyze($files);
        self::assertSame([], $diagnostics['/Plain.xphp']);
    }

    public function testNonGenericNamesDoNotTriggerInstantiationRecording(): void
    {
        // Locks the `!is_array($args) || $args === []` guard on the
        // instantiation visitor. A bare `new Tag()` (no `<...>`) has no
        // ATTR_GENERIC_ARGS attribute — must skip cleanly.
        $files = $this->parseFiles([
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            class Tag {}
            $t = new Tag();
            PHP,
        ]);

        $diagnostics = (new WorkspaceAnalyzer())->analyze($files);
        self::assertSame([], $diagnostics['/Use.xphp']);
    }

    public function testNonConcreteGenericArgsAreSkippedDuringWorkspaceWalk(): void
    {
        // Locks the `foreach ($args as $a)` non-concrete check on line 153.
        // A Wrapper<T> template body referencing Box<T> has Box<T> as a
        // generic instantiation but T is still a type-param (not concrete)
        // — the visitor must skip it instead of trying to look up a
        // not-yet-resolvable template. With the foreach iterating [] the
        // skip never fires and recordInstantiation would receive a TypeRef
        // with isTypeParam=true, which is invalid input.
        $files = $this->parseFiles([
            '/Box.xphp' => <<<'PHP'
            <?php
            namespace App;
            class Box<T> { public T $item; }
            PHP,
            '/Wrapper.xphp' => <<<'PHP'
            <?php
            namespace App;
            class Wrapper<T>
            {
                public Box<T> $boxed;
            }
            PHP,
        ]);

        $diagnostics = (new WorkspaceAnalyzer())->analyze($files);
        self::assertSame([], $diagnostics['/Box.xphp']);
        self::assertSame([], $diagnostics['/Wrapper.xphp']);
    }

    public function testValidWorkspaceProducesNoDiagnostics(): void
    {
        $files = $this->parseFiles([
            '/Box.xphp' => <<<'PHP'
            <?php
            namespace App;
            class Box<T: \Stringable>
            {
                public T $item;
            }
            PHP,
            '/Tag.xphp' => <<<'PHP'
            <?php
            namespace App;
            class Tag implements \Stringable
            {
                public function __toString(): string { return ''; }
            }
            PHP,
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            $x = new Box<Tag>();
            PHP,
        ]);

        $diagnostics = (new WorkspaceAnalyzer())->analyze($files);

        foreach ($diagnostics as $path => $list) {
            self::assertSame([], $list, "{$path} should have no diagnostics");
        }
    }

    public function testDuplicateTemplateDeclarationProducesDiagnostic(): void
    {
        $files = $this->parseFiles([
            '/BoxOne.xphp' => <<<'PHP'
            <?php
            namespace App;
            class Box<T> { public T $item; }
            PHP,
            '/BoxTwo.xphp' => <<<'PHP'
            <?php
            namespace App;
            class Box<T> { public T $item; }
            PHP,
        ]);

        $diagnostics = (new WorkspaceAnalyzer())->analyze($files);

        // The second declaration encountered carries the duplicate-declaration diagnostic.
        // Iteration order over $files is insertion order, so BoxOne wins, BoxTwo gets the error.
        self::assertSame([], $diagnostics['/BoxOne.xphp']);
        self::assertCount(1, $diagnostics['/BoxTwo.xphp']);
        $d = $diagnostics['/BoxTwo.xphp'][0];
        self::assertStringContainsString('already declared', $d->message);
        self::assertSame(DiagnosticCode::Definition, $d->code);
        // Column accuracy: the diagnostic must point at the duplicate class
        // identifier (`Box`, 3 chars), not span the whole line. Locks the
        // `getEndFilePos() + 1` arithmetic against off-by-one regressions.
        self::assertSame(3, $d->endCharacter - $d->startCharacter, 'range must span just the `Box` identifier');
    }

    public function testHierarchyAstsEnrichBoundCheckWithoutBeingWalked(): void
    {
        $files = $this->parseFiles([
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            use App\Models\Tag;
            $x = new Box<Tag>(new Tag('hi'));
            PHP,
        ]);
        $hierarchyAsts = $this->parseAstOnly([
            '/Box.xphp' => <<<'PHP'
            <?php
            namespace App;
            class Box<T: \Stringable>
            {
                public function __construct(public T $item) {}
            }
            PHP,
            '/Tag.xphp' => <<<'PHP'
            <?php
            namespace App\Models;
            class Tag implements \Stringable
            {
                public function __construct(public string $name) {}
                public function __toString(): string { return $this->name; }
            }
            PHP,
        ]);

        $diagnostics = (new WorkspaceAnalyzer())->analyze($files, $hierarchyAsts);

        // Bound is satisfied via the hierarchy contribution: Tag → \Stringable.
        self::assertSame([], $diagnostics['/Use.xphp'], 'no bound violation when hierarchy includes Tag → \\Stringable');
        // Hierarchy-only entries are NOT walked, so they don't get a diagnostics slot.
        self::assertArrayNotHasKey('/Box.xphp', $diagnostics);
        self::assertArrayNotHasKey('/Tag.xphp', $diagnostics);
    }

    public function testHierarchyAstsAreSkippedWhenSameUriAlreadyInFiles(): void
    {
        // Open-doc entries in $files take precedence over hierarchyAsts entries
        // with the same URI — even when the AST differs (live > stale on-disk).
        // Stale AST claims Tag implements nothing; the live AST has it
        // implementing \Stringable, so the bound check must use the live one.
        $files = $this->parseFiles([
            '/Tag.xphp' => <<<'PHP'
            <?php
            namespace App\Models;
            class Tag implements \Stringable
            {
                public function __toString(): string { return ''; }
            }
            PHP,
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            use App\Models\Tag;
            $x = new Box<Tag>(new Tag());
            PHP,
        ]);
        $hierarchyAsts = $this->parseAstOnly([
            '/Tag.xphp' => <<<'PHP'
            <?php
            namespace App\Models;
            class Tag {}
            PHP,
            '/Box.xphp' => <<<'PHP'
            <?php
            namespace App;
            class Box<T: \Stringable>
            {
                public function __construct(public T $item) {}
            }
            PHP,
        ]);

        $diagnostics = (new WorkspaceAnalyzer())->analyze($files, $hierarchyAsts);

        self::assertSame([], $diagnostics['/Use.xphp'], 'live /Tag.xphp wins over stale hierarchy entry');
    }

    public function testHierarchyAstsLoopContinuesPastSameUriSkipToRegisterLaterTemplates(): void
    {
        // Locks the `continue` in the filesystem-definitions loop against
        // `break`. With `break`, the very first overlap with $files would
        // short-circuit, leaving Box's template unregistered → bound-check
        // verdict skipped → bound violation NOT surfaced.
        //
        // Scenario: $hierarchyAsts iterates a URI also in $files FIRST
        // (must `continue`), then a Box template that needs to register
        // (only reachable if the loop continues). $files has a `new
        // Box<User>(...)` call where User lacks \Stringable. With the
        // template registered, validateBounds runs → diagnostic fires.
        $files = $this->parseFiles([
            '/User.xphp' => <<<'PHP'
            <?php
            namespace App\Models;
            class User {}  // no \Stringable
            PHP,
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            use App\Models\User;
            $bad = new Box<User>(new User());
            PHP,
        ]);
        $hierarchyAsts = $this->parseAstOnly([
            // Same URI as $files → must hit the `continue` branch.
            '/User.xphp' => <<<'PHP'
            <?php
            namespace App\Models;
            class User implements \Stringable
            {
                public function __toString(): string { return ''; }
            }
            PHP,
            // Box's template: only registered if the loop continues past
            // the overlap above. Without registration, `Box<User>` skips
            // validateBounds silently and no diagnostic fires.
            '/Box.xphp' => <<<'PHP'
            <?php
            namespace App;
            class Box<T: \Stringable>
            {
                public function __construct(public T $item) {}
            }
            PHP,
        ]);

        $diagnostics = (new WorkspaceAnalyzer())->analyze($files, $hierarchyAsts);

        // Bound violation must fire: live User in $files (no Stringable)
        // can't satisfy Box's \Stringable bound. If the loop broke at the
        // first iteration, Box would be unregistered and this would be [].
        self::assertCount(1, $diagnostics['/Use.xphp']);
        self::assertStringContainsString(
            'Generic bound violated',
            $diagnostics['/Use.xphp'][0]->message,
        );
    }

    /**
     * @param array<string, string> $sources keyed by path → source
     * @return array<string, array{ast: list<\PhpParser\Node\Stmt>, source: string}>
     */
    private function parseFiles(array $sources): array
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $analyzer = new Analyzer($parser);
        $out = [];
        foreach ($sources as $path => $source) {
            $result = $analyzer->analyzeFile($source);
            self::assertNotNull($result->ast, "fixture {$path} should parse without syntax errors");
            $out[$path] = ['ast' => $result->ast, 'source' => $source];
        }
        return $out;
    }

    /**
     * @param array<string, string> $sources keyed by URI → source
     * @return array<string, list<\PhpParser\Node\Stmt>>
     */
    private function parseAstOnly(array $sources): array
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $analyzer = new Analyzer($parser);
        $out = [];
        foreach ($sources as $uri => $source) {
            $result = $analyzer->analyzeFile($source);
            self::assertNotNull($result->ast, "fixture {$uri} should parse without syntax errors");
            $out[$uri] = $result->ast;
        }
        return $out;
    }
}
