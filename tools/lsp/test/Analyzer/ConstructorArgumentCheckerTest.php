<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Analyzer;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\DiagnosticCode;
use XPHP\Lsp\Analyzer\WorkspaceAnalyzer;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class ConstructorArgumentCheckerTest extends TestCase
{
    public function testFlagsUserPassedAsTagInGenericConstructor(): void
    {
        // The prod-driven motivating case: `StringableBox<Tag>` has
        // ctor `__construct(public T $item)`; with T=Tag the
        // substituted param is `Tag`.  Passing `new User('hello')`
        // here is a runtime TypeError waiting to happen.
        $diagnostics = $this->checkWorkspace([
            '/StringableBox.xphp' => <<<'PHP'
            <?php
            namespace App\Containers;
            class StringableBox<T: \Stringable>
            {
                public function __construct(public T $item) {}
            }
            PHP,
            '/Tag.xphp' => <<<'PHP'
            <?php
            namespace App\Models;
            final class Tag implements \Stringable {
                public function __toString(): string { return 'tag'; }
            }
            PHP,
            '/User.xphp' => <<<'PHP'
            <?php
            namespace App\Models;
            final class User {}
            PHP,
            '/Bounds.xphp' => <<<'PHP'
            <?php
            namespace App\Demos;
            use App\Containers\StringableBox;
            use App\Models\Tag;
            use App\Models\User;
            $v = new StringableBox<Tag>(new User());
            PHP,
        ]);

        $boundsDiags = self::filterByCode($diagnostics['/Bounds.xphp'], DiagnosticCode::ConstructorArgumentMismatch);
        self::assertCount(1, $boundsDiags, 'mismatch should surface on the Bounds.xphp use site');
        self::assertStringContainsString('App\\Models\\Tag', $boundsDiags[0]->message);
        self::assertStringContainsString('App\\Models\\User', $boundsDiags[0]->message);
    }

    public function testAcceptsCorrectGenericConstructorArgument(): void
    {
        // Same shape with the correct type-arg pairing -- no
        // diagnostic should fire.
        $diagnostics = $this->checkWorkspace([
            '/StringableBox.xphp' => <<<'PHP'
            <?php
            namespace App\Containers;
            class StringableBox<T: \Stringable>
            {
                public function __construct(public T $item) {}
            }
            PHP,
            '/Tag.xphp' => <<<'PHP'
            <?php
            namespace App\Models;
            final class Tag implements \Stringable {
                public function __toString(): string { return 'tag'; }
            }
            PHP,
            '/Bounds.xphp' => <<<'PHP'
            <?php
            namespace App\Demos;
            use App\Containers\StringableBox;
            use App\Models\Tag;
            $v = new StringableBox<Tag>(new Tag());
            PHP,
        ]);

        self::assertSame([], self::filterByCode($diagnostics['/Bounds.xphp'], DiagnosticCode::ConstructorArgumentMismatch));
    }

    public function testFlagsScalarLiteralMismatchOnNonGenericConstructor(): void
    {
        // Non-generic V1 path: `User` has `string $name` ctor param;
        // passing an int literal is a runtime TypeError.
        $diagnostics = $this->checkWorkspace([
            '/User.xphp' => <<<'PHP'
            <?php
            namespace App\Models;
            final class User {
                public function __construct(public string $name) {}
            }
            PHP,
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            use App\Models\User;
            $u = new User(42);
            PHP,
        ]);

        $diags = self::filterByCode($diagnostics['/Use.xphp'], DiagnosticCode::ConstructorArgumentMismatch);
        self::assertCount(1, $diags);
        self::assertStringContainsString('expects string, got int', $diags[0]->message);
    }

    public function testAcceptsScalarLiteralMatch(): void
    {
        $diagnostics = $this->checkWorkspace([
            '/User.xphp' => <<<'PHP'
            <?php
            namespace App\Models;
            final class User {
                public function __construct(public string $name) {}
            }
            PHP,
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            use App\Models\User;
            $u = new User('alice');
            PHP,
        ]);

        self::assertSame([], self::filterByCode($diagnostics['/Use.xphp'], DiagnosticCode::ConstructorArgumentMismatch));
    }

    public function testAcceptsSubclassInPlaceOfBaseClassParameter(): void
    {
        // Subclass argument should satisfy a base-class param.
        $diagnostics = $this->checkWorkspace([
            '/Animal.xphp' => <<<'PHP'
            <?php
            namespace App\Zoo;
            abstract class Animal {}
            PHP,
            '/Dog.xphp' => <<<'PHP'
            <?php
            namespace App\Zoo;
            final class Dog extends Animal {}
            PHP,
            '/Cage.xphp' => <<<'PHP'
            <?php
            namespace App\Zoo;
            final class Cage {
                public function __construct(public Animal $occupant) {}
            }
            PHP,
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            use App\Zoo\Cage;
            use App\Zoo\Dog;
            $c = new Cage(new Dog());
            PHP,
        ]);

        self::assertSame([], self::filterByCode($diagnostics['/Use.xphp'], DiagnosticCode::ConstructorArgumentMismatch));
    }

    public function testAcceptsNullForNullableParameter(): void
    {
        $diagnostics = $this->checkWorkspace([
            '/Item.xphp' => <<<'PHP'
            <?php
            namespace App;
            final class Item {
                public function __construct(public ?string $label) {}
            }
            PHP,
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            $i = new Item(null);
            PHP,
        ]);

        self::assertSame([], self::filterByCode($diagnostics['/Use.xphp'], DiagnosticCode::ConstructorArgumentMismatch));
    }

    public function testFlagsNullForNonNullableParameter(): void
    {
        $diagnostics = $this->checkWorkspace([
            '/Item.xphp' => <<<'PHP'
            <?php
            namespace App;
            final class Item {
                public function __construct(public string $label) {}
            }
            PHP,
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            $i = new Item(null);
            PHP,
        ]);

        $diags = self::filterByCode($diagnostics['/Use.xphp'], DiagnosticCode::ConstructorArgumentMismatch);
        self::assertCount(1, $diags);
        self::assertStringContainsString('expects string, got null', $diags[0]->message);
    }

    public function testSkipsArgumentsWhoseTypeCannotBeInferred(): void
    {
        // Variables / function calls don't carry static type info in
        // the AST; the checker MUST skip them to avoid false
        // positives.  Conservative behaviour.
        $diagnostics = $this->checkWorkspace([
            '/User.xphp' => <<<'PHP'
            <?php
            namespace App\Models;
            final class User {
                public function __construct(public string $name) {}
            }
            PHP,
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            use App\Models\User;
            $name = getName();
            $u = new User($name);
            PHP,
        ]);

        self::assertSame([], self::filterByCode($diagnostics['/Use.xphp'], DiagnosticCode::ConstructorArgumentMismatch));
    }

    public function testAcceptsPermissiveTypeParameters(): void
    {
        // `object`, `mixed`, `callable`, `iterable` accept any
        // argument shape -- the checker should never fire a
        // diagnostic on these.
        $diagnostics = $this->checkWorkspace([
            '/Wrap.xphp' => <<<'PHP'
            <?php
            namespace App;
            final class Wrap {
                public function __construct(public object $any) {}
            }
            PHP,
            '/Tag.xphp' => <<<'PHP'
            <?php
            namespace App;
            final class Tag {}
            PHP,
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            $w = new Wrap(new Tag());
            PHP,
        ]);

        self::assertSame([], self::filterByCode($diagnostics['/Use.xphp'], DiagnosticCode::ConstructorArgumentMismatch));
    }

    public function testPositionsDiagnosticOnTheBadArgumentItself(): void
    {
        // The squiggle must underline only the offending argument
        // expression, not the whole `new ...` call.  Verifies the
        // range-from-offsets path.
        $diagnostics = $this->checkWorkspace([
            '/User.xphp' => <<<'PHP'
            <?php
            namespace App\Models;
            final class User {
                public function __construct(public string $name) {}
            }
            PHP,
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            use App\Models\User;
            $u = new User(42);
            PHP,
        ]);

        $diag = self::filterByCode($diagnostics['/Use.xphp'], DiagnosticCode::ConstructorArgumentMismatch)[0];
        // `42` is on line 3 (0-indexed) of the Use.xphp fixture
        // (`<?php`=0, `namespace`=1, `use`=2, `$u = new User(42);`=3).
        self::assertSame(3, $diag->startLine);
        // The `42` literal is 2 chars wide; squiggle must match.
        self::assertSame(2, $diag->endCharacter - $diag->startCharacter);
    }

    /**
     * @return list<\XPHP\Lsp\Analyzer\Diagnostic>
     */
    private static function filterByCode(array $diagnostics, DiagnosticCode $code): array
    {
        return array_values(array_filter($diagnostics, static fn ($d): bool => $d->code === $code));
    }

    /**
     * @param array<string, string> $sources
     * @return array<string, list<\XPHP\Lsp\Analyzer\Diagnostic>>
     */
    private function checkWorkspace(array $sources): array
    {
        $files = $this->parseFiles($sources);
        return (new WorkspaceAnalyzer())->analyze($files);
    }

    /**
     * Mirror the prod path: the LSP's per-file Analyzer does NOT run
     * nikic's NameResolver before handing ASTs to the WorkspaceAnalyzer.
     * The checker must compute namespacedName + alias resolution from
     * the file's `namespace` + `use` statements itself.  Tests
     * deliberately skip NameResolver so a regression to "relies on
     * resolvedName" surfaces here.
     *
     * @param array<string, string> $sources
     * @return array<string, array{ast: list<\PhpParser\Node\Stmt>, source: string}>
     */
    private function parseFiles(array $sources): array
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $analyzer = new Analyzer($parser);
        $out = [];
        foreach ($sources as $path => $source) {
            $result = $analyzer->analyzeFile($source);
            self::assertNotNull($result->ast, "fixture {$path} should parse");
            $out[$path] = ['ast' => $result->ast, 'source' => $source];
        }
        return $out;
    }
}
