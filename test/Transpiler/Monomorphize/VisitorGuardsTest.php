<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PHPUnit\Framework\TestCase;

/**
 * Targets the LogicalAnd → LogicalOr guard-condition mutations in the three Monomorphize
 * NodeVisitors (CallSiteRewriter, RegistryCollector, Specializer).
 *
 * Each guard is a chain like `is_array($x) && $x !== [] && is_string($y) && allConcrete($x)`.
 * Replacing ANY `&&` with `||` makes the guard vacuously true and lets the body execute
 * with bad / missing inputs. These tests construct AST nodes with exactly one failed
 * pre-condition and assert no side effect occurs.
 */
final class VisitorGuardsTest extends TestCase
{
    // =====================================================================
    // CallSiteRewriter
    // =====================================================================

    public function testCallSiteRewriterIgnoresFullyQualifiedNames(): void
    {
        $registry = new Registry();
        $fq = new FullyQualified('App\\Models\\Plastic');
        $fq->setAttribute(XphpSourceParser::ATTR_GENERIC_ARGS, [new TypeRef('App\\Plastic')]);
        $fq->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, 'App\\Containers\\Box');

        $ast = self::wrapNameInStmt($fq);
        (new CallSiteRewriter($registry))->rewrite($ast);

        self::assertSame([], $registry->instantiations(), 'already-FullyQualified nodes must not be re-rewritten');
    }

    public function testCallSiteRewriterIgnoresNameWithoutGenericArgs(): void
    {
        $registry = new Registry();
        $name = new Name('Box');
        $name->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, 'App\\Containers\\Box');
        // genericArgs deliberately not set

        $ast = self::wrapNameInStmt($name);
        (new CallSiteRewriter($registry))->rewrite($ast);

        self::assertSame([], $registry->instantiations(), 'Name without xphp:genericArgs must not be rewritten');
    }

    public function testCallSiteRewriterRecordsEmptyArgsInstantiation(): void
    {
        // Empty `xphp:genericArgs` is the `Cache::<>` shape (and the bare
        // `new Cache;` shape after RegistryCollector synthesizes the marker):
        // an instantiation that asks the registry to pad entirely from defaults.
        // The rewriter no longer gates on `args !== []` -- the registry's
        // recordInstantiation does the padding, validation, and hashing.
        $registry = new Registry();
        $name = new Name('Box');
        $name->setAttribute(XphpSourceParser::ATTR_GENERIC_ARGS, []);
        $name->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, 'App\\Containers\\Box');

        $ast = self::wrapNameInStmt($name);
        (new CallSiteRewriter($registry))->rewrite($ast);

        self::assertCount(1, $registry->instantiations(), 'empty genericArgs routes through recordInstantiation for defaults padding');
    }

    public function testCallSiteRewriterIgnoresNameWithoutTemplateFqn(): void
    {
        $registry = new Registry();
        $name = new Name('Box');
        $name->setAttribute(XphpSourceParser::ATTR_GENERIC_ARGS, [new TypeRef('App\\Plastic')]);
        // templateFqn deliberately not set

        $ast = self::wrapNameInStmt($name);
        (new CallSiteRewriter($registry))->rewrite($ast);

        self::assertSame([], $registry->instantiations());
    }

    public function testCallSiteRewriterIgnoresNonConcreteArgs(): void
    {
        $registry = new Registry();
        $name = new Name('Box');
        $name->setAttribute(XphpSourceParser::ATTR_GENERIC_ARGS, [new TypeRef('T', isTypeParam: true)]);
        $name->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, 'App\\Containers\\Box');

        $ast = self::wrapNameInStmt($name);
        (new CallSiteRewriter($registry))->rewrite($ast);

        self::assertSame([], $registry->instantiations(), 'unresolved type-param in args must not produce a Registry entry');
    }

    // =====================================================================
    // RegistryCollector — Class_ recording branch (line 45)
    // =====================================================================

    public function testCollectorIgnoresClassWithoutGenericParams(): void
    {
        $registry = new Registry();
        $class = new Class_(new Identifier('Box'));
        $class->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, 'App\\Containers\\Box');
        // genericParams deliberately not set

        (new RegistryCollector($registry))->collect([$class], '/x.xphp');

        self::assertSame([], $registry->definitions(), 'Class_ without xphp:genericParams must not be recorded');
    }

    public function testCollectorIgnoresClassWithEmptyGenericParams(): void
    {
        $registry = new Registry();
        $class = new Class_(new Identifier('Box'));
        $class->setAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS, []);
        $class->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, 'App\\Containers\\Box');

        (new RegistryCollector($registry))->collect([$class], '/x.xphp');

        self::assertSame([], $registry->definitions());
    }

    public function testCollectorIgnoresClassWithoutTemplateFqn(): void
    {
        $registry = new Registry();
        $class = new Class_(new Identifier('Box'));
        $class->setAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS, [new TypeParam('T')]);
        // templateFqn deliberately not set

        (new RegistryCollector($registry))->collect([$class], '/x.xphp');

        self::assertSame([], $registry->definitions());
    }

    public function testCollectorDoesNotReRecordAlreadyKnownDefinition(): void
    {
        $registry = new Registry();
        $class = new Class_(new Identifier('Box'));
        $class->setAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS, [new TypeParam('T')]);
        $class->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, 'App\\Containers\\Box');

        $collector = new RegistryCollector($registry);
        $collector->collect([$class], '/x.xphp');
        // Second collect should not throw a duplicate-definition error.
        $collector->collect([$class], '/y.xphp');

        self::assertCount(1, $registry->definitions());
    }

    // =====================================================================
    // RegistryCollector — Name instantiation branch (line 59)
    // =====================================================================

    public function testCollectorIgnoresNameWithoutGenericArgs(): void
    {
        $registry = new Registry();
        $name = new Name('Box');
        $name->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, 'App\\Containers\\Box');

        (new RegistryCollector($registry))->collect(self::wrapNameInStmt($name), '/x.xphp');

        self::assertSame([], $registry->instantiations());
    }

    public function testCollectorIgnoresNameWithNonConcreteArgs(): void
    {
        $registry = new Registry();
        $name = new Name('Box');
        $name->setAttribute(XphpSourceParser::ATTR_GENERIC_ARGS, [new TypeRef('T', isTypeParam: true)]);
        $name->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, 'App\\Containers\\Box');

        (new RegistryCollector($registry))->collect(self::wrapNameInStmt($name), '/x.xphp');

        self::assertSame([], $registry->instantiations());
    }

    public function testCollectorBareNewSynthesisSkipsTemplatesWithRequiredParams(): void
    {
        // `class Box<T>` (no default) -- a bare `new Box;` must NOT synthesize
        // a zero-arg instantiation. Only all-defaults templates are eligible.
        $registry = new Registry();
        $class = new Class_(new Identifier('Box'));
        $class->setAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS, [new TypeParam('T')]);
        $class->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, 'Box');
        $bareNew = new \PhpParser\Node\Expr\New_(new Name('Box'));
        $ast = [
            $class,
            new \PhpParser\Node\Stmt\Expression($bareNew),
        ];

        (new RegistryCollector($registry))->collect($ast, '/x.xphp');

        self::assertSame([], $registry->instantiations(), 'bare new on a non-defaulted template must not synthesize an instantiation');
    }

    public function testCollectorBareNewSynthesisRecordsAllDefaultsTemplate(): void
    {
        // `class Cache<K = string>` and bare `new Cache;` -- synthesizer fires.
        $registry = new Registry();
        $class = new Class_(new Identifier('Cache'));
        $class->setAttribute(
            XphpSourceParser::ATTR_GENERIC_PARAMS,
            [new TypeParam('K', default: new TypeRef('string', isScalar: true))],
        );
        $class->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, 'Cache');
        $bareNew = new \PhpParser\Node\Expr\New_(new Name('Cache'));
        $ast = [
            $class,
            new \PhpParser\Node\Stmt\Expression($bareNew),
        ];

        (new RegistryCollector($registry))->collect($ast, '/x.xphp');

        self::assertCount(1, $registry->instantiations());
        // The Name node now carries the synthesized attributes.
        self::assertSame([], $bareNew->class->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS));
        self::assertSame('Cache', $bareNew->class->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN));
    }

    public function testCollectorBareNewSynthesisSkipsNameWithExistingGenericArgs(): void
    {
        // `new Cache::<int>;` -- the Name already carries ATTR_GENERIC_ARGS. The
        // synthesis arm must NOT fire (a second recordInstantiation on a different
        // arg shape would still be idempotent under hash, but the gate keeps
        // synthesis strictly for the bare-`new` shape).
        $registry = new Registry();
        $class = new Class_(new Identifier('Cache'));
        $class->setAttribute(
            XphpSourceParser::ATTR_GENERIC_PARAMS,
            [new TypeParam('K', default: new TypeRef('string', isScalar: true))],
        );
        $class->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, 'Cache');
        $explicitCall = new Name('Cache');
        $explicitCall->setAttribute(XphpSourceParser::ATTR_GENERIC_ARGS, [new TypeRef('int', isScalar: true)]);
        $explicitCall->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, 'Cache');
        $newExpr = new \PhpParser\Node\Expr\New_($explicitCall);
        $ast = [
            $class,
            new \PhpParser\Node\Stmt\Expression($newExpr),
        ];

        (new RegistryCollector($registry))->collect($ast, '/x.xphp');

        // Exactly ONE instantiation -- the explicit-args one -- not two
        // (which would mean the synthesis pass also recorded).
        self::assertCount(1, $registry->instantiations());
        $only = array_values($registry->instantiations())[0];
        self::assertCount(1, $only->concreteTypes);
        self::assertSame('int', $only->concreteTypes[0]->name);
        self::assertTrue($only->concreteTypes[0]->isScalar);
    }

    public function testCollectorBareNewSynthesisSkipsFullyQualifiedName(): void
    {
        // FullyQualified Name nodes already point at an explicit class -- no
        // namespace + use-map resolution needed, and they're not a synthesis
        // target. Pin that the collector ignores them.
        $registry = new Registry();
        $class = new Class_(new Identifier('Cache'));
        $class->setAttribute(
            XphpSourceParser::ATTR_GENERIC_PARAMS,
            [new TypeParam('K', default: new TypeRef('string', isScalar: true))],
        );
        $class->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, 'Cache');
        $bareNew = new \PhpParser\Node\Expr\New_(new FullyQualified('Cache'));
        $ast = [
            $class,
            new \PhpParser\Node\Stmt\Expression($bareNew),
        ];

        (new RegistryCollector($registry))->collect($ast, '/x.xphp');

        self::assertSame([], $registry->instantiations(), 'FullyQualified bare new must not be synthesized');
    }

    // =====================================================================
    // Specializer — substitution map matching (line 91)
    // =====================================================================

    public function testSpecializerSubstitutesOnlyMatchingTypeParam(): void
    {
        // class Template {
        //     public T $a;       <-- substitutable: T is in subst map AND isTypeParam=true after substitution lookup
        //     public Other $b;   <-- NOT substitutable: Other is in subst map but it's a concrete-name lookup,
        //                           not a type-param. With LogicalAnd -> LogicalOr at line 91, the substituteTypeRef
        //                           would also try to substitute Other, which has no nested args.
        // }
        $tParam = new Property(0, [new PropertyItem('a')], type: new Name('T'));
        $otherParam = new Property(0, [new PropertyItem('b')], type: new Name('Other'));
        $template = new Class_(new Identifier('Template'), ['stmts' => [$tParam, $otherParam]]);

        // Substitution map keyed by 'T' AND 'Other' — but only 'T' substitution should fire,
        // because the Specializer's name-substitution branch only matches single-segment names
        // that have a key in the map. (`Other` matches by name too — but the property type Name
        // for it would also be replaced. So this test alone doesn't isolate S91. Build a different
        // case below.)
        $subst = [
            'T' => new TypeRef('App\\Models\\Plastic'),
        ];

        $specialized = (new Specializer())->specialize($template, $subst);

        // T should be replaced with FullyQualified \App\Models\Plastic.
        $aProp = $specialized->stmts[0];
        self::assertInstanceOf(Property::class, $aProp);
        self::assertInstanceOf(FullyQualified::class, $aProp->type);
        self::assertSame('App\\Models\\Plastic', $aProp->type->toString());

        // Other is not in the subst map -> stays as plain Name.
        $bProp = $specialized->stmts[1];
        self::assertInstanceOf(Property::class, $bProp);
        self::assertInstanceOf(Name::class, $bProp->type);
        self::assertNotInstanceOf(FullyQualified::class, $bProp->type);
        self::assertSame('Other', $bProp->type->toString());
    }

    public function testSpecializerDoesNotSubstituteWhenKeyIsAbsentFromMap(): void
    {
        // class Template {
        //     public T $a;
        // }
        // Substitution map: ['U' => ...]  — 'T' is NOT in the map.
        //
        // Under the original `isTypeParam && isset($subst[$name])` guard inside substituteTypeRef,
        // a Name whose name is 'T' but for which subst['T'] is not set must remain as-is. The
        // outer leaveNode also gates on isset($substitution[$parts[0]]) — so 'T' stays unchanged.
        $tParam = new Property(0, [new PropertyItem('a')], type: new Name('T'));
        $template = new Class_(new Identifier('Template'), ['stmts' => [$tParam]]);

        $specialized = (new Specializer())->specialize($template, ['U' => new TypeRef('App\\Other')]);

        $aProp = $specialized->stmts[0];
        self::assertInstanceOf(Name::class, $aProp->type);
        self::assertNotInstanceOf(FullyQualified::class, $aProp->type);
        self::assertSame('T', $aProp->type->toString());
    }

    public function testSpecializerSubstituteTypeRefRequiresBothIsTypeParamAndKey(): void
    {
        // substituteTypeRef gates substitution on `$ref->isTypeParam && isset($subst[$ref->name])`.
        // Set up a Name node carrying xphp:genericArgs containing a NON-type-param TypeRef whose
        // name happens to collide with a substitution key. Under the original `&&` guard the arg
        // must remain unchanged (because isTypeParam is false). Under the `||` mutation it would
        // be replaced even though it's a regular class reference, not a type-param.
        $argTypeRef = new TypeRef('T'); // isTypeParam defaults to false — this is a class named "T", not a type-param
        $boxName = new Name('Box');
        $boxName->setAttribute(XphpSourceParser::ATTR_GENERIC_ARGS, [$argTypeRef]);
        $boxName->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, 'App\\Containers\\Box');

        $property = new Property(0, [new PropertyItem('b')], type: $boxName);
        $template = new Class_(new Identifier('Wrapper'), ['stmts' => [$property]]);

        // Substitution map keyed by 'T'.
        $specialized = (new Specializer())->specialize($template, ['T' => new TypeRef('App\\Plastic')]);

        $aProp = $specialized->stmts[0];
        $resolvedArgs = $aProp->type->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
        self::assertIsArray($resolvedArgs);
        self::assertCount(1, $resolvedArgs);
        self::assertSame('T', $resolvedArgs[0]->name, 'a non-type-param TypeRef named "T" must not be substituted just because subst["T"] exists');
        self::assertFalse($resolvedArgs[0]->isTypeParam);
    }

    public function testSpecializerLeavesNameWithoutGenericArgsAttributeUntouched(): void
    {
        // Inside the visitor's leaveNode, the second branch reads xphp:genericArgs.
        // For Names that don't have the attribute, `is_array($args) && $args !== []` must be false.
        // The mutation `is_array($args) || $args !== []` would enter the body with $args = null
        // and crash on array_map(null).
        //
        // Trigger: a Name node ('App\Models\Foo') that has no xphp:genericArgs and is not in the
        // substitution map.
        $param = new Param(new Variable('x'), type: new Name('Foo'));
        $method = new ClassMethod('bar', ['params' => [$param]]);
        $template = new Class_(new Identifier('Template'), ['stmts' => [$method]]);

        // Should not throw / crash.
        $specialized = (new Specializer())->specialize($template, ['T' => new TypeRef('App\\Plastic')]);

        // Foo stays as plain Name.
        $methodOut = $specialized->stmts[0];
        self::assertInstanceOf(ClassMethod::class, $methodOut);
        $paramOut = $methodOut->params[0];
        self::assertInstanceOf(Name::class, $paramOut->type);
        self::assertSame('Foo', $paramOut->type->toString());
    }

    // =====================================================================
    // Specializer — leading-backslash normalization on substitution values
    // (A5 from the triage; kills the three UnwrapLtrim mutations in typeRefToNode).
    // =====================================================================

    public function testSpecializerNormalizesLeadingBackslashForConcreteClassSubstitution(): void
    {
        // class Template { public T $a; }   — substitute T with `\App\Plastic` (note leading \).
        // Hits the !isGeneric() branch in typeRefToNode (line 78). Without ltrim the
        // FullyQualified node would internally hold `\App\Plastic`, which the pretty-printer
        // would render as `\\App\Plastic` (invalid PHP).
        $template = new Class_(new Identifier('Template'), [
            'stmts' => [new Property(0, [new PropertyItem('a')], type: new Name('T'))],
        ]);

        $specialized = (new Specializer())->specialize($template, [
            'T' => new TypeRef('\\App\\Plastic'),
        ]);

        $aProp = $specialized->stmts[0];
        self::assertInstanceOf(Property::class, $aProp);
        self::assertInstanceOf(FullyQualified::class, $aProp->type);
        self::assertSame('App\\Plastic', $aProp->type->toString(), 'one leading backslash on the FQN, not double');
    }

    public function testSpecializerNormalizesLeadingBackslashForGenericSubstitution(): void
    {
        // class Template { public T $a; }   — substitute T with a generic TypeRef whose
        // name has a leading backslash. Hits the generic branch (lines 80 + 82).
        $template = new Class_(new Identifier('Template'), [
            'stmts' => [new Property(0, [new PropertyItem('a')], type: new Name('T'))],
        ]);

        $genericSubst = new TypeRef('\\App\\Containers\\Collection', [new TypeRef('App\\Models\\Plastic')]);
        $specialized = (new Specializer())->specialize($template, [
            'T' => $genericSubst,
        ]);

        $nameNode = $specialized->stmts[0]->type;
        self::assertInstanceOf(Name::class, $nameNode);
        self::assertNotInstanceOf(FullyQualified::class, $nameNode);
        self::assertSame('App\\Containers\\Collection', $nameNode->toString(), 'Name() constructor must receive ltrim-normalized name (line 80)');
        self::assertSame(
            'App\\Containers\\Collection',
            $nameNode->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN),
            'ATTR_TEMPLATE_FQN attribute must be ltrim-normalized (line 82)',
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Wraps a Name node in an expression statement (`new $name()`) so a NodeTraverser walks it.
     *
     * @return list<Node\Stmt>
     */
    private static function wrapNameInStmt(Name $name): array
    {
        $new = new Node\Expr\New_($name);
        return [new Node\Stmt\Expression($new)];
    }
}
