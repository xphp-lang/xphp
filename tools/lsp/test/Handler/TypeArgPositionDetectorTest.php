<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Handler\TypeArgPositionDetector;

final class TypeArgPositionDetectorTest extends TestCase
{
    public function testDetectsImmediatelyAfterOpenBracket(): void
    {
        $source = 'new Box<';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertSame(['prefix' => ''], $hit);
    }

    public function testDetectsWithPartialIdentifierPrefix(): void
    {
        $source = 'new Box<Pla';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertSame(['prefix' => 'Pla'], $hit);
    }

    public function testDetectsAfterCommaInMultiArgList(): void
    {
        $source = 'new Pair<Foo, ';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertSame(['prefix' => ''], $hit);
    }

    public function testDetectsInsideNestedGenericsAtSameDepth(): void
    {
        $source = 'new Box<List<int>, ';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertSame(['prefix' => ''], $hit);
    }

    public function testRejectsLessThanOperator(): void
    {
        $source = 'if ($a < ';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertNull($hit);
    }

    public function testRejectsOutsideAnyTypeArgClause(): void
    {
        $source = '$x = new Box(';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertNull($hit);
    }

    public function testRejectsAfterClosingBracket(): void
    {
        $source = 'new Box<Plastic> ';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertNull($hit, 'cursor past the closing `>` is no longer in type-arg context');
    }

    public function testAcceptsFqnStylePrefix(): void
    {
        // Backslashes are part of the identifier so an FQN prefix matches as one token.
        $source = 'new Box<App\\Mo';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertNotNull($hit);
        self::assertSame('App\\Mo', $hit['prefix']);
    }

    public function testHandlesNestedDepthCorrectly(): void
    {
        // Inside the INNER `<…>`, prefix is the partial identifier just typed.
        $source = 'new Box<List<Pla';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertSame(['prefix' => 'Pla'], $hit);
    }
}
