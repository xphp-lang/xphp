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

    public function testResolvesGenericArgViaUseAliasWithDifferentRootNamespace(): void
    {
        // Catches `firstSegment` off-by-ones: the use alias and the current namespace
        // start with DIFFERENT prefixes, so the use-map-lookup path produces a different
        // result than the namespace-concat fallback. With substr offset jitter (or
        // UnwrapSubstr), firstSegment returns a string that misses the useMap entry and
        // the resolver falls back — observably wrong.
        $source = <<<'PHP'
<?php
namespace App;

use Vendor\Lib;

$x = new Box<Lib\Container>();
PHP;
        $args = self::parseAndGetArgs($source, 'Box');
        self::assertCount(1, $args);
        self::assertSame('Vendor\\Lib\\Container', $args[0]->name);
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

    // ===================================================================
    // B5: marker matching requires BOTH line AND name to match — kills the
    // LogicalAnd mutations on the class and name marker loops (lines 338, 360).
    // ===================================================================

    public function testClassMarkerRequiresBothLineAndNameMatch(): void
    {
        // Two classes on the SAME line, only the second is generic. classMarkers
        // contains exactly one entry {line: X, name: 'Box', params: ['T']}.
        // Under the original AND guard: only Box matches → Helper gets no genericParams,
        // Box gets ['T']. Under the OR mutation: Helper (visited first, same line) wrongly
        // grabs Box's marker → Helper marked as generic, Box left unmarked.
        $source = <<<'PHP'
<?php
namespace App;
class Helper {} class Box<T> { public T $item; }
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $classes = self::collectClasses($ast);
        self::assertCount(2, $classes);

        $byName = [];
        foreach ($classes as $c) {
            $byName[$c->name->toString()] = $c;
        }

        self::assertNull(
            $byName['Helper']->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS),
            'Helper must not steal Box\'s marker via line-only OR matching',
        );
        self::assertSame(
            ['T'],
            $byName['Box']->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS),
        );
    }

    public function testNameMarkerRequiresBothLineAndNameMatch(): void
    {
        // Two Name nodes on the SAME line: Foo (static call, no generics) and Box (new with generics).
        // nameMarkers has one entry {line: X, name: 'Box', args: [Plastic]}.
        // Under AND: only Box matches. Under OR mutation: Foo (visited first, same line) grabs
        // the marker → wrong attribute assignment; Box gets nothing.
        $source = <<<'PHP'
<?php
namespace App;
$y = Foo::method() + (new Box<Plastic>())->x;
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $fooArgs = self::firstNameAttr($ast, 'Foo', XphpSourceParser::ATTR_GENERIC_ARGS);
        $boxArgs = self::firstNameAttr($ast, 'Box', XphpSourceParser::ATTR_GENERIC_ARGS);

        self::assertNull($fooArgs, 'Foo must not pick up Box\'s genericArgs via line-only OR matching');
        self::assertIsArray($boxArgs);
        self::assertCount(1, $boxArgs);
        self::assertSame('App\\Plastic', $boxArgs[0]->name);
    }

    // ===================================================================
    // B6: typeParamStack must pop on class leaveNode — kills the
    // FunctionCallRemoval on `array_pop($this->typeParamStack)` (line 396).
    // ===================================================================

    public function testTypeParamScopeDoesNotLeakIntoSiblingClass(): void
    {
        // class A<T> {} — T is in A's scope while A is being walked.
        // After A's leaveNode, T must be popped. Otherwise, when class B's body is
        // walked, resolveTypeRef sees T in the stack and marks `Box<T>` as having
        // an unresolved type-param argument — which the registry then refuses to
        // record (allConcrete fails), so the instantiation goes missing.
        $source = <<<'PHP'
<?php
namespace App;

class A<T> {}

class B {
    public Box<T> $b;
}
PHP;
        $args = self::parseAndGetArgs($source, 'Box');
        self::assertCount(1, $args);
        self::assertFalse(
            $args[0]->isTypeParam,
            'A\'s type-param T must not leak into B\'s scope after array_pop',
        );
        self::assertSame('App\\T', $args[0]->name, 'T inside B should resolve as a real class via the namespace fallback');
    }

    // ===================================================================
    // B1: leading-backslash (fully-qualified) names in generic positions.
    // Kills UnwrapLtrim on lines 131 / 207 (where nameText / $tokens[$i]->text
    // get stripped of leading backslashes) and the Concat / ConcatOperandRemoval
    // mutations on lines 218 / 222 ($resolvedName = $isFq ? '\\' . $name : $name).
    // ===================================================================

    public function testFullyQualifiedArgIsResolvedExactly(): void
    {
        // `new Box<\Vendor\Foreign>()` — the leading backslash makes the resolved
        // FQN exactly `Vendor\Foreign`, regardless of the surrounding namespace.
        // This exercises the $isFq=true branch in parseTypeArg's non-recursive return
        // (line 222) and the resolveTypeRef ltrim that strips the leading backslash.
        $source = <<<'PHP'
<?php
namespace App\Containers;

$x = new Box<\Vendor\Foreign>();
PHP;
        $args = self::parseAndGetArgs($source, 'Box');
        self::assertCount(1, $args);
        self::assertSame('Vendor\\Foreign', $args[0]->name);
    }

    public function testFullyQualifiedTemplateNameIsRecognized(): void
    {
        // `new \App\Containers\Box<Plastic>()` — leading backslash on the OUTER name
        // (the template). The scanner's $nameText is `\App\Containers\Box`; line 131's
        // ltrim normalizes it to `App\Containers\Box` so the marker matches the AST's
        // Name node (which renders without the leading backslash).
        $source = <<<'PHP'
<?php
namespace App;

use App\Models\Plastic;

$x = new \App\Containers\Box<Plastic>();
PHP;
        $args = self::parseAndGetArgs($source, 'App\\Containers\\Box');
        self::assertCount(1, $args);
        self::assertSame('App\\Models\\Plastic', $args[0]->name);
    }

    public function testFullyQualifiedNamesInNestedGenericArg(): void
    {
        // `new Box<\Vendor\Lst<\Vendor\Plastic>>()` — nested generic where BOTH the
        // outer-arg template and the inner-arg type use the fully-qualified form.
        // Exercises the $isFq branch on line 218 (the recursive-into-generic return).
        $source = <<<'PHP'
<?php
namespace App;

$x = new Box<\Vendor\Lst<\Vendor\Plastic>>();
PHP;
        $args = self::parseAndGetArgs($source, 'Box');
        self::assertCount(1, $args);
        self::assertTrue($args[0]->isGeneric());
        self::assertSame('Vendor\\Lst', $args[0]->name);
        self::assertSame('Vendor\\Plastic', $args[0]->args[0]->name);
    }

    // ===================================================================
    // B2: isNameToken token-type coverage — kills the Identical mutations on
    // lines 270 / 271 (the T_NAME_FULLY_QUALIFIED and T_NAME_RELATIVE branches).
    // The T_NAME_FULLY_QUALIFIED branch is covered by the B1 tests above.
    // ===================================================================

    public function testRelativeNamespaceQualifiedNameIsRecognizedByScanner(): void
    {
        // `namespace\Foo` produces a T_NAME_RELATIVE token. The scanner must recognize
        // it as a name so the `<...>` clause is detected — without that, isNameToken
        // returns false, no marker is created, and the AST Name carries no genericArgs.
        //
        // Note: the resolver currently doesn't substitute `namespace\` with the actual
        // namespace prefix at resolution time (resolver bug — `namespace\Plastic` resolves
        // to `App\namespace\Plastic`). That's tracked separately; this test only locks the
        // tokenizer recognition path (kills the :271 Identical mutation).
        $source = <<<'PHP'
<?php
namespace App;

$x = new Box<namespace\Plastic>();
PHP;
        $args = self::parseAndGetArgs($source, 'Box');
        self::assertNotEmpty(
            $args,
            'a T_NAME_RELATIVE arg must be recognized by isNameToken so the <...> clause is parsed',
        );
    }

    // ===================================================================
    // B4: use-alias resolution coalesce — kills the Coalesce mutation on
    // `$u->alias?->toString() ?? self::lastSegment($fqn)` (line 408).
    // ===================================================================

    public function testUseStatementWithExplicitAliasResolvesViaAlias(): void
    {
        // `use Foo\Bar as Baz;` aliases Baz -> Foo\Bar. Then `new Box<Baz>()` must resolve
        // the arg via the alias path. Under the Coalesce mutation (args reversed), the
        // resolver would index by lastSegment($fqn) = 'Bar' instead of the alias 'Baz',
        // so the 'Baz' lookup at arg-resolution time would miss the use map.
        $source = <<<'PHP'
<?php
namespace App;

use Vendor\Plastic as Material;

$x = new Box<Material>();
PHP;
        $args = self::parseAndGetArgs($source, 'Box');
        self::assertCount(1, $args);
        self::assertSame('Vendor\\Plastic', $args[0]->name, 'arg resolution must use the explicit alias key, not the last segment of the FQN');
    }

    // ===================================================================
    // Helpers for the new tests above
    // ===================================================================

    /**
     * @param array<int, mixed> $ast
     * @return list<Class_>
     */
    private static function collectClasses(array $ast): array
    {
        $found = [];
        $walker = function ($nodes) use (&$walker, &$found): void {
            foreach ($nodes as $node) {
                if ($node instanceof Class_) {
                    $found[] = $node;
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
     * Find the first Name node whose ->toString() matches and return the value of $attribute.
     *
     * @param array<int, mixed> $ast
     */
    private static function firstNameAttr(array $ast, string $nameLookup, string $attribute): mixed
    {
        $value = null;
        $found = false;
        $walker = function ($nodes) use (&$walker, &$value, &$found, $nameLookup, $attribute): void {
            foreach ($nodes as $node) {
                if ($found) {
                    return;
                }
                if ($node instanceof Name && $node->toString() === $nameLookup) {
                    $value = $node->getAttribute($attribute);
                    $found = true;
                    return;
                }
                if (is_object($node) && method_exists($node, 'getSubNodeNames')) {
                    foreach ($node->getSubNodeNames() as $sub) {
                        $v = $node->$sub;
                        if (is_array($v)) {
                            $walker($v);
                        } elseif (is_object($v)) {
                            $walker([$v]);
                        }
                    }
                }
            }
        };
        $walker($ast);
        return $value;
    }
}
