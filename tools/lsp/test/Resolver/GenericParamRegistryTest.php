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

    private function registry(PhpactorWorkspace $workspace): GenericParamRegistry
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        return new GenericParamRegistry($workspace, $cache);
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
