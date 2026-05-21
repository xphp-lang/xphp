<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Handler\AstPositionResolver;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class AstPositionResolverTest extends TestCase
{
    public function testFindsSmallestNameContainingTheOffset(): void
    {
        $source = "<?php\nnew Box();";
        $ast = $this->parse($source);

        // Offset of 'B' in 'Box' on line 2.
        $offset = strpos($source, 'Box');
        $hit = AstPositionResolver::nameAtOffset($ast, $offset);

        self::assertNotNull($hit);
        self::assertSame('Box', $hit['name']->toString());
    }

    public function testReturnsNullWhenOffsetIsBetweenTokens(): void
    {
        $source = "<?php\n  new   Box();";
        $ast = $this->parse($source);

        // Offset of the space immediately after `new ` (before extra whitespace).
        $offset = strpos($source, '  new') + strlen('  new ');
        $hit = AstPositionResolver::nameAtOffset($ast, $offset);

        self::assertNull($hit);
    }

    public function testCapturesEnclosingClassLikeScopeForTypeParamLookups(): void
    {
        $source = <<<'XPHP'
        <?php
        namespace App;
        class Box<T>
        {
            public T $item;
        }
        XPHP;
        $ast = $this->parse($source);

        // Offset of the `T` in `public T $item;`.
        $offset = strpos($source, 'public T') + strlen('public ');
        $hit = AstPositionResolver::nameAtOffset($ast, $offset);

        self::assertNotNull($hit);
        self::assertSame('T', $hit['name']->toString());
        self::assertCount(1, $hit['classScope']);
        self::assertSame('Box', (string) $hit['classScope'][0]->name);
    }

    public function testReturnsNullPastEndOfFile(): void
    {
        $source = "<?php\nnew Box();";
        $ast = $this->parse($source);

        $positionMap = new PositionMap($source);
        // Past EOF.
        $offset = $positionMap->positionToOffset(99, 99);
        $hit = AstPositionResolver::nameAtOffset($ast, $offset);

        self::assertNull($hit);
    }

    /**
     * @return list<\PhpParser\Node\Stmt>
     */
    private function parse(string $source): array
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);
        self::assertNotNull($ast);
        return $ast;
    }
}
