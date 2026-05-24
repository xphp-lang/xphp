<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Reflection;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\WorseReflection\Core\Exception\SourceNotFound;
use Phpactor\WorseReflection\Core\Name;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Reflection\FilesystemSourceLocator;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class FilesystemSourceLocatorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xphp-fs-loc-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->rmrf($this->root);
        }
    }

    public function testLocatesClassInPhpFile(): void
    {
        $path = $this->root . '/User.php';
        file_put_contents($path, "<?php\nnamespace App;\nclass User {}\n");

        $document = $this->newLocator()->locate(Name::fromString('App\\User'));

        self::assertStringEndsWith($path, (string) $document->uri());
        self::assertStringContainsString('class User', (string) $document);
    }

    public function testLocatesClassInXphpFileWithGenericClauseStripped(): void
    {
        $path = $this->root . '/Box.xphp';
        file_put_contents($path, "<?php\nnamespace App;\nclass Box<T> { public T \$item; }\n");

        $document = $this->newLocator()->locate(Name::fromString('App\\Box'));
        $text = (string) $document;

        // The `<T>` clause must be whitespace; class header and member still
        // at original offsets (so any Location worse-reflection derives from
        // the parsed source aligns with the editor's view of the .xphp file).
        self::assertStringNotContainsString('<T>', $text);
        self::assertStringContainsString('class Box', $text);
        self::assertStringContainsString('$item', $text);
    }

    public function testLocatesFunctionInXphpFile(): void
    {
        $path = $this->root . '/funcs.xphp';
        file_put_contents($path, "<?php\nfunction greet(string \$n) { return \$n; }\n");

        $document = $this->newLocator()->locate(Name::fromString('greet'));

        self::assertStringEndsWith($path, (string) $document->uri());
    }

    public function testFindsClassInSubdirectory(): void
    {
        mkdir($this->root . '/src/Models', 0o755, true);
        $path = $this->root . '/src/Models/User.php';
        file_put_contents($path, "<?php\nnamespace App\\Models;\nclass User {}\n");

        $document = $this->newLocator()->locate(Name::fromString('App\\Models\\User'));
        self::assertStringEndsWith($path, (string) $document->uri());
    }

    public function testSkipsVendorDirectory(): void
    {
        // vendor/ is intentionally excluded -- composer-installed code is
        // worse-reflection's job via stubs, not ours via brute-force scan.
        mkdir($this->root . '/vendor/some/pkg', 0o755, true);
        file_put_contents($this->root . '/vendor/some/pkg/Lib.php', "<?php\nclass VendorLib {}\n");

        $this->expectException(SourceNotFound::class);
        $this->newLocator()->locate(Name::fromString('VendorLib'));
    }

    public function testThrowsForUnknownFqn(): void
    {
        $this->expectException(SourceNotFound::class);
        $this->newLocator()->locate(Name::fromString('Nope\\Nope'));
    }

    public function testReturnsEmptyMapWhenRootMissing(): void
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $workspace = new PhpactorWorkspace();
        $root = '/path/that/definitely/does/not/exist';
        $locator = new FilesystemSourceLocator(
            new FqnIndex($workspace, $cache, $parser, $root),
            $parser,
            $root,
        );

        $this->expectException(SourceNotFound::class);
        $locator->locate(Name::fromString('Anything'));
    }

    private function newLocator(): FilesystemSourceLocator
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $workspace = new PhpactorWorkspace();
        return new FilesystemSourceLocator(
            new FqnIndex($workspace, $cache, $parser, $this->root),
            $parser,
            $this->root,
        );
    }

    private function rmrf(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $p = $dir . '/' . $entry;
            if (is_dir($p)) {
                $this->rmrf($p);
            } else {
                unlink($p);
            }
        }
        rmdir($dir);
    }
}
