<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Analyzer;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
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
        self::assertStringContainsString('Generic bound violated', $diagnostics['/Use.xphp'][0]->message);
        self::assertSame('xphp.bound', $diagnostics['/Use.xphp'][0]->code);
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
        self::assertStringContainsString('already declared', $diagnostics['/BoxTwo.xphp'][0]->message);
        self::assertSame('xphp.definition', $diagnostics['/BoxTwo.xphp'][0]->code);
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
}
