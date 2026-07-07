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
        self::assertSame(['T'], self::paramNames($class));
    }

    public function testAttachesGenericParamsToInterfaceDefinition(): void
    {
        $source = <<<'PHP'
<?php
namespace App;

interface Container<T>
{
    public function get(): T;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $interface = self::findFirstClassLike($ast, \PhpParser\Node\Stmt\Interface_::class);
        self::assertNotNull($interface);
        self::assertSame('Container', $interface->name?->toString());
        self::assertSame(['T'], self::paramNames($interface));
        self::assertSame('App\\Container', $interface->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN));
    }

    public function testGenericTraitTemplateIsDroppedFromOutputWithoutBecomingAMarker(): void
    {
        // Locks the `instanceof Class_ || instanceof Interface_` guard in CallSiteRewriter:
        // traits don't get a marker interface (PHP can't instanceof a trait), so the
        // generic trait template must be stripped entirely. A mutation that loosens the
        // guard (e.g., LogicalOrAllSubExprNegation -> true-for-all-ClassLike) would
        // smuggle the trait through as an empty marker interface, which would corrupt
        // any class that `use`s it.
        $source = <<<'PHP'
<?php
namespace App;

trait HasTimestamps<T>
{
    private T $first;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $rewriter = new CallSiteRewriter(new Registry());
        $rewritten = $rewriter->rewrite($ast);

        $printer = new \PhpParser\PrettyPrinter\Standard();
        $printed = $printer->prettyPrintFile($rewritten);

        self::assertStringNotContainsString('HasTimestamps', $printed, 'generic trait must be removed, not replaced with an interface marker');
        self::assertStringNotContainsString('interface HasTimestamps', $printed);
        self::assertStringNotContainsString('trait HasTimestamps', $printed);
    }

    public function testAttachesGenericParamsToTraitDefinition(): void
    {
        // Traits ride the same ClassLike pathway as classes/interfaces. Locks the
        // T_TRAIT branch of the scanner's keyword guard.
        $source = <<<'PHP'
<?php
namespace App;

trait HasCollection<T>
{
    private T $first;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $trait = self::findFirstClassLike($ast, \PhpParser\Node\Stmt\Trait_::class);
        self::assertNotNull($trait);
        self::assertSame(['T'], self::paramNames($trait));
    }

    public function testAttachesBoundedTypeParamToClassDefinition(): void
    {
        $source = <<<'PHP'
<?php
namespace App;

class Box<T: \Stringable>
{
    public T $item;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $class = self::findFirstClass($ast);
        self::assertNotNull($class);
        $params = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertIsArray($params);
        self::assertCount(1, $params);
        self::assertSame('T', $params[0]->name);
        self::assertInstanceOf(BoundLeaf::class, $params[0]->bound);
        self::assertSame('Stringable', $params[0]->bound->type->name, 'leading-\\ marks bound as fully qualified — must NOT get the App\\ prefix');
    }

    public function testBoundedTypeParamResolvesAgainstUseAlias(): void
    {
        // Locks the alias-resolution path on bounds — exactly the same logic the rest of the
        // scanner uses for type args, but reached via the new parseTypeParamList code path.
        $source = <<<'PHP'
<?php
namespace App;

use App\Contracts\HasName as NamedThing;

class Repo<T: NamedThing>
{
    public T $item;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $class = self::findFirstClass($ast);
        self::assertNotNull($class);
        $params = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertInstanceOf(BoundLeaf::class, $params[0]->bound);
        self::assertSame('App\\Contracts\\HasName', $params[0]->bound->type->name);
    }

    public function testMixesBoundedAndUnboundedTypeParams(): void
    {
        $source = <<<'PHP'
<?php
namespace App;

class Pair<K: \Stringable, V>
{
    public K $key;
    public V $value;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $class = self::findFirstClass($ast);
        self::assertNotNull($class);
        $params = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertCount(2, $params);
        self::assertSame('K', $params[0]->name);
        self::assertInstanceOf(BoundLeaf::class, $params[0]->bound);
        self::assertSame('Stringable', $params[0]->bound->type->name);
        self::assertSame('V', $params[1]->name);
        self::assertNull($params[1]->bound, 'V has no bound — bound must stay null');
    }

    public function testIntersectionBoundIsParsedAsBoundIntersection(): void
    {
        // `T : A & B` parses as a BoundIntersection of two leaves.
        $source = <<<'PHP'
<?php
namespace App;

class Sortable<T: \Stringable & \Countable>
{
    public T $item;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $class = self::findFirstClass($ast);
        self::assertNotNull($class);
        $params = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertCount(1, $params);
        self::assertInstanceOf(BoundIntersection::class, $params[0]->bound);
        self::assertCount(2, $params[0]->bound->operands);
        self::assertInstanceOf(BoundLeaf::class, $params[0]->bound->operands[0]);
        self::assertSame('Stringable', $params[0]->bound->operands[0]->type->name);
        self::assertSame('Countable', $params[0]->bound->operands[1]->type->name);
    }

    public function testUnionBoundIsParsedAsBoundUnion(): void
    {
        // `T : A | B` parses as a BoundUnion of two leaves.
        $source = <<<'PHP'
<?php
namespace App;

class Either<T: \Stringable | \Countable>
{
    public T $item;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $class = self::findFirstClass($ast);
        self::assertNotNull($class);
        $params = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertInstanceOf(BoundUnion::class, $params[0]->bound);
        self::assertCount(2, $params[0]->bound->operands);
    }

    public function testDnfBoundIsParsedAsUnionOfIntersections(): void
    {
        // `(A & B) | C` builds Union(Intersection(A, B), C).
        $source = <<<'PHP'
<?php
namespace App;

class Combined<T: (\Stringable & \Countable) | \Iterator>
{
    public T $item;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $class = self::findFirstClass($ast);
        self::assertNotNull($class);
        $params = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        $bound = $params[0]->bound;
        self::assertInstanceOf(BoundUnion::class, $bound);
        self::assertCount(2, $bound->operands);
        self::assertInstanceOf(BoundIntersection::class, $bound->operands[0]);
        self::assertCount(2, $bound->operands[0]->operands);
        self::assertInstanceOf(BoundLeaf::class, $bound->operands[1]);
        self::assertSame('Iterator', $bound->operands[1]->type->name);
    }

    public function testFBoundedRecursionAcceptsBoundWithGenericArgs(): void
    {
        // `T : Comparable<T>` parses as a leaf whose TypeRef carries
        // a single arg (the T type-param), enabling F-bounded recursion. The
        // top-level self-reference guard must NOT fire here because the inner
        // T is nested inside Comparable's generic args, not bare.
        $source = <<<'PHP'
<?php
namespace App;

class Sortable<T: \Comparable<T>>
{
    public T $item;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);    // must not throw

        $class = self::findFirstClass($ast);
        self::assertNotNull($class);
        $params = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertInstanceOf(BoundLeaf::class, $params[0]->bound);
        self::assertSame('Comparable', $params[0]->bound->type->name);
        self::assertCount(1, $params[0]->bound->type->args);
        self::assertSame('T', $params[0]->bound->type->args[0]->name);
        self::assertTrue($params[0]->bound->type->args[0]->isTypeParam, 'inner T must resolve as a type-param ref via the enclosing scope');
    }

    public function testSelfReferenceGuardFiresOnOperandOfCompoundBound(): void
    {
        // `T : T & Foo` is forbidden too (the bare-self leaf is an
        // operand of an intersection, not the top-level node, but it still
        // counts as self-reference).
        $source = <<<'PHP'
<?php
namespace App;

class A<T : T & \Stringable> {
    public T $item;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot use itself as a bound');
        $parser->parse($source);
    }

    public function testSelfReferenceGuardFiresOnRightOperandOfUnion(): void
    {
        // the recursion in `boundContainsSelfReference`
        // walks every operand. A first-operand-only mutation would survive
        // the existing `T : T & Foo` test (where T is the FIRST operand)
        // but be killed by this test (where T is the SECOND operand of a
        // union and the FIRST is a real class).
        $source = <<<'PHP'
<?php
namespace App;

class A<T : \Stringable | T> {
    public T $item;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot use itself as a bound');
        $parser->parse($source);
    }

    public function testSelfReferenceGuardFiresOnDeeplyNestedOperand(): void
    {
        // the bare-self leaf can be arbitrarily
        // deep in the bound tree (`T : (A & T) | B` here). The recursion
        // walks every operand level; any mutation that only checks the top
        // level OR only one level deep would survive this test.
        $source = <<<'PHP'
<?php
namespace App;

class A<T : (\Stringable & T) | \Iterator> {
    public T $item;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot use itself as a bound');
        $parser->parse($source);
    }

    public function testSelfReferenceGuardSkipsEntriesWithoutBoundAndChecksLater(): void
    {
        // Mutation regression: assertNoTopLevelSelfReference's `continue` -> `break`
        // would exit the loop on the first bound-less entry. Test shape:
        // `<K, T : T>` -- K has no bound (triggers the `continue`), T has the
        // self-reference. With `break`, K's entry would terminate the loop
        // and T's self-reference would slip through.
        $source = <<<'PHP'
<?php
namespace App;

class Pair<K, T : T> {
    public K $key;
    public T $val;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot use itself as a bound');
        $parser->parse($source);
    }

    public function testForwardReferenceBoundResolvesToTypeParamRef(): void
    {
        // `class C<K, T : K>` -- T's bound is the EARLIER type-param K, not a
        // class. resolveNameOnly must short-circuit on `isEnclosingTypeParam`
        // and return the param name as-is, NOT qualify it to `App\K`.
        //
        // Kills the ReturnRemoval mutant on resolveNameOnly's type-param branch
        // (removing the `return $name` would fall through to namespace
        // qualification, resolving K to `App\K` which doesn't exist as a class).
        $source = <<<'PHP'
<?php
namespace App;

class Container<K, T : K> {
    public K $key;
    public T $val;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $class = self::findFirstClass($ast);
        self::assertNotNull($class);
        $params = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertCount(2, $params);
        self::assertSame('T', $params[1]->name);
        self::assertInstanceOf(BoundLeaf::class, $params[1]->bound);
        self::assertSame(
            'K',
            $params[1]->bound->type->name,
            'forward-reference bound must resolve K as a bare type-param name, not App\\K',
        );
    }

    public function testTopLevelSelfReferenceBoundIsRejectedAtDeclarationTime(): void
    {
        // RFC bound-erased generic types forbids `class A<T : T>` -- T cannot use
        // itself as a bound at top level. The error fires at declaration time
        // rather than later at instantiation, where the user would see a
        // confusing "compiler cannot prove satisfaction" message instead.
        $source = <<<'PHP'
<?php
namespace App;

class A<T : T> {
    public T $item;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot use itself as a bound');
        $parser->parse($source);
    }

    public function testFullyQualifiedBoundWithSameNameAsTypeParamIsAllowed(): void
    {
        // `class A<T : \T>` is NOT a self-reference -- the leading backslash
        // makes `\T` a global-class reference, not the type parameter. The
        // self-reference guard must skip this case.
        $source = <<<'PHP'
<?php
namespace App;

class A<T : \T> {
    public T $item;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);    // must not throw

        $class = self::findFirstClass($ast);
        self::assertNotNull($class);
        $params = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertCount(1, $params);
        self::assertSame('T', $params[0]->name);
        self::assertInstanceOf(BoundLeaf::class, $params[0]->bound);
        self::assertSame('T', $params[0]->bound->type->name, 'leading-\\ marks bound as FQ -- resolves to global `T`, not the type-param');
    }

    public function testSelfWithTypeArgsInReturnPositionIsAccepted(): void
    {
        // RFC class pseudo-types: `self<T>`, `static<T>`, `parent<T>` are
        // accepted in type-hint positions. Verifies the scanner strips the
        // `<T>` clause so PHP can parse the method signature, AND that no
        // generic-args marker leaks onto the bare `self` Name node -- the
        // pseudo-types are class references, not template references, so the
        // Registry must never see them as templates to specialize.
        $source = <<<'PHP'
<?php
namespace App;

class Container<T> {
    public T $item;

    public function with(T $newItem): self<T> {
        $this->item = $newItem;
        return $this;
    }
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $stripped = $parser->strip($source);
        self::assertStringNotContainsString('self<T>', $stripped);
        self::assertStringContainsString(': self', $stripped);

        // The pseudo-type's Name node must NOT carry ATTR_GENERIC_ARGS --
        // otherwise the Registry would try to specialize `App\self`.
        self::assertNull(
            self::firstNameAttr($ast, 'self', XphpSourceParser::ATTR_GENERIC_ARGS),
            'self<T> must not attach generic-args marker; pseudo-types are class refs, not templates',
        );
    }

    public function testStaticWithTypeArgsInReturnPositionIsAccepted(): void
    {
        // Same as the self<T> case but for late-static-bound `static<T>`.
        $source = <<<'PHP'
<?php
namespace App;

class Builder<T> {
    public function reset(): static<T> {
        return $this;
    }
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $stripped = $parser->strip($source);

        self::assertStringNotContainsString('static<T>', $stripped);
        self::assertStringContainsString(': static', $stripped);

        $ast = $parser->parse($source);
        self::assertNull(
            self::firstNameAttr($ast, 'static', XphpSourceParser::ATTR_GENERIC_ARGS),
            'static<T> must not attach generic-args marker',
        );
    }

    public function testParentWithTypeArgsInReturnPositionIsAccepted(): void
    {
        // `parent<T>` in a return position resolves to the parent class
        // specialized with T. Same recognizer / strip mechanism.
        $source = <<<'PHP'
<?php
namespace App;

class Sub<T> extends Container {
    public function reset(): parent<T> {
        return parent::reset();
    }
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $stripped = $parser->strip($source);

        self::assertStringNotContainsString('parent<T>', $stripped);
        self::assertStringContainsString(': parent', $stripped);

        $ast = $parser->parse($source);
        self::assertNull(
            self::firstNameAttr($ast, 'parent', XphpSourceParser::ATTR_GENERIC_ARGS),
            'parent<T> must not attach generic-args marker',
        );

        $parser->parse($source);    // must not throw
    }

    public function testAnonymousClassWithAngleBracketsIsNotRecognizedAsTemplate(): void
    {
        // RFC bound-erased generic types forbids type parameters on anonymous
        // classes (`new class<T> { ... }` is "unrecoverably ambiguous" per the
        // RFC text). xphp's T_CLASS branch already requires a T_STRING name
        // after the keyword, so `new class<T>` never reaches the template
        // recognizer. This test locks the alignment-by-shape so a future
        // refactor of the T_CLASS branch can't quietly start accepting
        // anonymous-class type parameters.
        $source = <<<'PHP'
<?php
$x = new class<T> { public int $item = 0; };
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());

        // Contract: the scanner does NOT strip the `<T>` clause -- it must
        // survive into the cleaned source so any downstream tooling sees the
        // form as invalid PHP rather than xphp silently specializing it.
        $stripped = $parser->strip($source);
        self::assertStringContainsString('class<T>', $stripped, 'anon-class `<T>` must be left un-stripped');
    }

    public function testMultiLineTypeParamClauseKeepsLaterLineKeyedMarkersAligned(): void
    {
        // The `<…>` strip used to collapse newlines to spaces, shifting every
        // later LINE-KEYED marker: `class Wide<\n T\n>` before `class Box<T>`
        // left Box un-generic — raw `T` hints in the emitted code, a silent
        // runtime fatal. Blanking must preserve BOTH byte length and line count.
        $source = <<<'PHP'
<?php
namespace App;

class Wide<
    T
> {
    public function __construct(public T $v) {}
}

class Box<T> {
    public function __construct(public T $v) {}
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $stripped = $parser->strip($source);
        self::assertSame(strlen($source), strlen($stripped), 'blanking must preserve byte length');
        self::assertSame(
            substr_count($source, "\n"),
            substr_count($stripped, "\n"),
            'blanking must preserve line count',
        );

        $ast = $parser->parse($source);
        $byName = self::classesByName($ast);
        self::assertSame(['T'], self::paramNames($byName['Wide']));
        self::assertSame(
            ['T'],
            self::paramNames($byName['Box']),
            'the class declared AFTER a multi-line clause must keep its type params',
        );
    }

    public function testMultiLineTurbofishArgListKeepsLaterMarkersAligned(): void
    {
        $source = <<<'PHP'
<?php
namespace App;

class Box<T> {
    public function __construct(public T $v) {}
}

$b = new Box::<
    int
>(1);

class Pair<U> {
    public function __construct(public U $u) {}
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $stripped = $parser->strip($source);
        self::assertSame(strlen($source), strlen($stripped));
        self::assertSame(substr_count($source, "\n"), substr_count($stripped, "\n"));

        $ast = $parser->parse($source);
        self::assertNotNull(
            self::firstNameAttr($ast, 'Box', XphpSourceParser::ATTR_GENERIC_ARGS),
            'the multi-line turbofish itself must still attach',
        );
        self::assertSame(
            ['U'],
            self::paramNames(self::classesByName($ast)['Pair']),
            'the class declared AFTER a multi-line turbofish must keep its type params',
        );
    }

    public function testMultibyteInsideStrippedClauseBlanksPerByte(): void
    {
        // A multibyte character inside the blanked span (here in a comment the
        // clause tolerates) must blank to one space PER BYTE — a /u-flagged
        // blanking would shrink the file and shift every later byte-keyed marker.
        $source = <<<'PHP'
<?php
namespace App;

class Wide<
    T // café
> {}

class Box<T> {
    public function __construct(public T $v) {}
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $stripped = $parser->strip($source);
        self::assertSame(strlen($source), strlen($stripped), 'multibyte spans must blank per byte');

        $ast = $parser->parse($source);
        self::assertSame(['T'], self::paramNames(self::classesByName($ast)['Box']));
    }

    public function testCrlfInsideStrippedClauseIsPreserved(): void
    {
        $source = "<?php\r\nnamespace App;\r\nclass Wide<\r\n    T\r\n> {}\r\nclass Box<T> {\r\n"
            . "    public function __construct(public T \$v) {}\r\n}\r\n";
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $stripped = $parser->strip($source);
        self::assertSame(strlen($source), strlen($stripped));
        self::assertSame(substr_count($source, "\r\n"), substr_count($stripped, "\r\n"));

        $ast = $parser->parse($source);
        self::assertSame(['T'], self::paramNames(self::classesByName($ast)['Box']));
    }

    public function testArraySugarSpanWithNewlineKeepsLineCount(): void
    {
        // The sugar rewrite is length-CHANGING (`Rec[\n]` -> `array`), so byte
        // length cannot be preserved — but the span's newlines must be
        // re-appended or every later line-keyed marker shifts.
        $source = <<<'PHP'
<?php
namespace App;

class Rec {}

function tally(Rec[
] $rs): int {
    return \count($rs);
}

class Box<T> {
    public function __construct(public T $v) {}
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $stripped = $parser->strip($source);
        self::assertStringContainsString('array', $stripped);
        self::assertSame(
            substr_count($source, "\n"),
            substr_count($stripped, "\n"),
            'a length-changing sugar rewrite must still keep the line count',
        );
        self::assertSame(
            strpos($source, 'Rec['),
            strpos($stripped, 'array'),
            'the lowered type must start where the original type started — the '
            . 'span\'s newlines belong AFTER it, or positions stop rounding-trip',
        );

        $ast = $parser->parse($source);
        self::assertSame(
            ['T'],
            self::paramNames(self::classesByName($ast)['Box']),
            'the class declared AFTER a newline-bearing sugar span must keep its type params',
        );
    }

    public function testForwardReferenceToEarlierTypeParamAsBoundIsAllowed(): void
    {
        // `class C<T, U : T>` is NOT a self-reference -- U's bound references
        // a DIFFERENT type parameter (T), not itself. The RFC explicitly allows
        // this (the "forward references and mutual recursion" clause).
        $source = <<<'PHP'
<?php
namespace App;

class Pair<T, U : T> {
    public T $first;
    public U $second;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);    // must not throw

        $class = self::findFirstClass($ast);
        self::assertNotNull($class);
        $params = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertCount(2, $params);
    }

    public function testAttachesGenericArgsToNewExpressionResolvedAgainstNamespace(): void
    {
        $source = <<<'PHP'
<?php
namespace App;

$x = new Box::<Plastic>();
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

$x = new Box::<Plastic>();
PHP;
        $args = self::parseAndGetArgs($source, 'Box');
        self::assertCount(1, $args);
        self::assertSame('App\\Models\\Plastic', $args[0]->name);
    }

    public function testHandlesMultipleTypeArgs(): void
    {
        $source = <<<'PHP'
<?php
$x = new Map::<string, User>();
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
use App\Containers\Collection;
use App\Models\Plastic;

$x = new Box::<Collection<Plastic>>();
PHP;
        $args = self::parseAndGetArgs($source, 'Box');
        self::assertCount(1, $args);
        self::assertSame('App\\Containers\\Collection', $args[0]->name);
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

$x = new Box::<Models\Plastic>();
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

$x = new Box::<Lib\Container>();
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

$x = new Containers\Box::<Plastic>();
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

    /**
     * @return list<string>
     */
    private static function paramNames(\PhpParser\Node\Stmt\ClassLike $node): array
    {
        $params = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        if (!is_array($params)) {
            return [];
        }
        return array_map(static fn (TypeParam $p): string => $p->name, $params);
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

    /**
     * @template TNode of \PhpParser\Node\Stmt\ClassLike
     * @param array<int, mixed> $ast
     * @param class-string<TNode> $kind
     * @return TNode|null
     */
    private static function findFirstClassLike(array $ast, string $kind): ?\PhpParser\Node\Stmt\ClassLike
    {
        foreach ($ast as $node) {
            if ($node instanceof $kind) {
                return $node;
            }
            if ($node instanceof Namespace_) {
                foreach ($node->stmts ?? [] as $inner) {
                    if ($inner instanceof $kind) {
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
        self::assertSame(['T'], self::paramNames($byName['Box']));
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
$y = Foo::method() + (new Box::<Plastic>())->x;
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

$x = new Box::<\Vendor\Foreign>();
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

$x = new \App\Containers\Box::<Plastic>();
PHP;
        $args = self::parseAndGetArgs($source, 'App\\Containers\\Box');
        self::assertCount(1, $args);
        self::assertSame('App\\Models\\Plastic', $args[0]->name);
    }

    public function testFullyQualifiedNamesInNestedGenericArg(): void
    {
        // `new Box<\Vendor\Collection<\Vendor\Plastic>>()` — nested generic where BOTH the
        // outer-arg template and the inner-arg type use the fully-qualified form.
        // Exercises the $isFq branch on line 218 (the recursive-into-generic return).
        $source = <<<'PHP'
<?php
namespace App;

$x = new Box::<\Vendor\Collection<\Vendor\Plastic>>();
PHP;
        $args = self::parseAndGetArgs($source, 'Box');
        self::assertCount(1, $args);
        self::assertTrue($args[0]->isGeneric());
        self::assertSame('Vendor\\Collection', $args[0]->name);
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

$x = new Box::<namespace\Plastic>();
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

$x = new Box::<Material>();
PHP;
        $args = self::parseAndGetArgs($source, 'Box');
        self::assertCount(1, $args);
        self::assertSame('Vendor\\Plastic', $args[0]->name, 'arg resolution must use the explicit alias key, not the last segment of the FQN');
    }

    // ===================================================================
    // Array-type sugar: Name[] (and chained Name[][]…) lowers to native `array`
    // ===================================================================

    public function testBareTypeParamArraySugarLowersToArray(): void
    {
        $source = <<<'PHP'
<?php
namespace App;

class Collection<T> {
    private T[] $items;
}
PHP;
        $printed = self::parseAndPrettyPrint($source);

        self::assertStringContainsString('private array $items', $printed);
        self::assertStringNotContainsString('T[]', $printed);
    }

    public function testArraySugarLowersInParameterAndReturnPositions(): void
    {
        $source = <<<'PHP'
<?php
class Collection<T> {
    public function set(T[] $items): void { $this->items = $items; }
    public function all(): T[] { return $this->items; }
}
PHP;
        $printed = self::parseAndPrettyPrint($source);

        self::assertStringContainsString('public function set(array $items): void', $printed);
        self::assertStringContainsString('public function all(): array', $printed);
    }

    public function testChainedBracketsAllLowerToSingleArray(): void
    {
        $source = <<<'PHP'
<?php
class C { private T[][] $matrix; private T[][][] $cube; }
PHP;
        $printed = self::parseAndPrettyPrint($source);

        self::assertStringContainsString('private array $matrix', $printed);
        self::assertStringContainsString('private array $cube', $printed);
        // Whole chain must collapse to a single `array`, not e.g. `array []`.
        self::assertStringNotContainsString('array[', $printed);
        self::assertStringNotContainsString('array [', $printed);
    }

    public function testArraySugarAcceptsConcreteClassNames(): void
    {
        // Sugar isn't limited to type parameters — `User[]` in any class also lowers.
        $source = <<<'PHP'
<?php
class UserList { private User[] $users; }
PHP;
        $printed = self::parseAndPrettyPrint($source);

        self::assertStringContainsString('private array $users', $printed);
    }

    public function testArraySugarAcceptsFullyQualifiedNames(): void
    {
        // The Name token can be T_NAME_FULLY_QUALIFIED, which my matcher must accept.
        $source = <<<'PHP'
<?php
class C { private \App\Models\Plastic[] $items; }
PHP;
        $printed = self::parseAndPrettyPrint($source);

        self::assertStringContainsString('private array $items', $printed);
    }

    public function testArraySugarAcceptsNamespaceQualifiedNames(): void
    {
        // T_NAME_QUALIFIED (`Models\Plastic`) must also be eligible for the sugar.
        $source = <<<'PHP'
<?php
class C { private Models\Plastic[] $items; }
PHP;
        $printed = self::parseAndPrettyPrint($source);

        self::assertStringContainsString('private array $items', $printed);
        self::assertStringNotContainsString('Models\\', $printed);
    }

    public function testArraySugarToleratesWhitespaceBetweenBrackets(): void
    {
        $source = <<<'PHP'
<?php
class C { private T [ ] $items; }
PHP;
        $printed = self::parseAndPrettyPrint($source);

        self::assertStringContainsString('private array $items', $printed);
    }

    public function testArrayIndexingIsNotMistakenForArraySugar(): void
    {
        // `$arr[0]`: the `[` is preceded by a T_VARIABLE, not a Name. Must not match.
        $source = <<<'PHP'
<?php
function f(array $arr): mixed { return $arr[0]; }
PHP;
        $printed = self::parseAndPrettyPrint($source);

        // The `[0]` indexing must survive the cleaning step intact.
        self::assertStringContainsString('return $arr[0]', $printed);
    }

    public function testLiteralEmptyArrayIsNotMistakenForArraySugar(): void
    {
        // `[]` as an array literal has nothing before it, so the matcher must not fire.
        $source = <<<'PHP'
<?php
$x = []; $y = [1, 2, 3];
PHP;
        $printed = self::parseAndPrettyPrint($source);

        self::assertStringContainsString('$x = []', $printed);
        self::assertStringContainsString('$y = [1, 2, 3]', $printed);
    }

    public function testNonEmptyBracketsAfterNameAreNotMistakenForArraySugar(): void
    {
        // `Foo::CONST_NAME` is followed by `::`, never `[`. But if a user wrote a Name
        // followed by `[<non-empty>]` (e.g. inside an attribute argument), my matcher
        // must reject the suffix and keep the source intact. This locks the requirement
        // that parseArraySuffix requires the brackets to be empty.
        $source = <<<'PHP'
<?php
class C { public const TYPES = [User::class => 1]; }
PHP;
        $printed = self::parseAndPrettyPrint($source);

        self::assertStringContainsString('User::class', $printed);
        self::assertStringContainsString('=> 1', $printed);
    }

    public function testArraySugarPreservesLineNumbersForLaterMarkers(): void
    {
        // A T[] on an early line must not shift the line numbers of a Box<…> marker
        // later in the file — the variable-length replacement happens on a single line
        // and the line counter must stay stable for marker → AST matching to work.
        $source = <<<'PHP'
<?php
namespace App;

class Wrapper<T> {
    private T[] $items;
    public Box<T> $b;
}
PHP;
        $args = self::parseAndGetArgs($source, 'Box');
        self::assertCount(1, $args);
        self::assertSame('T', $args[0]->name);
        self::assertTrue(
            $args[0]->isTypeParam,
            'Box<T> on the line AFTER T[] must still be picked up by the line-keyed marker resolver',
        );
    }

    public function testArraySugarInsideAndOutsideGenericClassesCoexist(): void
    {
        // Two adjacent classes: the first is generic and uses T[], the second is
        // plain and uses Foo[]. Both must lower; neither should affect the other.
        $source = <<<'PHP'
<?php
namespace App;

class A<T> { private T[] $a; }
class B { private Foo[] $b; }
PHP;
        $printed = self::parseAndPrettyPrint($source);

        self::assertStringContainsString('class A', $printed);
        self::assertStringContainsString('class B', $printed);
        self::assertStringContainsString('private array $a', $printed);
        self::assertStringContainsString('private array $b', $printed);
    }

    public function testArrayAppendOnPropertyIsNotMistakenForArraySugar(): void
    {
        // The hairy one: `$this->keys[] = $key` is array-append. `keys` is a T_STRING that
        // happens to be followed by `[]` — but it's a member access (preceded by `->`), not
        // a type-hint. The sugar must NOT fire here, otherwise the source rewrites to
        // `$this->array = $key` and the property write silently goes to the wrong slot.
        $source = <<<'PHP'
<?php
class Bag {
    private array $items = [];
    public function add(string $x): void { $this->items[] = $x; }
}
PHP;
        $printed = self::parseAndPrettyPrint($source);

        self::assertStringContainsString('$this->items[] = $x', $printed);
        self::assertStringNotContainsString('$this->array', $printed);
    }

    public function testArrayAppendThroughNullsafeIsNotMistakenForArraySugar(): void
    {
        // Same logic for `?->`. Locks the T_NULLSAFE_OBJECT_OPERATOR branch of the
        // member-access guard.
        $source = <<<'PHP'
<?php
class Bag {
    public function add(?self $other, string $x): void { $other?->items[] = $x; }
}
PHP;
        $printed = self::parseAndPrettyPrint($source);

        self::assertStringContainsString('$other?->items[] = $x', $printed);
    }

    public function testStaticPropertyAppendIsNotMistakenForArraySugar(): void
    {
        // And `::`. Locks the T_DOUBLE_COLON branch of the member-access guard.
        $source = <<<'PHP'
<?php
class Registry {
    public static array $entries = [];
    public static function register(string $entry): void { self::$entries[] = $entry; }
}
PHP;
        $printed = self::parseAndPrettyPrint($source);

        self::assertStringContainsString('self::$entries[] = $entry', $printed);
    }

    public function testMemberAccessGuardWalksBackPastCommentsAndWhitespace(): void
    {
        // PhpToken places a comment + extra whitespace between `->` and the property
        // name. The walk-back loop in isMemberAccessContext must keep skipping
        // T_COMMENT/T_DOC_COMMENT/T_WHITESPACE — not just one token — to land on the
        // operator. Locks the `while` (vs `if`) and `--` (vs `++`) mutations in the
        // walk-back, and the inner LogicalOr chain that lists which token kinds to skip.
        $source = <<<'PHP'
<?php
class Bag {
    public function add(string $x): void {
        $this  ->  /* note */  items[] = $x;
    }
}
PHP;
        $printed = self::parseAndPrettyPrint($source);

        self::assertStringContainsString('$this->items[] = $x', $printed);
        self::assertStringNotContainsString('$this->array', $printed);
    }

    public function testUnclosedBracketAfterNameLeavesSourceUnchanged(): void
    {
        // Defensive: a Name followed by `[` but never `]` must not trigger the sugar,
        // since `parseArraySuffix` requires a closing bracket. Without that guard a
        // mutation could quietly truncate source text and the parser would explode.
        $source = <<<'PHP'
<?php
function f(array $a) { return Foo::map($a, fn ($x) => $x + 1); }
PHP;
        $printed = self::parseAndPrettyPrint($source);

        self::assertStringContainsString('Foo::map', $printed);
        self::assertStringNotContainsString('array::', $printed);
    }

    // ===================================================================
    // Nullable type-param support — `?T` in any position must specialize
    // to `?<concrete>`. (This is already covered by the Specializer's
    // Name-substitution loop; the tests below lock the contract.)
    // ===================================================================

    public function testNullableTypeParamInReturnPositionIsPreservedThroughParse(): void
    {
        // The parser must leave `?T` alone (no array-sugar / generic-clause
        // false-match). Downstream substitution happens in the Specializer.
        $source = <<<'PHP'
<?php
class C<T> {
    public function first(): ?T { return null; }
}
PHP;
        $printed = self::parseAndPrettyPrint($source);

        self::assertStringContainsString('public function first(): ?T', $printed);
    }

    public function testGenericMethodScannerHandlesFunctionNameAtEndOfSource(): void
    {
        // Group B mutation regression: `$k < $n` -> `$k <= $n` boundary
        // checks at parser.php:182 (and the parallel inner LessThan /
        // LogicalAnd variants).  Source ends right after `function foo`
        // so the lookahead index for the `<` of a type-param list lands
        // at EXACTLY count($tokens).
        //
        // - Original: short-circuits on `$k < $n` -> false; skips block.
        // - Mutated `<=`: condition true, accesses $tokens[$n] (undef),
        //   PHP throws "Attempt to read property text on null".
        //
        // The tolerant entry-point must NOT throw a TypeError from our
        // scan layer.  Any process-killing crash is observable to
        // Infection as a "killed by error" outcome.
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $result = $parser->parseTolerantWithMap("<?php\nfunction foo");
        self::assertNotNull($result, 'tolerant parser must produce a result for truncated `function NAME` input');
    }

    public function testGenericClassScannerHandlesClassNameAtEndOfSource(): void
    {
        // Group B mutation regression for the class-scanning twin of
        // the above: `$k < $n` at parser.php:210 + the inner
        // LessThan/LogicalAnd mirrors.  Source ends right after
        // `class Foo`; the lookahead for `<` lands at the array end.
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $result = $parser->parseTolerantWithMap("<?php\nclass Foo");
        self::assertNotNull($result, 'tolerant parser must produce a result for truncated `class NAME` input');
    }

    public function testGenericInstantiationScannerHandlesIdentifierAtEndOfSource(): void
    {
        // Group B mutation regression for the `parseTypeArgList` /
        // member-access scanner (parser.php:244, 366, 372, 381, 388,
        // 406, 415).  Source ends right after the identifier that
        // could-be-a-generic-instantiation; the lookahead for `<`
        // lands at the boundary.
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $result = $parser->parseTolerantWithMap("<?php\nidentity");
        self::assertNotNull($result, 'tolerant parser must produce a result for truncated identifier-at-EOF');
    }

    public function testParseTolerantWithMapAttachesGenericParamAttributesToAst(): void
    {
        // Mutation regression: `$this->resolveAndAttach(...)` call dropped
        // at parser.php:134.  Without it, the tolerant-parse path returns
        // an AST whose ClassLike nodes are MISSING the xphp attributes
        // (`ATTR_GENERIC_PARAMS`, `ATTR_TEMPLATE_FQN`) that the LSP
        // monomorphization-aware paths depend on.
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $result = $parser->parseTolerantWithMap("<?php\nnamespace App;\nclass Box<T> { public T \$item; }\n");

        self::assertNotNull($result);
        $class = self::findFirstClass($result->ast);
        self::assertNotNull($class, 'tolerant parse must surface the class');
        self::assertSame('Box', $class->name?->toString());

        // The attributes are what `resolveAndAttach` exists to produce.
        $params = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertIsArray($params, 'ATTR_GENERIC_PARAMS must be attached by resolveAndAttach');
        self::assertCount(1, $params);
        self::assertSame('T', $params[0]->name);
        self::assertSame(
            'App\\Box',
            $class->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN),
        );
    }

    public function testParseTypeParamListHandlesAngleBracketAtEndOfSource(): void
    {
        // Group B mutation regression: `$openIdx >= $n` LogicalOr +
        // GreaterThanOrEqualTo guards in parseTypeParamList / parseTypeArgList
        // (parser.php:306, 324, 366).  Source ends right after the `<` of
        // a generic clause; parseTypeParamList enters at the boundary
        // openIdx == count(tokens) - 1 and the inner skipWs / next-token
        // checks all land at $n.
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $result = $parser->parseTolerantWithMap("<?php\nclass Box<");
        self::assertNotNull($result, 'tolerant parser must produce a result when a `<` is the last token');
    }

    public function testParseWithMapIsCallableFromOutsideTheClass(): void
    {
        // Mutation regression: `public function parseWithMap` -> `protected`.
        //
        // External packages -- notably the LSP analyzer at
        // tools/lsp/src/Analyzer/Analyzer.php -- depend on `parseWithMap`
        // being callable from outside the class (they need both the AST
        // and the ByteOffsetMap for stripped-to-original position
        // translation).  If the visibility ever drops to `protected`,
        // those callers break with a fatal "cannot access protected
        // method" -- but the breakage only surfaces in the LSP package,
        // not in core's `parse()` tests (which call from inside the
        // class hierarchy).  This test pins the contract from inside
        // core so refactors get caught at the right layer.
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        [$ast, $byteOffsetMap] = $parser->parseWithMap("<?php\nclass Foo {}\n");

        self::assertIsArray($ast);
        self::assertInstanceOf(ByteOffsetMap::class, $byteOffsetMap);
    }

    // ===================================================================
    // RFC bound_erased_generic_types: `::<…>` turbofish at call/`new` sites,
    // bare `<…>` at type-hint sites only.
    // ===================================================================

    public function testTurbofishOnFreeFunctionCallIsRecognized(): void
    {
        $source = <<<'PHP'
<?php
namespace App;

$x = identity::<int>(42);
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());

        // Cleaned source must drop both `::` and `<int>` so PHP sees `identity(42)`.
        $stripped = $parser->strip($source);
        self::assertStringNotContainsString('::<', $stripped);
        self::assertStringNotContainsString('<int>', $stripped);
        self::assertStringContainsString('identity', $stripped);

        // And the resolver attaches the type-args to the FuncCall node.
        $ast = $parser->parse($source);
        $funcCall = self::findFirstNodeOfType($ast, Node\Expr\FuncCall::class);
        self::assertNotNull($funcCall);
        $args = $funcCall->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS);
        self::assertIsArray($args);
        self::assertCount(1, $args);
        self::assertSame('int', $args[0]->name);
        self::assertTrue($args[0]->isScalar);
    }

    public function testTurbofishOnStaticMethodCallIsRecognized(): void
    {
        $source = <<<'PHP'
<?php
$x = Util::identity::<int>(42);
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());

        $stripped = $parser->strip($source);
        self::assertStringNotContainsString('::<', $stripped);
        self::assertStringNotContainsString('<int>', $stripped);
        self::assertStringContainsString('Util::identity', $stripped);

        $ast = $parser->parse($source);
        $call = self::findFirstNodeOfType($ast, Node\Expr\StaticCall::class);
        self::assertNotNull($call);
        $args = $call->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS);
        self::assertIsArray($args);
        self::assertCount(1, $args);
        self::assertSame('int', $args[0]->name);
    }

    public function testTurbofishOnSelfConstructorIsStrippedWithoutMarker(): void
    {
        // `new self::<T>(...)` -- pseudo-type at constructor position. Same
        // shape as the bare `self<T>` filter above: strip the `::<T>` clause
        // so nikic parses `new self(...)`, but DON'T record a marker.
        // Otherwise the resolver would attach ATTR_TEMPLATE_FQN = `App\…\self`
        // and the Registry would fail looking up the non-existent template.
        $source = <<<'PHP'
<?php
namespace App;

class Container<T> {
    public T $item;
    public function __construct(T $item) {
        $this->item = $item;
    }
    public function with(T $newItem): self {
        return new self::<T>($newItem);
    }
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());

        $stripped = $parser->strip($source);
        self::assertStringNotContainsString('::<T>', $stripped);
        self::assertStringNotContainsString('self<T>', $stripped);
        self::assertStringContainsString('new self', $stripped);

        $ast = $parser->parse($source);
        self::assertNull(
            self::firstNameAttr($ast, 'self', XphpSourceParser::ATTR_GENERIC_ARGS),
            'new self::<T>() must not attach generic-args marker; pseudo-types are class refs',
        );
    }

    public function testTurbofishOnParentConstructorIsStrippedWithoutMarker(): void
    {
        // `new parent::<T>(...)` -- pseudo-type at constructor position.
        $source = <<<'PHP'
<?php
namespace App;

class Child<T> extends Parent_ {
    public function clone(T $v): self {
        return new parent::<T>($v);
    }
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());

        $stripped = $parser->strip($source);
        self::assertStringNotContainsString('::<T>', $stripped);
        self::assertStringContainsString('new parent', $stripped);

        $ast = $parser->parse($source);
        self::assertNull(
            self::firstNameAttr($ast, 'parent', XphpSourceParser::ATTR_GENERIC_ARGS),
            'new parent::<T>() must not attach generic-args marker',
        );
    }

    public function testTurbofishOnStaticConstructorIsStrippedWithoutMarker(): void
    {
        // `new static::<T>(...)` -- late-static-bound pseudo-type at constructor
        // position. PHP resolves `static` against the currently-executing class
        // at runtime, which after monomorphization is the specialized class.
        $source = <<<'PHP'
<?php
namespace App;

class Builder<T> {
    public function fresh(T $v): static {
        return new static::<T>($v);
    }
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());

        $stripped = $parser->strip($source);
        self::assertStringNotContainsString('::<T>', $stripped);
        self::assertStringContainsString('new static', $stripped);

        $ast = $parser->parse($source);
        self::assertNull(
            self::firstNameAttr($ast, 'static', XphpSourceParser::ATTR_GENERIC_ARGS),
            'new static::<T>() must not attach generic-args marker',
        );
    }

    public function testTurbofishOnMixedCaseSelfIsStrippedWithoutMarker(): void
    {
        // `self` / `parent` / `static` are case-insensitive PHP keywords --
        // `new SELF::<T>()` parses the same as `new self::<T>()`. The
        // pseudo-type filter normalizes via strtolower; this test pins
        // that behavior so the strtolower call can't be silently mutated
        // away (and a future contributor can't forget the case insensitivity).
        $source = <<<'PHP'
<?php
namespace App;

class Container<T> {
    public T $item;
    public function __construct(T $item) {
        $this->item = $item;
    }
    public function with(T $newItem): self {
        return new SELF::<T>($newItem);
    }
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());

        $stripped = $parser->strip($source);
        self::assertStringNotContainsString('::<T>', $stripped);
        self::assertStringContainsString('new SELF', $stripped);

        $ast = $parser->parse($source);
        self::assertNull(
            self::firstNameAttr($ast, 'SELF', XphpSourceParser::ATTR_GENERIC_ARGS),
            'mixed-case new SELF::<T>() must not attach generic-args marker',
        );
        self::assertNull(
            self::firstNameAttr($ast, 'self', XphpSourceParser::ATTR_GENERIC_ARGS),
        );
    }

    public function testTurbofishOnSelfStaticMethodCallStripsAndAttachesMarkerToMethod(): void
    {
        // Regression guard for the existing `self::method::<T>()` path: the
        // marker is attached to `method`, not to `self`. The pseudo-type
        // filter on the turbofish branch must NOT swallow this marker --
        // it only fires on the LEADING name, and here `self` is followed by
        // `::method::<...>` not `::<...>`.
        $source = <<<'PHP'
<?php
namespace App;

class Util<T> {
    public static function make(T $x): T { return $x; }
    public function call(T $x): T {
        return self::make::<T>($x);
    }
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());

        $ast = $parser->parse($source);
        $call = self::findFirstNodeOfType($ast, Node\Expr\StaticCall::class);
        self::assertNotNull($call);
        $args = $call->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS);
        self::assertIsArray($args);
        self::assertCount(1, $args);
        self::assertSame('T', $args[0]->name);

        // And the bare `self` Name receives no generic-args marker.
        self::assertNull(
            self::firstNameAttr($ast, 'self', XphpSourceParser::ATTR_GENERIC_ARGS),
        );
    }

    public function testTurbofishOnInstanceMethodCallIsRecognized(): void
    {
        // Instance-method turbofish (`$obj->method::<T>(...)`) -- the resolver
        // now claims the marker and attaches it to the MethodCall node, alongside
        // the scanner's strip. GenericMethodCompiler does receiver-type analysis
        // to pick the right method template at specialization time.
        $source = <<<'PHP'
<?php
$result = $obj->map::<string>($fn);
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());

        $stripped = $parser->strip($source);
        self::assertStringNotContainsString('::<', $stripped);
        self::assertStringNotContainsString('<string>', $stripped);
        self::assertStringContainsString('$obj->map', $stripped);

        $ast = $parser->parse($source);
        $call = self::findFirstNodeOfType($ast, Node\Expr\MethodCall::class);
        self::assertNotNull($call);
        $args = $call->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS);
        self::assertIsArray($args);
        self::assertCount(1, $args);
        self::assertSame('string', $args[0]->name);
        self::assertTrue($args[0]->isScalar);
    }

    public function testTurbofishOnNullsafeInstanceMethodCallIsRecognized(): void
    {
        $source = <<<'PHP'
<?php
$result = $obj?->map::<string>($fn);
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());

        $stripped = $parser->strip($source);
        self::assertStringNotContainsString('::<', $stripped);
        self::assertStringNotContainsString('<string>', $stripped);
        self::assertStringContainsString('$obj?->map', $stripped);

        $ast = $parser->parse($source);
        $call = self::findFirstNodeOfType($ast, Node\Expr\NullsafeMethodCall::class);
        self::assertNotNull($call);
        $args = $call->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS);
        self::assertIsArray($args);
        self::assertCount(1, $args);
        self::assertSame('string', $args[0]->name);
    }

    public function testBareNewCallSiteIsRejectedAndLeftUnstripped(): void
    {
        // Bare `new Box<Plastic>()` at expression context is not RFC-compliant;
        // the scanner must NOT strip the `<…>` so downstream PHP surfaces the
        // syntax error rather than xphp silently specializing the call.
        $source = <<<'PHP'
<?php
namespace App;

$x = new Box<Plastic>();
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $stripped = $parser->strip($source);

        self::assertStringContainsString('<Plastic>', $stripped, 'bare `new Name<…>()` must be left un-stripped');
    }

    public function testBareNewWithoutParensIsRejectedAndLeftUnstripped(): void
    {
        // PHP allows `new Foo;` (no parens). Without the `isPrecededByNew`
        // lookback, the bare-`<…>` rejection only caught the parens-bearing
        // form (`>` followed by `(`), so this slipped through and xphp
        // silently specialized a call shape the RFC turbofish requirement
        // would refuse.
        $source = <<<'PHP'
<?php
namespace App;

$x = new Box<Plastic>;
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $stripped = $parser->strip($source);

        self::assertStringContainsString('<Plastic>', $stripped, 'parenless `new Name<…>` must be left un-stripped');
    }

    public function testBareFreeFunctionCallIsRejectedAndLeftUnstripped(): void
    {
        $source = <<<'PHP'
<?php
$x = identity<int>(42);
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $stripped = $parser->strip($source);

        self::assertStringContainsString('<int>', $stripped, 'bare `name<…>()` free-function call must be left un-stripped');
    }

    public function testBareStaticMethodCallIsRejectedAndLeftUnstripped(): void
    {
        $source = <<<'PHP'
<?php
$x = Util::identity<int>(42);
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $stripped = $parser->strip($source);

        self::assertStringContainsString('<int>', $stripped, 'bare `Recv::method<…>()` static call must be left un-stripped');
    }

    public function testWhitespaceBetweenDoubleColonAndAngleDefeatsTurbofish(): void
    {
        // The RFC turbofish is whitespace-sensitive: `Foo:: <T>` is `Foo::`
        // (an incomplete static reference) followed by `<T>` (comparison) --
        // not a turbofish. The scanner enforces this by requiring the `<`
        // token's byte offset to sit at `::pos + 2` (no gap).
        $source = <<<'PHP'
<?php
$x = identity:: <int>(42);
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $stripped = $parser->strip($source);

        self::assertStringContainsString('<int>', $stripped, 'whitespace between `::` and `<` must defeat turbofish recognition');
    }

    public function testTypeHintPositionAcceptsFullyQualifiedOuterName(): void
    {
        // A fully-qualified outer Name (`\App\Containers\Box<Plastic>`) in a
        // type-hint slot: the marker records the raw source spelling (leading
        // `\` kept) and the resolver compares the node's `toCodeString()`, so
        // the two agree and the generic args attach.
        $source = <<<'PHP'
<?php
namespace App;

use App\Models\Plastic;

class Holder {
    public \App\Containers\Box<Plastic> $b;
}
PHP;
        $args = self::parseAndGetArgs($source, 'App\\Containers\\Box');
        self::assertCount(1, $args);
        self::assertSame('App\\Models\\Plastic', $args[0]->name);
    }

    public function testFullyQualifiedTurbofishResolvesWithoutDoublingTheNamespace(): void
    {
        // `new \App\Box::<int>` used to record ATTR_TEMPLATE_FQN = `App\App\Box`
        // (the leading `\` was trimmed before namespace resolution), which
        // hard-failed as an undefined template.
        $source = <<<'PHP'
<?php
namespace App;
class Box<T> { public function __construct(public T $v) {} }
$b = new \App\Box::<int>(1);
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $finder = new \PhpParser\NodeFinder();
        $fq = null;
        foreach ($finder->findInstanceOf($ast, Name::class) as $n) {
            if ($n->isFullyQualified()) {
                $fq = $n;
            }
        }
        self::assertNotNull($fq);
        self::assertSame('App\\Box', $fq->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN));
        $args = $fq->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
        self::assertIsArray($args);
        self::assertSame('int', $args[0]->name);
    }

    public function testSameLineRelativeReturnAndBareTurbofishKeepTheirOwnMarkers(): void
    {
        // On ONE line, the relative return type's marker and the body
        // turbofish's marker both spell a bare `Box` after toString() — the
        // return-type node used to steal the body's marker (specializing the
        // wrong site) while its own `namespace\Box` marker never bound.
        $source = <<<'PHP'
<?php
namespace App;
class Box<T> { public function __construct(public T $v) {} }
function f(): namespace\Box<int> { return new Box::<string>('s'); }
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $finder = new \PhpParser\NodeFinder();
        $relative = null;
        $bare = null;
        foreach ($finder->findInstanceOf($ast, Name::class) as $n) {
            if ($n->isRelative()) {
                $relative = $n;
            } elseif (!$n->isFullyQualified() && $n->toString() === 'Box'
                && $n->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS) !== null
            ) {
                $bare = $n;
            }
        }

        self::assertNotNull($relative, 'relative return-type Name missing');
        self::assertSame('App\\Box', $relative->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN));
        $relArgs = $relative->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
        self::assertIsArray($relArgs);
        self::assertSame('int', $relArgs[0]->name, 'the return type must keep its OWN args');

        self::assertNotNull($bare, 'body turbofish Name missing its args');
        $bareArgs = $bare->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
        self::assertIsArray($bareArgs);
        self::assertSame('string', $bareArgs[0]->name, 'the body turbofish must keep its OWN args');
    }

    public function testUppercaseRelativePrefixStillMatchesItsMarker(): void
    {
        // The `namespace` keyword is case-insensitive in source, but
        // `toCodeString()` always emits it lowercase — the marker spelling
        // must fold the prefix or `NAMESPACE\Box::<int>` silently loses
        // its marker.
        $source = <<<'PHP'
<?php
namespace App;
class Box<T> { public function __construct(public T $v) {} }
$b = new NAMESPACE\Box::<int>(1);
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $finder = new \PhpParser\NodeFinder();
        $relative = null;
        foreach ($finder->findInstanceOf($ast, Name::class) as $n) {
            if ($n->isRelative()) {
                $relative = $n;
            }
        }
        self::assertNotNull($relative);
        self::assertSame('App\\Box', $relative->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN));
        $args = $relative->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
        self::assertIsArray($args);
        self::assertSame('int', $args[0]->name);
    }

    public function testRelativeNamesResolveToTheCurrentNamespaceNotTheAliasMap(): void
    {
        // `namespace\Thing` binds to App\Thing even with `use Other\Thing` in
        // scope, and — being an explicit class reference — is never flagged as
        // a suspect undeclared type parameter.
        $source = <<<'PHP'
<?php
namespace App;

use Other\Thing;

class Gen<T> {
    public function m(namespace\Thing $x): void {}
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $finder = new \PhpParser\NodeFinder();
        $relative = null;
        foreach ($finder->findInstanceOf($ast, Name::class) as $n) {
            if ($n->isRelative()) {
                $relative = $n;
            }
        }
        self::assertNotNull($relative);
        self::assertSame(
            'App\\Thing',
            $relative->getAttribute(XphpSourceParser::ATTR_RESOLVED_FQN),
            'the alias must not capture a relative name',
        );
        self::assertNull(
            $relative->getAttribute(XphpSourceParser::ATTR_SUSPECT_UNDECLARED_TYPE),
            'a relative name is an explicit class reference, never a suspect type param',
        );
    }

    public function testRelativeNameCollidingWithATypeParamIsStillAClassReference(): void
    {
        // Inside `Gen<T>`, `namespace\T` spells the CLASS App\T — it must get
        // a resolved FQN (so the specializer swaps it to `\App\T`), never be
        // treated as the type parameter.
        $source = <<<'PHP'
<?php
namespace App;

class Gen<T> {
    public function m(namespace\T $x): void {}
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $finder = new \PhpParser\NodeFinder();
        $relative = null;
        foreach ($finder->findInstanceOf($ast, Name::class) as $n) {
            if ($n->isRelative()) {
                $relative = $n;
            }
        }
        self::assertNotNull($relative);
        self::assertSame('App\\T', $relative->getAttribute(XphpSourceParser::ATTR_RESOLVED_FQN));
        self::assertNull(
            $relative->getAttribute(XphpSourceParser::ATTR_SUSPECT_UNDECLARED_TYPE),
            'an explicit relative reference must not be flagged as a suspect type param'
            . ' — here there is no alias, so only the relative exclusion protects it',
        );
    }

    public function testQualifiedFunctionTurbofishesResolveAndAttach(): void
    {
        // `\App\make::<int>(…)` used to double the namespace in
        // ATTR_TEMPLATE_FQN (stripping the generic function with no dispatcher
        // emitted — a silent undefined-function fatal); `namespace\make::<…>`
        // never matched its marker at all and false-rejected as a
        // missing-type-argument call.
        $source = <<<'PHP'
<?php
namespace App;
function make<T>(T $x): T { return $x; }
$a = \App\make::<int>(1);
$b = namespace\make::<string>('s');
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $finder = new \PhpParser\NodeFinder();
        $calls = $finder->findInstanceOf($ast, Node\Expr\FuncCall::class);
        self::assertCount(2, $calls);

        $fqCall = $calls[0];
        self::assertSame('App\\make', $fqCall->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN));
        $fqArgs = $fqCall->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS);
        self::assertIsArray($fqArgs);
        self::assertSame('int', $fqArgs[0]->name);

        $relCall = $calls[1];
        self::assertSame('App\\make', $relCall->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN));
        $relArgs = $relCall->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS);
        self::assertIsArray($relArgs);
        self::assertSame('string', $relArgs[0]->name);
    }

    public function testTypeHintPositionStillAcceptsBareGenericArgs(): void
    {
        // Regression: type-hint sites (property type, return type, params,
        // `extends` / `implements`) keep bare `<…>`. The trailing-`(`
        // heuristic that rejects call-site bare-`<…>` must not misfire here.
        $source = <<<'PHP'
<?php
namespace App;

class Holder {
    public Box<Plastic> $b;
    public function get(): Box<Plastic> { return $this->b; }
    public function set(Box<Plastic> $b): void { $this->b = $b; }
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $stripped = $parser->strip($source);

        // All three `<Plastic>` clauses (property, return, param) are at
        // type-hint sites and must be stripped.
        self::assertStringNotContainsString('<Plastic>', $stripped);
        // And neither original `Box` token should have leaked into a turbofish form.
        self::assertStringNotContainsString('::<', $stripped);
    }

    public function testDefaultTypeParamIsAccepted(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
class Box<T = string>
{
    public T $item;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);
        $class = self::findFirstClass($ast);
        $params = $class?->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertIsArray($params);
        self::assertCount(1, $params);
        $default = $params[0]->default;
        self::assertNotNull($default);
        self::assertSame('string', $default->name);
        self::assertTrue($default->isScalar);
    }

    public function testDefaultAfterBoundIsAccepted(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
class Box<T : \Stringable = \App\MyStringable>
{
    public T $item;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);
        $class = self::findFirstClass($ast);
        $params = $class?->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertNotNull($params[0]->bound);
        self::assertSame('App\\MyStringable', $params[0]->default?->name);
    }

    public function testDefaultCanReferenceEarlierParam(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
class Pair<A, B = A>
{
    public A $first;
    public B $second;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);
        $class = self::findFirstClass($ast);
        $params = $class?->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertSame('A', $params[1]->default?->name);
        self::assertTrue($params[1]->default?->isTypeParam);
    }

    public function testDefaultCanReferenceEarlierParamInsideGenericArgs(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
class Wrapper<A, B = Box<A>>
{
    public B $inner;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);
        $class = self::findFirstClass($ast);
        $params = $class?->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        $default = $params[1]->default;
        self::assertSame('App\\Box', $default?->name);
        self::assertSame('A', $default?->args[0]->name);
        self::assertTrue($default?->args[0]->isTypeParam);
    }

    public function testRequiredAfterDefaultIsRejected(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
class Bad<T = int, U>
{
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Required type parameters must precede defaulted ones');
        $parser->parse($source);
    }

    public function testDefaultCannotReferenceSelf(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
class Bad<T = T>
{
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot reference itself in its default');
        $parser->parse($source);
    }

    public function testDefaultCannotReferenceLaterParam(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
class Bad<T = U, U = int>
{
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('declared later in the same parameter list');
        $parser->parse($source);
    }

    public function testDefaultMayUseFullyQualifiedSameNameAsParam(): void
    {
        // `\T` is the global class named T, NOT the type-param T -- the FQ form
        // unambiguously refers to a class, so the guard does not fire.
        $source = <<<'PHP'
<?php
namespace App;
class Box<T = \T>
{
    public T $item;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        // Must not throw.
        $parser->parse($source);
        self::assertTrue(true);
    }

    public function testMethodLevelDefaultIsAcceptedAndStored(): void
    {
        // Method-level defaults now ship. The marker carries the default
        // through to the resolver's TypeParam construction.
        $source = <<<'PHP'
<?php
namespace App;
class C
{
    public function id<T = string>(T $x): T { return $x; }
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);
        $class = self::findFirstClass($ast);
        $method = $class?->getMethods()[0] ?? null;
        self::assertNotNull($method);
        $params = $method->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
        self::assertIsArray($params);
        self::assertSame('string', $params[0]->default?->name);
    }

    public function testFreeFunctionLevelDefaultIsAcceptedAndStored(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
function id<T = string>(T $x): T { return $x; }
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);
        $fn = self::findFirstNodeOfType($ast, \PhpParser\Node\Stmt\Function_::class);
        $params = $fn?->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
        self::assertIsArray($params);
        self::assertSame('string', $params[0]->default?->name);
    }

    public function testNullableDefaultIsRejectedWithClearError(): void
    {
        // PHP's `?Type` nullable shape is intentionally not allowed as a default;
        // a nullable default is parsed by `parseTypeArg` failing on the `?` token,
        // which surfaces as the "invalid default" error.
        $source = <<<'PHP'
<?php
namespace App;
class Bad<T = ?int>
{
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid default');
        $this->expectExceptionMessage('no nullable or union shapes');
        $parser->parse($source);
    }

    public function testUnionDefaultIsRejectedWithClearError(): void
    {
        // PHP's union shape `A|B` is intentionally not allowed as a default --
        // defaults must be a single concrete or generic type. The parser detects
        // the trailing `|` after the first leaf and throws the consistent
        // "invalid default" error.
        $source = <<<'PHP'
<?php
namespace App;
class Bad<T = Box|Other>
{
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid default');
        $this->expectExceptionMessage('no nullable or union shapes');
        $parser->parse($source);
    }

    public function testIntersectionDefaultIsRejectedWithClearError(): void
    {
        // `A & B` (intersection) -- same rejection family as the union case.
        $source = <<<'PHP'
<?php
namespace App;
class Bad<T = Box & Other>
{
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid default');
        $this->expectExceptionMessage('no nullable or union shapes');
        $parser->parse($source);
    }

    public function testDefaultForwardRefGuardWalksIntoNestedGenericArgs(): void
    {
        // `class Bad<A = Box<U>, U = string>` -- A's default references U via
        // a nested generic arg. The trailing-default rule passes (both have
        // defaults), so the forward-ref guard must descend into TypeRef::args
        // to catch the violation.
        $source = <<<'PHP'
<?php
namespace App;
class Bad<A = Box<U>, U = string>
{
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('declared later in the same parameter list');
        $parser->parse($source);
    }

    public function testCycleBetweenDefaultsIsRejectedAtForwardRef(): void
    {
        // `<A = B, B = A>` -- A's default references B (declared later, illegal).
        // The forward-ref guard catches this on A first; B's own default (= A,
        // earlier) is fine but is never reached because A fails first.
        $source = <<<'PHP'
<?php
namespace App;
class Bad<A = B, B = A>
{
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('declared later in the same parameter list');
        $parser->parse($source);
    }

    public function testCovariantTypeParamIsParsedAndStored(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
class Producer<out T>
{
    public function get(): T { throw new \LogicException; }
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);
        $class = self::findFirstClass($ast);
        $params = $class?->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertIsArray($params);
        self::assertSame(Variance::Covariant, $params[0]->variance);
    }

    public function testContravariantTypeParamIsParsedAndStored(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
class Consumer<in T>
{
    public function set(T $x): void {}
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);
        $class = self::findFirstClass($ast);
        $params = $class?->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertSame(Variance::Contravariant, $params[0]->variance);
    }

    public function testInvariantTypeParamRemainsTheDefault(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
class Box<T> { public T $item; }
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);
        $class = self::findFirstClass($ast);
        $params = $class?->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertSame(Variance::Invariant, $params[0]->variance);
    }

    public function testMixedVarianceTypeParamsAreParsed(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
interface Iter<K, out V>
{
    public function key(): K;
    public function current(): V;
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);
        $interface = self::findFirstClassLike($ast, \PhpParser\Node\Stmt\Interface_::class);
        $params = $interface?->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertSame(Variance::Invariant, $params[0]->variance);
        self::assertSame(Variance::Covariant, $params[1]->variance);
    }

    public function testMethodLevelVarianceIsRejected(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
class C
{
    public function id<out T>(T $x): T { return $x; }
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Variance markers `out T` / `in T` are not supported on methods, functions, closures, or arrow functions');
        $parser->parse($source);
    }

    public function testFreeFunctionVarianceIsRejected(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
function id<in T>(T $x): T { return $x; }
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Variance markers');
        $this->expectExceptionMessage('methods, functions, closures, or arrow functions');
        $parser->parse($source);
    }

    public function testOutMarkerParsesAsCovariant(): void
    {
        $class = self::parseSingleClass(<<<'PHP'
<?php
namespace App;
class Producer<out T> {}
PHP);
        $params = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertSame('T', $params[0]->name);
        self::assertSame(Variance::Covariant, $params[0]->variance);
    }

    public function testInMarkerParsesAsContravariant(): void
    {
        $class = self::parseSingleClass(<<<'PHP'
<?php
namespace App;
class Consumer<in T> {}
PHP);
        $params = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertSame('T', $params[0]->name);
        self::assertSame(Variance::Contravariant, $params[0]->variance);
    }

    public function testMultiParamVarianceMarkersParseIndependently(): void
    {
        // Both entries carry a marker; the second is comma-preceded — guards the
        // migration blind spot where only the leading marker gets recognized.
        $class = self::parseSingleClass(<<<'PHP'
<?php
namespace App;
class Couple<out A, in B> {}
PHP);
        $params = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertSame(['A', 'B'], array_map(static fn (TypeParam $p): string => $p->name, $params));
        self::assertSame(Variance::Covariant, $params[0]->variance);
        self::assertSame(Variance::Contravariant, $params[1]->variance);
    }

    public function testBoundedCovariantMarkerParses(): void
    {
        $class = self::parseSingleClass(<<<'PHP'
<?php
namespace App;
class Cache<out K : \Stringable> {}
PHP);
        $params = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertSame('K', $params[0]->name);
        self::assertSame(Variance::Covariant, $params[0]->variance);
        self::assertNotNull($params[0]->bound);
    }

    public function testOldPlusMarkerIsRejectedWithMigrationHint(): void
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The `+T` / `-T` variance syntax was replaced by `out T` / `in T`');
        $parser->parse("<?php\nnamespace App;\nclass Box<+T> {}\n");
    }

    public function testOldMinusMarkerIsRejectedWithMigrationHint(): void
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('was replaced by `out T` / `in T`');
        $parser->parse("<?php\nnamespace App;\nclass Box<-T> {}\n");
    }

    public function testOutReservedAsTypeParamNameIsRejected(): void
    {
        // No name follows, so `out` lands in the name slot and the reserve check fires.
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('`out` and `in` are variance markers and cannot name a type parameter');
        $parser->parse("<?php\nnamespace App;\nclass Box<out> {}\n");
    }

    public function testOutFollowedByOutIsRejectedAtNameSlot(): void
    {
        // Marker `out` consumed, then `out` again as the name — the name-slot
        // reserve closes the hole a marker-only lookahead would have accepted.
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot name a type parameter');
        $parser->parse("<?php\nnamespace App;\nclass Box<out out> {}\n");
    }

    public function testReservedInAmongMultipleParamsIsRejected(): void
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot name a type parameter');
        $parser->parse("<?php\nnamespace App;\nclass Pair<in, out> {}\n");
    }

    public function testReservedInWithBoundIsRejected(): void
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot name a type parameter');
        $parser->parse("<?php\nnamespace App;\nclass Box<in : \\Stringable> {}\n");
    }

    public function testNamesStartingWithOutOrInAreValidInvariantParams(): void
    {
        // Only the exact lowercase tokens `out`/`in` are reserved; names that
        // merely start with them stay ordinary invariant parameters.
        $class = self::parseSingleClass(<<<'PHP'
<?php
namespace App;
class Bag<outer, Input, outT> {}
PHP);
        $params = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertSame(['outer', 'Input', 'outT'], array_map(static fn (TypeParam $p): string => $p->name, $params));
        foreach ($params as $p) {
            self::assertSame(Variance::Invariant, $p->variance);
        }
    }

    private static function parseSingleClass(string $source): Class_
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $class = self::findFirstClass($parser->parse($source));
        self::assertNotNull($class);

        return $class;
    }

    public function testContravariantInInputPositionIsAccepted(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
class Consumer<in T>
{
    public function set(T $x): void {}
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $parser->parse($source);
        self::assertTrue(true);
    }

    public function testInvariantTypeParamAcceptsBothPositions(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
class Box<T>
{
    public T $item;
    public function get(): T { return $this->item; }
    public function set(T $x): void { $this->item = $x; }
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $parser->parse($source);
        self::assertTrue(true);
    }

    public function testGenericClosureWithoutUseHoistsAndSpecializes(): void
    {
        // Variable-turbofish call on a capture-free generic closure: GMC
        // hoists the body to a top-level Function_ and rewrites the call
        // site to invoke the hoisted function.
        $workDir = sys_get_temp_dir() . '/xphp-closure-' . uniqid('', true);
        mkdir($workDir, 0o755, true);
        $sourceDir = $workDir . '/src';
        mkdir($sourceDir, 0o755, true);
        file_put_contents($sourceDir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $pair = function<K, V>(K $key, V $value): array {
            return [$key, $value];
        };
        $pair::<string, int>('age', 42);
        PHP);

        $compiler = new Compiler(
            new \XPHP\FileSystem\FileReader\NativeFileReader(),
            new \XPHP\FileSystem\FileWriter\NativeFileWriter(),
            new XphpSourceParser((new ParserFactory())->createForHostVersion()),
            new Specializer(),
            new SpecializedClassGenerator(
                new \PhpParser\PrettyPrinter\Standard(),
                new \XPHP\FileSystem\FileWriter\NativeFileWriter(),
            ),
            new \PhpParser\PrettyPrinter\Standard(),
        );
        $sources = (new \XPHP\FileSystem\FileFinder\NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $sourceDir, $workDir . '/dist', $workDir . '/.xphp-cache');

        $rewritten = file_get_contents($workDir . '/dist/Use.php');
        // The call site was rewritten to the hoisted function name.
        self::assertStringContainsString('closure_pair_T_', $rewritten);
        // The original `$pair(...)` call form is gone.
        self::assertStringNotContainsString("\$pair('age', 42)", $rewritten);

        self::rrmdir($workDir);
    }

    public function testGenericArrowFunctionSpecializesViaDispatcher(): void
    {
        // P5.5: generic arrow functions now specialize end-to-end via the
        // P5.4 dispatcher. The arrow's implicit captures (none here) are
        // synthesized as a `use (...)` clause on the dispatcher closure.
        $workDir = sys_get_temp_dir() . '/xphp-arrow-' . uniqid('', true);
        mkdir($workDir, 0o755, true);
        $sourceDir = $workDir . '/src';
        mkdir($sourceDir, 0o755, true);
        file_put_contents($sourceDir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $id = fn<T>(T $x): T => $x;
        $id::<int>(42);
        PHP);

        $compiler = new Compiler(
            new \XPHP\FileSystem\FileReader\NativeFileReader(),
            new \XPHP\FileSystem\FileWriter\NativeFileWriter(),
            new XphpSourceParser((new ParserFactory())->createForHostVersion()),
            new Specializer(),
            new SpecializedClassGenerator(
                new \PhpParser\PrettyPrinter\Standard(),
                new \XPHP\FileSystem\FileWriter\NativeFileWriter(),
            ),
            new \PhpParser\PrettyPrinter\Standard(),
        );
        $sources = (new \XPHP\FileSystem\FileFinder\NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $compiler->compile($sources, $sourceDir, $workDir . '/dist', $workDir . '/.xphp-cache');

        $rewritten = file_get_contents($workDir . '/dist/Use.php');
        self::assertStringContainsString('closure_id_T_', $rewritten);
        self::assertStringContainsString('__xphp_tag', $rewritten);
        // Call site rewritten with the tag prefix.
        self::assertMatchesRegularExpression(
            "/\\\$id\\('T_[0-9a-f]+', 42\\)/",
            $rewritten,
        );

        self::rrmdir($workDir);
    }

    public function testGenericClosureWithUseClauseSpecializesViaDispatcher(): void
    {
        // P5.6: closures with explicit `use (...)` now specialize via
        // the dispatcher path; the `use` clause is forwarded onto the
        // dispatcher closure and each capture is lifted as a trailing
        // `mixed` param on the specialized function.
        $workDir = sys_get_temp_dir() . '/xphp-closure-use-' . uniqid('', true);
        mkdir($workDir, 0o755, true);
        $sourceDir = $workDir . '/src';
        mkdir($sourceDir, 0o755, true);
        file_put_contents($sourceDir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $y = 1;
        $f = function<T>(T $x) use ($y) { return [$x, $y]; };
        $f::<int>(42);
        PHP);

        $compiler = new Compiler(
            new \XPHP\FileSystem\FileReader\NativeFileReader(),
            new \XPHP\FileSystem\FileWriter\NativeFileWriter(),
            new XphpSourceParser((new ParserFactory())->createForHostVersion()),
            new Specializer(),
            new SpecializedClassGenerator(
                new \PhpParser\PrettyPrinter\Standard(),
                new \XPHP\FileSystem\FileWriter\NativeFileWriter(),
            ),
            new \PhpParser\PrettyPrinter\Standard(),
        );
        $sources = (new \XPHP\FileSystem\FileFinder\NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $compiler->compile($sources, $sourceDir, $workDir . '/dist', $workDir . '/.xphp-cache');

        $rewritten = file_get_contents($workDir . '/dist/Use.php');
        self::assertStringContainsString('closure_f_T_', $rewritten);
        // Dispatcher closure's `use ($y)` clause forwards the capture.
        self::assertStringContainsString('use ($y)', $rewritten);
        // Specialized function carries the lifted `mixed $y` trailing param.
        self::assertMatchesRegularExpression(
            '/function closure_f_T_[0-9a-f]+\(int \$x, mixed \$y\)/',
            $rewritten,
        );

        self::rrmdir($workDir);
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testVariableTurbofishCallSiteIsRecognized(): void
    {
        // `$pair::<int>('x')` -- variable turbofish. After scanner strip,
        // nikic parses as `FuncCall(name: Variable($pair), args: [...])`.
        // The resolver attaches ATTR_METHOD_GENERIC_ARGS to the FuncCall.
        $source = <<<'PHP'
<?php
namespace App;
$pair = function<T>(T $x) { return $x; };
$pair::<int>(42);
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);
        $funcCall = self::findFirstNodeOfType($ast, \PhpParser\Node\Expr\FuncCall::class);
        self::assertNotNull($funcCall);
        $args = $funcCall->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS);
        self::assertIsArray($args);
        self::assertCount(1, $args);
        self::assertSame('int', $args[0]->name);
        self::assertTrue($args[0]->isScalar);
    }

    public function testGenericClosureDeclarationIsParsed(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
$pair = function<K, V>(K $k, V $v): array { return [$k, $v]; };
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);
        $closure = self::findFirstNodeOfType($ast, \PhpParser\Node\Expr\Closure::class);
        self::assertNotNull($closure);
        $params = $closure->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
        self::assertIsArray($params);
        self::assertCount(2, $params);
        self::assertSame('K', $params[0]->name);
        self::assertSame('V', $params[1]->name);
    }

    public function testGenericStaticClosureDeclarationIsParsed(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
$identity = static function<T>(T $x): T { return $x; };
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);
        $closure = self::findFirstNodeOfType($ast, \PhpParser\Node\Expr\Closure::class);
        self::assertNotNull($closure);
        $params = $closure->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
        self::assertIsArray($params);
        self::assertSame('T', $params[0]->name);
        self::assertTrue($closure->static);
    }

    public function testGenericArrowFunctionIsParsed(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
$id = fn<T>(T $x): T => $x;
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);
        $arrow = self::findFirstNodeOfType($ast, \PhpParser\Node\Expr\ArrowFunction::class);
        self::assertNotNull($arrow);
        $params = $arrow->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
        self::assertIsArray($params);
        self::assertSame('T', $params[0]->name);
    }

    public function testGenericStaticArrowAttachesItsMarker(): void
    {
        // The node starts at `static`, not at `fn` — the marker must anchor
        // there or the arrow silently loses its params (raw `T` in the output).
        $source = <<<'PHP'
<?php
namespace App;
$id = static fn<T>(T $x): T => $x;
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);
        $arrow = self::findFirstNodeOfType($ast, \PhpParser\Node\Expr\ArrowFunction::class);
        self::assertNotNull($arrow);
        self::assertTrue($arrow->static);
        $params = $arrow->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
        self::assertIsArray($params);
        self::assertSame('T', $params[0]->name);
    }

    public function testAttributedGenericClosureAttachesItsMarker(): void
    {
        // The node starts at the first `#[`, not at `function`.
        $source = <<<'PHP'
<?php
namespace App;
$f = #[Marked] function<T>(T $x): T {
    return $x;
};
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);
        $closure = self::findFirstNodeOfType($ast, \PhpParser\Node\Expr\Closure::class);
        self::assertNotNull($closure);
        $params = $closure->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
        self::assertIsArray($params);
        self::assertSame('T', $params[0]->name);
    }

    public function testAttributedStaticGenericArrowAttachesItsMarker(): void
    {
        // Two ADJACENT attribute groups (no whitespace between them) — one
        // with an array argument, so the walk back to the node start must
        // balance the inner brackets AND resume exactly one token before
        // each consumed group — plus `static`.
        $source = <<<'PHP'
<?php
namespace App;
$g = #[Marked]#[Tagged([1, 2])] static fn<U>(U $y): U => $y;
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);
        $arrow = self::findFirstNodeOfType($ast, \PhpParser\Node\Expr\ArrowFunction::class);
        self::assertNotNull($arrow);
        self::assertTrue($arrow->static);
        $params = $arrow->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
        self::assertIsArray($params);
        self::assertSame('U', $params[0]->name);
    }

    public function testSplitDeclarationHeadersKeepTheirMarkers(): void
    {
        // php-parser starts a node at its first attribute group or modifier —
        // when that sits on a DIFFERENT line than the declaration's name, the
        // marker (recorded at the name token's line) must still bind. Every
        // shape here either silently lost its type params or false-rejected
        // with "instantiated but never defined" when matched on the node line.
        $source = <<<'PHP'
<?php
namespace App;

#[Note]
final class Box<T> {
    public function __construct(public T $v) {}
}

final
class Pair<U> {
    public function __construct(public U $u) {}
}

class Wrap {
    #[Note]
    public function lift<T>(T $x): T { return $x; }

    public static
    function pick<T>(T $x): T { return $x; }
}

#[Note]
function ident<T>(T $x): T { return $x; }

function
double<T>(T $x): array { return [$x, $x]; }
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $byName = self::classesByName($ast);
        self::assertSame(['T'], self::paramNames($byName['Box']));
        self::assertSame(['U'], self::paramNames($byName['Pair']));

        foreach ($byName['Wrap']->getMethods() as $method) {
            $params = $method->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
            self::assertIsArray($params, "method {$method->name->toString()} lost its marker");
            self::assertCount(1, $params);
        }

        $functions = [];
        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_) {
                foreach ($stmt->stmts as $s) {
                    if ($s instanceof \PhpParser\Node\Stmt\Function_) {
                        $functions[$s->name->toString()] = $s;
                    }
                }
            }
        }
        self::assertSame(['ident', 'double'], array_keys($functions));
        foreach ($functions as $name => $fn) {
            $params = $fn->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
            self::assertIsArray($params, "function {$name} lost its marker");
            self::assertCount(1, $params);
        }
    }

    public function testSplitInterfaceAndTraitHeadersKeepTheirMarkers(): void
    {
        $source = <<<'PHP'
<?php
namespace App;

#[Note]
interface Keeper<T> {
    public function get(): T;
}

#[Note]
trait Mixin<T> {
}
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $iface = self::findFirstClassLike($ast, \PhpParser\Node\Stmt\Interface_::class);
        self::assertNotNull($iface);
        self::assertSame(['T'], self::paramNames($iface));

        $trait = self::findFirstClassLike($ast, \PhpParser\Node\Stmt\Trait_::class);
        self::assertNotNull($trait);
        self::assertSame(['T'], self::paramNames($trait));
    }

    public function testUseFunctionImportNeverBecomesAGenericMarker(): void
    {
        // `use function b<T>;` is invalid code, but it must fail as PHP's own
        // syntax error on the un-stripped `<` — not be silently swallowed
        // (the clause used to strip, emitting `use function b ;`), and not be
        // misreported as a transpiler bug by the unbound-marker backstop
        // (an import never produces the Function_ node the marker binds to).
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());

        foreach (['use function b<T>;', 'use function b<T> as c;', 'use Foo\{function a<T>};'] as $import) {
            $source = "<?php\nnamespace App;\n{$import}\n";
            $stripped = $parser->strip($source);
            self::assertMatchesRegularExpression(
                '/<T>/',
                $stripped,
                "the clause in `{$import}` must survive into the cleaned source",
            );
            try {
                $parser->parse($source);
                self::fail("expected PHP's own parse error for `{$import}`");
            } catch (\PhpParser\Error) {
                // PHP's syntax error — the right blame, in both modes.
            }
        }

        // Plain function imports keep compiling, including next to a genuine
        // generic declaration.
        $ast = $parser->parse("<?php\nnamespace App;\nuse function strlen;\nfunction f<T>(T \$x): T { return \$x; }\n");
        self::assertNotEmpty($ast);
    }

    public function testUnboundDeclarationMarkerMessageIsNullWhenEverythingBound(): void
    {
        self::assertNull(XphpSourceParser::unboundDeclarationMarkerMessage([], []));
    }

    public function testUnboundClassMarkerProducesTheLoudBackstopError(): void
    {
        $msg = XphpSourceParser::unboundDeclarationMarkerMessage(
            [['line' => 7, 'name' => 'Box']],
            [],
        );
        self::assertSame(
            'The generic type-parameter clause for `Box` (line 7) was recognized but never bound to '
            . 'its declaration — compiling on would silently drop the type parameters from the '
            . 'emitted code. This is a transpiler bug; please report it. As a workaround, keep the '
            . 'declaration header (attributes, modifiers, and name) on a single line.',
            $msg,
        );
    }

    public function testUnboundMethodAndClosureMarkersProduceTheLoudBackstopError(): void
    {
        $named = XphpSourceParser::unboundDeclarationMarkerMessage(
            [],
            [['line' => 3, 'name' => 'wrap']],
        );
        self::assertNotNull($named);
        self::assertStringContainsString('`wrap`', $named);
        self::assertStringContainsString('line 3', $named);

        $anonymous = XphpSourceParser::unboundDeclarationMarkerMessage(
            [],
            [['line' => 9, 'name' => '']],
        );
        self::assertNotNull($anonymous);
        self::assertStringContainsString('an anonymous closure', $anonymous);
        self::assertStringContainsString('line 9', $anonymous);

        // Class markers are reported first when both kinds survive.
        $both = XphpSourceParser::unboundDeclarationMarkerMessage(
            [['line' => 1, 'name' => 'A']],
            [['line' => 2, 'name' => 'b']],
        );
        self::assertNotNull($both);
        self::assertStringContainsString('`A`', $both);
    }

    public function testGenericClosureDefaultIsAccepted(): void
    {
        // P5.7: defaults now allowed on anonymous closures; GMC pads
        // missing trailing args via `Registry::padArgsWithDefaults`.
        $source = <<<'PHP'
<?php
namespace App;
$f = function<T = string>(T $x): T { return $x; };
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);
        self::assertNotEmpty($ast);
    }

    public function testGenericArrowFunctionDefaultIsAccepted(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
$f = fn<T = string>(T $x): T => $x;
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);
        self::assertNotEmpty($ast);
    }

    public function testGenericStaticClosureDefaultStillRejected(): void
    {
        // P5.7's per-form gating: static closures still reject defaults
        // because their specialization didn't ship.
        $source = <<<'PHP'
<?php
namespace App;
$f = static function<T = string>(T $x): T { return $x; };
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('static closures');
        $parser->parse($source);
    }

    public function testGenericClosureVarianceIsRejected(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
$f = function<out T>(T $x): T { return $x; };
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Variance markers');
        $this->expectExceptionMessage('closures, or arrow functions');
        $parser->parse($source);
    }

    public function testArrowFunctionVarianceIsRejected(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
$f = fn<out T>(T $x): T => $x;
PHP;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Variance markers');
        $this->expectExceptionMessage('closures, or arrow functions');
        $parser->parse($source);
    }

    /**
     * @template TNode of Node
     * @param array<int, mixed> $ast
     * @param class-string<TNode> $kind
     * @return TNode|null
     */
    private static function findFirstNodeOfType(array $ast, string $kind): ?Node
    {
        $found = null;
        $walker = function ($nodes) use (&$walker, &$found, $kind): void {
            foreach ($nodes as $node) {
                if ($found !== null) {
                    return;
                }
                if ($node instanceof $kind) {
                    $found = $node;
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
        return $found;
    }

    // ===================================================================
    // Helpers for the new tests above
    // ===================================================================

    private static function parseAndPrettyPrint(string $source): string
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $printer = new \PhpParser\PrettyPrinter\Standard();
        return $printer->prettyPrintFile($ast);
    }

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
     * @param array<int, mixed> $ast
     * @return array<string, Class_>
     */
    private static function classesByName(array $ast): array
    {
        $byName = [];
        foreach (self::collectClasses($ast) as $class) {
            $byName[$class->name?->toString() ?? ''] = $class;
        }
        return $byName;
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
