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
