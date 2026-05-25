<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Resolver;

use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Resolver\FilesystemClassLikeLookup;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class FilesystemClassLikeLookupTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xphp-fs-cll-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->rmrf($this->root);
        }
    }

    public function testReturnsClassLikeFromFilesystemFile(): void
    {
        file_put_contents($this->root . '/Collection.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Collection<T> {
            public function first(): ?T { return null; }
        }
        XPHP);

        $lookup = $this->lookup();
        $class = $lookup->find('App\\Containers\\Collection');

        self::assertNotNull($class);
        self::assertSame('Collection', $class->name?->toString());
        // ClassLike must carry the xphp generic-params attribute -- without
        // it, GenericResolver can't substitute T at the call site.
        $params = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertIsArray($params);
        self::assertCount(1, $params);
        self::assertSame('T', $params[0]->name);
    }

    public function testReturnsNullForUnknownFqn(): void
    {
        $lookup = $this->lookup();
        self::assertNull($lookup->find('Mystery\\Class'));
    }

    private function lookup(): FilesystemClassLikeLookup
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $index = new FqnIndex(new PhpactorWorkspace(), $cache, $parser, $this->root);
        return new FilesystemClassLikeLookup($index);
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
