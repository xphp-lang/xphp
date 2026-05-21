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

    public function testClassScopeIsPoppedAfterLeavingNestedClass(): void
    {
        // Locks the `array_pop($this->classStack)` on leaveNode. Without it,
        // the stack accumulates and a Name AFTER an unrelated earlier class
        // would carry that class as a (wrong) enclosing scope.
        //
        // Layout:
        //   class A {}             <- popped
        //   class B<T> {           <- enters, stays on stack while inside
        //       public T $item;
        //   }                      <- popped
        //   $x = T;                <- the T HERE has empty scope
        //
        // We hover on the standalone T outside any class: the resolved
        // classScope must be EMPTY. With the array_pop removed, B would
        // still be on the stack and reported as scope.
        $source = <<<'XPHP'
        <?php
        namespace App;
        class A {}
        class B<T> { public T $item; }
        $x = T;
        XPHP;
        $ast = $this->parse($source);

        $offset = strpos($source, '$x = T') + strlen('$x = ');
        $hit = AstPositionResolver::nameAtOffset($ast, $offset);

        self::assertNotNull($hit);
        self::assertSame('T', $hit['name']->toString());
        self::assertSame(
            [],
            $hit['classScope'],
            'standalone T outside any class must have an empty class scope; otherwise array_pop is failing',
        );
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
