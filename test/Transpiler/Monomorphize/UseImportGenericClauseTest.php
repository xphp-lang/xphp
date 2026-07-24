<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\Diagnostics\DiagnosticCollector;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FilepathArray;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;
use XPHP\TestSupport\CompiledFixture;

/**
 * A generic clause on a namespace-import `use` (`use App\Box<int>;`, aliased, `use const`,
 * grouped, or the clause-on-prefix `use App<int>\{…}`) has no valid xphp meaning. Left alone
 * the single/aliased/const forms mis-blamed a double-qualified phantom template (`Main\App\Box`)
 * at a null line, and the grouped form emitted an absolute specialized name inside a `use N\{…}`
 * prefix group — unparseable PHP produced silently, escaping the check gate. Each form now
 * rejects with ONE diagnostic naming the real symbol at the import's real line, in both
 * `check` (collected) and `compile` (thrown). Ordinary imports and generic **trait-use**
 * (a `Stmt\TraitUse`, a different node) are unaffected.
 */
final class UseImportGenericClauseTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function rejectedForms(): iterable
    {
        // fixture => [expected-symbol-in-message, offending line]
        yield 'single'          => ['use_generic_import_single', 'App\\Box', 7];
        yield 'aliased'         => ['use_generic_import_aliased', 'App\\Box', 7];
        yield 'const'           => ['use_generic_import_const', 'App\\BOX', 7];
        yield 'grouped member'  => ['use_generic_import_grouped', 'App\\Box', 7];
        yield 'grouped prefix'  => ['use_generic_import_grouped_prefix', 'App', 7];
    }

    #[DataProvider('rejectedForms')]
    public function testCheckRejectsGenericClauseOnImportWithRightSymbolAndLine(
        string $fixture,
        string $symbol,
        int $line,
    ): void {
        $diagnostics = $this->check($fixture);

        self::assertCount(1, $diagnostics->all());
        $d = $diagnostics->all()[0];
        self::assertSame(Compiler::CODE_PARSE_ERROR, $d->code);
        // Names the source spelling, never the requalified phantom (`Main\App\Box`).
        self::assertSame(
            sprintf(
                'A generic clause is not allowed on a `use` import. Import the template with a '
                . 'plain `use %s;` and apply the type arguments at the use site (the hint '
                . '`%s<...>` or the call `%s::<...>()`).',
                $symbol,
                $symbol,
                $symbol,
            ),
            $d->message,
        );
        self::assertStringNotContainsString('Main\\App', $d->message);
        self::assertNotNull($d->location);
        self::assertSame($line, $d->location->line);
    }

    public function testMessageNamesTheSymbolAndBothUseSiteForms(): void
    {
        // Pin the full message so a reordered or truncated build is caught (the parts are
        // otherwise only substring-checked): it names the source symbol and shows both the
        // hint and the turbofish-call use-site forms.
        $d = $this->check('use_generic_import_single')->all()[0];

        self::assertSame(
            'A generic clause is not allowed on a `use` import. Import the template with a '
            . 'plain `use App\\Box;` and apply the type arguments at the use site (the hint '
            . '`App\\Box<...>` or the call `App\\Box::<...>()`).',
            $d->message,
        );
    }

    public function testAClauselessImportSharingASpellingIsNotRejected(): void
    {
        // Two imports of the SAME qualified name via distinct aliases (`use App\Box as B1;`
        // then `use App\Box<int> as B2;`): the clause-less first import must NOT be rejected.
        // Matching is byte-exact, not by spelling alone — a name-only match would cross-fire
        // and blame the wrong (clause-less) import. Exactly one diagnostic, at the
        // clause-bearing import (line 9), naming `App\Box`.
        $diagnostics = $this->check('use_generic_import_shadowed_name');

        self::assertCount(1, $diagnostics->all());
        $d = $diagnostics->all()[0];
        self::assertSame(Compiler::CODE_PARSE_ERROR, $d->code);
        self::assertNotNull($d->location);
        self::assertSame(9, $d->location->line);
        self::assertSame(
            'A generic clause is not allowed on a `use` import. Import the template with a '
            . 'plain `use App\\Box;` and apply the type arguments at the use site (the hint '
            . '`App\\Box<...>` or the call `App\\Box::<...>()`).',
            $d->message,
        );
    }

    public function testCompileThrowsOnGenericClauseImport(): void
    {
        // Compile mode: XphpParseException (a RuntimeException) propagates out of parse()
        // before any emit — the grouped silent fatal can no longer reach the writer.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not allowed on a `use` import');
        $this->compileFixture('use_generic_import_grouped');
    }

    public function testOrdinaryImportIsUnaffected(): void
    {
        self::assertSame([], $this->check('use_ordinary_import_clean')->all());
    }

    public function testGroupedImportWithoutClauseIsUnaffected(): void
    {
        self::assertSame([], $this->check('use_grouped_import_clean')->all());
    }

    #[RunInSeparateProcess]
    public function testGenericTraitUseStillSpecializesAndRuns(): void
    {
        // The must-keep guard: a generic trait-use is a Stmt\TraitUse, never a Stmt\Use_/
        // GroupUse, so the import reject never sees it. It still specializes, emits valid
        // PHP, and executes end-to-end.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/use_trait_generic/source',
            'use-trait-generic',
        );
        try {
            $fixture->registerAutoload('App\\UseTraitGeneric');
            $runtime = require __DIR__ . '/../../fixture/compile/use_trait_generic/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    private function check(string $fixture): DiagnosticCollector
    {
        return $this->buildCompiler()->check($this->sources($fixture));
    }

    private function compileFixture(string $fixture): void
    {
        $work = sys_get_temp_dir() . '/xphp-useimport-' . uniqid('', true);
        mkdir($work, 0o755, true);
        try {
            $this->buildCompiler()->compile(
                $this->sources($fixture),
                $this->sourceDir($fixture),
                $work . '/dist',
                $work . '/cache',
            );
        } finally {
            self::rrmdir($work);
        }
    }

    private function sources(string $fixture): FilepathArray
    {
        return (new NativeFileFinder())
            ->find($this->sourceDir($fixture))
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
    }

    private function sourceDir(string $fixture): string
    {
        return realpath(__DIR__ . '/../../fixture/check/' . $fixture . '/source')
            ?: throw new RuntimeException("Fixture missing: {$fixture}");
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function buildCompiler(): Compiler
    {
        $phpParser = (new ParserFactory())->createForHostVersion();
        $printer = new StandardPrinter();
        $writer = new NativeFileWriter();

        return new Compiler(
            new NativeFileReader(),
            $writer,
            new XphpSourceParser($phpParser),
            new Specializer(),
            new SpecializedClassGenerator($printer, $writer),
            $printer,
        );
    }
}
