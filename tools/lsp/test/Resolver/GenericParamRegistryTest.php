<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Resolver;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Resolver\GenericParamRegistry;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class GenericParamRegistryTest extends TestCase
{
    public function testStripsNamespacePrefixOnSingleGenericPlaceholder(): void
    {
        $workspace = new PhpactorWorkspace();
        $registry = $this->registry($workspace);
        $this->openCollection($workspace);

        self::assertSame('T', $registry->prettify('App\\Containers\\T'));
        self::assertSame('?T', $registry->prettify('?App\\Containers\\T'));
    }

    public function testLeavesRealClassReferencesUnchanged(): void
    {
        $workspace = new PhpactorWorkspace();
        $registry = $this->registry($workspace);
        $this->openCollection($workspace);

        // User isn't a generic param of any class -- keep the FQN.
        self::assertSame('App\\Models\\User', $registry->prettify('App\\Models\\User'));
        self::assertSame('?App\\Models\\User', $registry->prettify('?App\\Models\\User'));
    }

    public function testLeavesScalarTypesAlone(): void
    {
        $workspace = new PhpactorWorkspace();
        $registry = $this->registry($workspace);
        $this->openCollection($workspace);

        self::assertSame('int', $registry->prettify('int'));
        self::assertSame('string', $registry->prettify('string'));
        self::assertSame('array', $registry->prettify('array'));
        self::assertSame('<missing>', $registry->prettify('<missing>'));
        self::assertSame('', $registry->prettify(''));
    }

    public function testHandlesMultiplePlaceholdersInOneTypeExpression(): void
    {
        $workspace = new PhpactorWorkspace();
        $registry = $this->registry($workspace);
        // Map<K, V>: two generic params at App\Containers level.
        $workspace->open(new TextDocumentItem('/Map.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Containers;
        class Map<K, V> {}
        XPHP));

        // Composite types with both placeholders get both stripped.
        self::assertSame(
            'array<K, V>',
            $registry->prettify('array<App\\Containers\\K, App\\Containers\\V>'),
        );
    }

    public function testIgnoresPlaceholderNamesInUnrelatedNamespaces(): void
    {
        // Class T in App\Models declares NO generic params, so a reference
        // to App\Models\T is a real class reference, not a placeholder.
        // App\Containers\T IS a placeholder via Collection<T>.
        $workspace = new PhpactorWorkspace();
        $registry = $this->registry($workspace);
        $this->openCollection($workspace);
        $workspace->open(new TextDocumentItem('/RealT.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Models;
        class T {}
        XPHP));

        self::assertSame('T', $registry->prettify('App\\Containers\\T'));
        self::assertSame('App\\Models\\T', $registry->prettify('App\\Models\\T'));
    }

    public function testReturnsInputUnchangedWhenNoGenericClassesOpen(): void
    {
        $workspace = new PhpactorWorkspace();
        $registry = $this->registry($workspace);
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, "<?php\nnamespace App\\Models;\nclass User {}\n"));

        self::assertSame('App\\Containers\\T', $registry->prettify('App\\Containers\\T'));
    }

    public function testStripsPlaceholderFromFilesystemOnlyGenericClass(): void
    {
        // Phase 0.5: the registry now pulls placeholder names from BOTH
        // open documents and filesystem-indexed classes via FqnIndex.
        // Before this migration, hovering a variable whose type traced to
        // a closed generic-class file showed the unstripped form
        // (`?App\Containers\T`).  Now it strips correctly even with
        // Collection.xphp closed -- matching the user's real-world
        // scenario from xphp-20260524-231944-855.log.
        $root = sys_get_temp_dir() . '/xphp-fs-reg-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        try {
            file_put_contents($root . '/Collection.xphp', <<<'XPHP'
            <?php
            namespace App\Containers;
            class Collection<T> {
                public function first(): ?T { return null; }
            }
            XPHP);

            $workspace = new PhpactorWorkspace();
            $registry = $this->registry($workspace, $root);

            self::assertSame('T', $registry->prettify('App\\Containers\\T'));
            self::assertSame('?T', $registry->prettify('?App\\Containers\\T'));
            self::assertSame(
                'array<T, App\\Models\\User>',
                $registry->prettify('array<App\\Containers\\T, App\\Models\\User>'),
            );
        } finally {
            $this->rmrf($root);
        }
    }

    public function testOpenDocWinsOverFilesystemPlaceholders(): void
    {
        // If the same generic class is declared both on disk and open in
        // the editor (e.g. unsaved edits adding a second type-param),
        // the open-doc params take precedence.
        $root = sys_get_temp_dir() . '/xphp-fs-reg-collision-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        try {
            // Disk: Collection<T>
            file_put_contents($root . '/Collection.xphp', <<<'XPHP'
            <?php
            namespace App\Containers;
            class Collection<T> { }
            XPHP);

            $workspace = new PhpactorWorkspace();
            $registry = $this->registry($workspace, $root);
            // Editor: Collection<K, V> (mid-edit adding a second param)
            $workspace->open(new TextDocumentItem(
                '/Collection.xphp',
                'xphp',
                1,
                "<?php\nnamespace App\\Containers;\nclass Collection<K, V> { }\n",
            ));

            // Both K and V (open-doc shape) are placeholders; T (disk
            // shape) is also still recognised because filesystem entries
            // that DON'T collide on FQN still contribute.  But for this
            // single FQN, open-doc wins -- the prior T placeholder
            // doesn't leak through.  K and V are recognised.
            self::assertSame('K', $registry->prettify('App\\Containers\\K'));
            self::assertSame('V', $registry->prettify('App\\Containers\\V'));
        } finally {
            $this->rmrf($root);
        }
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

    private function registry(PhpactorWorkspace $workspace, string $rootPath = ''): GenericParamRegistry
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $fqnIndex = new \XPHP\Lsp\Reflection\FqnIndex($workspace, $cache, $parser, $rootPath);
        return new GenericParamRegistry($fqnIndex);
    }

    private function openCollection(PhpactorWorkspace $workspace): void
    {
        $workspace->open(new TextDocumentItem('/Collection.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Containers;
        class Collection<T> {
            public function first(): ?T { return null; }
        }
        XPHP));
    }
}
