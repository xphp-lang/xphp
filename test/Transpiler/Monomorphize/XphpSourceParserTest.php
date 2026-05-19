<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class XphpSourceParserTest extends TestCase
{
    public function testAttachesGenericParamsToClassDefinition(): void
    {
        $source = <<<'PHP'
<?php
namespace App;

class Box<T>
{
    public T $item;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $class = self::findFirstClass($ast);
        self::assertNotNull($class);
        self::assertSame('Box', $class->name?->toString());
        self::assertSame(['T'], $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS));
    }

    public function testAttachesGenericArgsToNewExpressionResolvedAgainstNamespace(): void
    {
        $source = <<<'PHP'
<?php
namespace App;

$x = new Box<Plastic>();
PHP;
        $args = self::parseAndGetArgs($source, 'Box');
        self::assertCount(1, $args);
        self::assertSame('App\\Plastic', $args[0]->name);
        self::assertFalse($args[0]->isGeneric());
    }

    public function testResolvesGenericArgViaUseStatement(): void
    {
        $source = <<<'PHP'
<?php
namespace App;

use App\Models\Plastic;

$x = new Box<Plastic>();
PHP;
        $args = self::parseAndGetArgs($source, 'Box');
        self::assertCount(1, $args);
        self::assertSame('App\\Models\\Plastic', $args[0]->name);
    }

    public function testHandlesMultipleTypeArgs(): void
    {
        $source = <<<'PHP'
<?php
$x = new Map<string, User>();
PHP;
        $args = self::parseAndGetArgs($source, 'Map');
        self::assertCount(2, $args);
        self::assertSame('string', $args[0]->name);
        self::assertTrue($args[0]->isScalar);
        self::assertSame('User', $args[1]->name);
    }

    public function testHandlesNestedGenericArgs(): void
    {
        $source = <<<'PHP'
<?php
namespace App;

use App\Containers\Box;
use App\Containers\Lst;
use App\Models\Plastic;

$x = new Box<Lst<Plastic>>();
PHP;
        $args = self::parseAndGetArgs($source, 'Box');
        self::assertCount(1, $args);
        self::assertSame('App\\Containers\\Lst', $args[0]->name);
        self::assertTrue($args[0]->isGeneric());
        self::assertCount(1, $args[0]->args);
        self::assertSame('App\\Models\\Plastic', $args[0]->args[0]->name);
    }

    public function testAttachesGenericArgsToPropertyType(): void
    {
        $source = <<<'PHP'
<?php
namespace App;

use App\Containers\Box;
use App\Models\Plastic;

class Container {
    public Box<Plastic> $b;
}
PHP;
        $args = self::parseAndGetArgs($source, 'Box');
        self::assertCount(1, $args);
        self::assertSame('App\\Models\\Plastic', $args[0]->name);
    }

    public function testKeepsEnclosingTypeParamUnresolved(): void
    {
        $source = <<<'PHP'
<?php
namespace App\Containers;

use App\Containers\Box;

class Wrapper<T> {
    public Box<T> $b;
}
PHP;
        $args = self::parseAndGetArgs($source, 'Box');
        self::assertCount(1, $args);
        self::assertSame('T', $args[0]->name);
        self::assertTrue($args[0]->isTypeParam);
    }

    public function testIgnoresLessThanComparison(): void
    {
        $source = <<<'PHP'
<?php
$a = 1;
$b = 2;
if ($a < $b) {
    echo 'less';
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        // No exception, no markers wrongly attached. Just assert parsing succeeded with a non-trivial AST.
        self::assertGreaterThan(2, count($ast));
    }

    public function testResolvesGenericArgViaMultiSegmentUseAlias(): void
    {
        // `use App\Models;` aliases `Models` to `App\Models`. The arg `Models\Plastic` must
        // resolve to `App\Models\Plastic` — i.e. resolveTypeRef appends $rest (`\Plastic`)
        // to the use-map lookup (`App\Models`).
        $source = <<<'PHP'
<?php
namespace App;

use App\Models;
use App\Containers\Box;

$x = new Box<Models\Plastic>();
PHP;
        $args = self::parseAndGetArgs($source, 'Box');
        self::assertCount(1, $args);
        self::assertSame('App\\Models\\Plastic', $args[0]->name);
    }

    public function testResolvesTemplateFqnViaMultiSegmentUseAlias(): void
    {
        // Same alias mechanism but for the OUTER name carrying the genericArgs (resolveNameOnly).
        // `use App\Containers;` aliases `Containers` to `App\Containers`. Then `Containers\Box`
        // as the template name must resolve to `App\Containers\Box`.
        $source = <<<'PHP'
<?php
namespace App;

use App\Containers;
use App\Models\Plastic;

$x = new Containers\Box<Plastic>();
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $templateFqn = self::firstNameTemplateFqn($ast, 'Containers\\Box');
        self::assertSame('App\\Containers\\Box', $templateFqn);
    }

    /**
     * @param array<int, mixed> $ast
     */
    private static function firstNameTemplateFqn(array $ast, string $nameLookup): ?string
    {
        $found = null;
        $walker = function ($nodes) use (&$walker, &$found, $nameLookup): void {
            foreach ($nodes as $node) {
                if ($found !== null) {
                    return;
                }
                if ($node instanceof Name && $node->toString() === $nameLookup) {
                    $attr = $node->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
                    if (is_string($attr)) {
                        $found = $attr;
                        return;
                    }
                }
                if (is_object($node) && method_exists($node, 'getSubNodeNames')) {
                    foreach ($node->getSubNodeNames() as $name) {
                        $value = $node->$name;
                        if (is_array($value)) {
                            $walker($value);
                        } elseif (is_object($value)) {
                            $walker([$value]);
                        }
                    }
                }
            }
        };
        $walker($ast);
        return $found;
    }

    /**
     * @return list<TypeRef>
     */
    private static function parseAndGetArgs(string $source, string $nameLookup): array
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $found = null;
        $walker = function ($nodes) use (&$walker, &$found, $nameLookup): void {
            foreach ($nodes as $node) {
                if ($found !== null) {
                    return;
                }
                if ($node instanceof Name
                    && $node->toString() === $nameLookup
                    && is_array($node->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS))
                ) {
                    $found = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
                    return;
                }
                if (is_object($node) && method_exists($node, 'getSubNodeNames')) {
                    foreach ($node->getSubNodeNames() as $name) {
                        $value = $node->$name;
                        if (is_array($value)) {
                            $walker($value);
                        } elseif (is_object($value)) {
                            $walker([$value]);
                        }
                    }
                }
            }
        };
        $walker($ast);
        return $found ?? [];
    }

    /** @param array<int, mixed> $ast */
    private static function findFirstClass(array $ast): ?Class_
    {
        foreach ($ast as $node) {
            if ($node instanceof Class_) {
                return $node;
            }
            if ($node instanceof Namespace_) {
                foreach ($node->stmts ?? [] as $inner) {
                    if ($inner instanceof Class_) {
                        return $inner;
                    }
                }
            }
        }
        return null;
    }
}
