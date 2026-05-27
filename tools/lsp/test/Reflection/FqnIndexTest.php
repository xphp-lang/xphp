<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Reflection;

use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class FqnIndexTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xphp-fqn-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->rmrf($this->root);
        }
    }

    public function testResolvesOpenDocumentDeclarationsByFqn(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            'file:///workspace/Collection.xphp',
            'xphp',
            1,
            "<?php\nnamespace App\\Containers;\nclass Collection<T> {}\n",
        ));
        $index = $this->index($workspace);

        self::assertSame('file:///workspace/Collection.xphp', $index->pathFor('App\\Containers\\Collection'));
        self::assertContains('App\\Containers\\Collection', $index->allClassFqns());
    }

    public function testResolvesFilesystemDeclarationsByFqn(): void
    {
        $this->writeFile('App/Models/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $index = $this->index(new PhpactorWorkspace());

        self::assertStringEndsWith('App/Models/User.xphp', $index->pathFor('App\\Models\\User'));
        self::assertContains('App\\Models\\User', $index->allClassFqns());
    }

    public function testOpenDocWinsOverFilesystemOnCollidingFqn(): void
    {
        // Same FQN on disk and in open editor -- live editor view wins.
        $this->writeFile('Collection.xphp', "<?php\nnamespace App\\Containers;\nclass Collection {}\n");
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            'file:///workspace/Collection.xphp',
            'xphp',
            1,
            "<?php\nnamespace App\\Containers;\nclass Collection<T> {}\n",
        ));
        $index = $this->index($workspace);

        self::assertSame(
            'file:///workspace/Collection.xphp',
            $index->pathFor('App\\Containers\\Collection'),
        );
    }

    public function testClassLikeForReturnsAstWithXphpAttributesFromFilesystem(): void
    {
        // Critical for GenericResolver: the ClassLike we hand back from a
        // closed file MUST still carry ATTR_GENERIC_PARAMS so type-arg
        // substitution works without re-parsing.
        $this->writeFile('Collection.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Collection<T> {
            public function first(): ?T { return null; }
        }
        XPHP);
        $index = $this->index(new PhpactorWorkspace());

        $class = $index->classLikeFor('App\\Containers\\Collection');

        self::assertNotNull($class);
        self::assertSame('Collection', $class->name?->toString());
        $params = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertIsArray($params);
        self::assertCount(1, $params);
        self::assertSame('T', $params[0]->name);
    }

    public function testClassLikeForReturnsAstFromOpenDocument(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            'file:///workspace/Pair.xphp',
            'xphp',
            1,
            "<?php\nnamespace App\\Containers;\nclass Pair<K, V> { public function key(): K { return null; } }\n",
        ));
        $index = $this->index($workspace);

        $class = $index->classLikeFor('App\\Containers\\Pair');

        self::assertNotNull($class);
        $params = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertCount(2, $params);
        self::assertSame('K', $params[0]->name);
        self::assertSame('V', $params[1]->name);
    }

    public function testFunctionFqnsTracked(): void
    {
        $this->writeFile('helpers.xphp', "<?php\nnamespace App;\nfunction greet(string \$n): string { return \$n; }\n");
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            'file:///fn.xphp',
            'xphp',
            1,
            "<?php\nfunction global_fn(): void {}\n",
        ));
        $index = $this->index($workspace);

        $functions = $index->allFunctionFqns();

        self::assertContains('App\\greet', $functions, 'filesystem function must be indexed');
        self::assertContains('global_fn', $functions, 'open-doc function must be indexed');
        // Class declarations don't leak into the function list.
        self::assertNotContains('App\\greet', $index->allClassFqns());
    }

    public function testReturnsNullForUnknownFqn(): void
    {
        $index = $this->index(new PhpactorWorkspace());

        self::assertNull($index->pathFor('Mystery\\Class'));
        self::assertNull($index->classLikeFor('Mystery\\Class'));
    }

    public function testEmptyFqnReturnsNullEarly(): void
    {
        $index = $this->index(new PhpactorWorkspace());

        self::assertNull($index->pathFor(''));
        self::assertNull($index->classLikeFor(''));
    }

    public function testMissingRootPathReturnsEmptyFilesystemSide(): void
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $index = new FqnIndex(
            new PhpactorWorkspace(),
            $cache,
            $parser,
            '/path/that/definitely/does/not/exist',
        );

        self::assertSame([], $index->allClassFqns());
        self::assertSame([], $index->allFunctionFqns());
    }

    public function testSkipsExcludedDirectories(): void
    {
        // Files under skipped dirs must NOT appear in the index.
        $this->writeFile('vendor/should-skip.xphp', "<?php\nclass VendorClass {}\n");
        $this->writeFile('node_modules/should-skip.xphp', "<?php\nclass NodeClass {}\n");
        $this->writeFile('legit.xphp', "<?php\nclass LegitClass {}\n");

        $index = $this->index(new PhpactorWorkspace());

        self::assertContains('LegitClass', $index->allClassFqns());
        self::assertNotContains('VendorClass', $index->allClassFqns());
        self::assertNotContains('NodeClass', $index->allClassFqns());
    }

    public function testLocationForFqnPointsAtIdentifierInOpenDoc(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            '/User.xphp',
            'xphp',
            1,
            "<?php\nnamespace App\\Models;\n\nclass User {}\n",
        ));
        $index = $this->index($workspace);

        $hit = $index->locationForFqn('App\\Models\\User');

        self::assertNotNull($hit);
        self::assertSame('/User.xphp', $hit['uri']);
        self::assertSame(3, $hit['line']);
        self::assertSame(6, $hit['char']);
        self::assertSame('User', $hit['short']);
    }

    public function testLocationForFqnFallsThroughToFilesystem(): void
    {
        $this->writeFile('Box.xphp', "<?php\nnamespace App\\Containers;\nclass Box<T> {}\n");
        $index = $this->index(new PhpactorWorkspace());

        $hit = $index->locationForFqn('App\\Containers\\Box');

        self::assertNotNull($hit);
        self::assertSame('file://' . $this->root . '/Box.xphp', $hit['uri']);
        // class Box<T> is on line 2 (0-indexed); `Box` is at char 6.
        self::assertSame(2, $hit['line']);
        self::assertSame(6, $hit['char']);
    }

    public function testLocationByShortNameFallsThroughToFilesystem(): void
    {
        $this->writeFile('User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $index = $this->index(new PhpactorWorkspace());

        $hit = $index->locationByShortName('User');

        self::assertNotNull($hit);
        self::assertSame('User', $hit['short']);
        self::assertSame('file://' . $this->root . '/User.xphp', $hit['uri']);
    }

    public function testLocationByShortNameMatchesUnnamespacedClass(): void
    {
        // A class declared outside any namespace: FQN == short name.  The
        // tail-suffix matcher would skip this since there's no `\<short>`
        // suffix to find; the explicit equality branch covers it.
        $this->writeFile('Bare.xphp', "<?php\nclass Bare {}\n");
        $index = $this->index(new PhpactorWorkspace());

        $hit = $index->locationByShortName('Bare');

        self::assertNotNull($hit);
        self::assertSame('Bare', $hit['short']);
    }

    public function testLocationByShortNameReturnsNullForUnknown(): void
    {
        $index = $this->index(new PhpactorWorkspace());
        self::assertNull($index->locationByShortName('NeverDeclared'));
    }

    public function testLocationForFqnReturnsNullForEmptyOrUnknown(): void
    {
        $index = $this->index(new PhpactorWorkspace());
        self::assertNull($index->locationForFqn(''));
        self::assertNull($index->locationForFqn('\\'));
        self::assertNull($index->locationForFqn('Nope\\Mystery'));
    }

    public function testFilesystemWalkSkipsNestedTestFixturesDir(): void
    {
        // Phase 3 polish: `test/fixture/...` declarations were polluting
        // workspace symbol search + closed-file GTD in the xphp repo
        // itself (confirmed in prod traces 2.2 + 2.3).  The walk now
        // skips `fixture` / `fixtures` when nested under a `test` /
        // `tests` directory.
        $this->writeFile('src/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $this->writeFile('test/fixture/source/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $this->writeFile('test/fixtures/another/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $this->writeFile('tests/fixture/yetmore/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");

        $index = $this->index(new PhpactorWorkspace());

        $location = $index->locationForFqn('App\\Models\\User');
        self::assertNotNull($location);
        self::assertSame('file://' . $this->root . '/src/User.xphp', $location['uri']);
    }

    public function testFilesystemWalkDoesNotSkipNonTestFixtureDirs(): void
    {
        // A `fixture` directory NOT under `test` (e.g. someone's domain
        // model) must still be walked.
        $this->writeFile('src/fixture/Item.xphp', "<?php\nnamespace App;\nclass Item {}\n");

        $index = $this->index(new PhpactorWorkspace());

        self::assertContains('App\\Item', $index->allClassFqns());
    }

    public function testShortNameTiebreakPrefersShorterPath(): void
    {
        // When two non-fixture files declare the same short-named class
        // across different namespaces, deterministic tiebreak picks the
        // shortest URI.
        $this->writeFile('a/User.xphp', "<?php\nnamespace App\\A;\nclass User {}\n");
        $this->writeFile('deep/path/here/User.xphp', "<?php\nnamespace App\\Deep;\nclass User {}\n");
        $index = $this->index(new PhpactorWorkspace());

        $hit = $index->locationByShortName('User');
        self::assertNotNull($hit);
        self::assertSame('file://' . $this->root . '/a/User.xphp', $hit['uri']);
    }

    public function testShortNameLookupOpenDocBeatsLongerFsPath(): void
    {
        // Open-doc precedence isn't disrupted by the tiebreak refactor:
        // even when a shorter filesystem path exists, the open buffer
        // wins.
        $this->writeFile('a/User.xphp', "<?php\nnamespace App\\A;\nclass User {}\n");

        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            '/edit/deeper/path/User.xphp',
            'xphp',
            1,
            "<?php\nnamespace App\\B;\nclass User {}\n",
        ));

        $hit = $this->index($workspace)->locationByShortName('User');
        self::assertNotNull($hit);
        self::assertSame('/edit/deeper/path/User.xphp', $hit['uri']);
    }

    public function testInvalidateFilesystemForcesRebuildOnNextQuery(): void
    {
        $this->writeFile('Alpha.xphp', "<?php\nnamespace App;\nclass Alpha {}\n");
        $index = $this->index(new PhpactorWorkspace());

        // Warm the cache.
        $first = $index->allClassFqns();
        self::assertContains('App\\Alpha', $first);

        // Add a file post-warming.  The cached map doesn't know about it.
        $this->writeFile('Beta.xphp', "<?php\nnamespace App;\nclass Beta {}\n");
        self::assertNotContains('App\\Beta', $index->allClassFqns());

        // Invalidate -- next query re-walks.
        $index->invalidateFilesystem();
        $after = $index->allClassFqns();
        self::assertContains('App\\Beta', $after);
        self::assertContains('App\\Alpha', $after);
    }

    public function testIterGenericClassesYieldsExactNamespacedFqnAndParams(): void
    {
        // Pins the namespace+class string-concat in
        // `collectGenericClasses` (FqnIndex.php line ~1232).  A Concat
        // operand swap would produce "Box\App\Containers"; a
        // ConcatOperandRemoval would yield "App\Containers" or "Box".
        // Either way the exact FQN assertion below catches it.
        $this->writeFile('Box.xphp', "<?php\nnamespace App\\Containers;\nclass Box<T> {}\n");
        $index = $this->index(new PhpactorWorkspace());

        $generic = iterator_to_array($index->iterGenericClasses());

        self::assertArrayHasKey('App\\Containers\\Box', $generic);
        self::assertSame(['T'], $generic['App\\Containers\\Box']);
        self::assertCount(1, $generic);
    }

    public function testIterGenericClassesYieldsBareFqnForNonNamespacedClass(): void
    {
        // Exercises the `$currentNamespace !== '' ? ... : $short`
        // ternary in `collectGenericClasses` -- without a namespace
        // the result must be the short name only, not e.g. "\Box".
        $this->writeFile('Box.xphp', "<?php\nclass Box<T> {}\n");
        $index = $this->index(new PhpactorWorkspace());

        $generic = iterator_to_array($index->iterGenericClasses());

        self::assertArrayHasKey('Box', $generic);
        self::assertSame(['T'], $generic['Box']);
    }

    public function testIterGenericClassesWithMultipleTypeParamsPreservesOrder(): void
    {
        // The param-name list is order-sensitive (callers index into it
        // by slot position).  Pins ArrayItemRemoval / order mutants on
        // the param collection loop.
        $this->writeFile(
            'Pair.xphp',
            "<?php\nnamespace App;\nclass Pair<K, V> {}\n",
        );
        $index = $this->index(new PhpactorWorkspace());

        $generic = iterator_to_array($index->iterGenericClasses());

        self::assertSame(['K', 'V'], $generic['App\\Pair']);
    }

    public function testIterGenericFunctionsAndMethodsYieldsExactMethodFqn(): void
    {
        // Pins the `$namespace . '\\' . $className . '::' . $declName`
        // concat in `collectGenericFunctionsAndMethods` (line ~1366).
        // The four segments + two literal separators must each appear
        // in order; any Concat swap or operand removal would change
        // the key.
        $this->writeFile(
            'Util.xphp',
            "<?php\nnamespace App\\Containers;\nclass Util { public function id<T>(T \$x): T { return \$x; } }\n",
        );
        $index = $this->index(new PhpactorWorkspace());

        $generic = iterator_to_array($index->iterGenericFunctionsAndMethods());

        self::assertArrayHasKey('App\\Containers\\Util::id', $generic);
        self::assertSame(['T'], $generic['App\\Containers\\Util::id']);
    }

    public function testIterGenericFunctionsAndMethodsBareClassMethod(): void
    {
        // Class without namespace -> method key is `Class::method`,
        // no leading `\`.  Exercises the `$currentNamespace !== ''`
        // branch's `: $className . '::' . $declName` fallback.
        $this->writeFile(
            'Util.xphp',
            "<?php\nclass Util { public function id<T>(T \$x): T { return \$x; } }\n",
        );
        $index = $this->index(new PhpactorWorkspace());

        $generic = iterator_to_array($index->iterGenericFunctionsAndMethods());

        self::assertArrayHasKey('Util::id', $generic);
        self::assertSame(['T'], $generic['Util::id']);
    }

    public function testIterGenericFunctionsAndMethodsNamespacedFreeFunction(): void
    {
        // Free function in a namespace -> key is `Namespace\func`,
        // no `::` separator.  Exercises the function-branch concat:
        // `$currentNamespace . '\\' . $declName`.
        $this->writeFile(
            'helpers.xphp',
            "<?php\nnamespace App\\Demos;\nfunction identity<T>(T \$x): T { return \$x; }\n",
        );
        $index = $this->index(new PhpactorWorkspace());

        $generic = iterator_to_array($index->iterGenericFunctionsAndMethods());

        self::assertArrayHasKey('App\\Demos\\identity', $generic);
        self::assertSame(['T'], $generic['App\\Demos\\identity']);
        // No method-shape key leaks in:
        self::assertArrayNotHasKey('App\\Demos\\identity::identity', $generic);
    }

    public function testIterGenericFunctionsAndMethodsBareFreeFunction(): void
    {
        // No namespace -> key is just the function name.
        $this->writeFile(
            'helpers.xphp',
            "<?php\nfunction identity<T>(T \$x): T { return \$x; }\n",
        );
        $index = $this->index(new PhpactorWorkspace());

        $generic = iterator_to_array($index->iterGenericFunctionsAndMethods());

        self::assertArrayHasKey('identity', $generic);
        self::assertSame(['T'], $generic['identity']);
    }

    public function testIterGenericFunctionsAndMethodsClassStackLeavesOnExit(): void
    {
        // The visitor maintains a `$classStack` so nested classes
        // don't bleed into sibling method keys.  Two classes in the
        // same file, each with a generic method of the same name --
        // the keys must differ by their enclosing class.  Pins the
        // `leaveNode -> array_pop` (line ~1380) plus the joint
        // `$classStack !== []` guard (line ~1379).
        $this->writeFile(
            'Two.xphp',
            "<?php\n"
            . "namespace App;\n"
            . "class A { public function id<T>(T \$x): T { return \$x; } }\n"
            . "class B { public function id<U>(U \$x): U { return \$x; } }\n",
        );
        $index = $this->index(new PhpactorWorkspace());

        $generic = iterator_to_array($index->iterGenericFunctionsAndMethods());

        self::assertArrayHasKey('App\\A::id', $generic);
        self::assertArrayHasKey('App\\B::id', $generic);
        self::assertSame(['T'], $generic['App\\A::id']);
        self::assertSame(['U'], $generic['App\\B::id']);
    }

    public function testBoundsForGenericClassYieldsExactBoundFqn(): void
    {
        // Pins the `collectGenericClassBounds` namespace+class concat
        // (line ~1289) plus the bound-FQN assertion.  A Concat swap
        // would change the lookup key; a wrong bound would change the
        // returned bound list.
        $this->writeFile(
            'Box.xphp',
            "<?php\nnamespace App\\Containers;\nclass Box<T: \\Stringable> {}\n",
        );
        $index = $this->index(new PhpactorWorkspace());

        $bounds = $index->boundsForGenericClass('App\\Containers\\Box');

        self::assertSame(['Stringable'], $bounds);
    }

    public function testBoundsForGenericClassWithoutNamespace(): void
    {
        $this->writeFile(
            'Box.xphp',
            "<?php\nclass Box<T: \\Stringable> {}\n",
        );
        $index = $this->index(new PhpactorWorkspace());

        $bounds = $index->boundsForGenericClass('Box');

        self::assertSame(['Stringable'], $bounds);
    }

    public function testClassLikeForNonGenericNonNamespacedClass(): void
    {
        // Exercises `findClassLikeInAst` line ~1423-1465 -- the
        // fallback path that walks the AST itself (no
        // ATTR_TEMPLATE_FQN stamp because the class isn't generic).
        // The non-namespaced shape pins the `$ns !== '' ? $ns . '\\'
        // . $current : $current` ternary against operand-swap mutants.
        $this->writeFile('Bare.xphp', "<?php\nclass Bare {}\n");
        $index = $this->index(new PhpactorWorkspace());

        $class = $index->classLikeFor('Bare');

        self::assertNotNull($class);
        self::assertSame('Bare', $class->name?->toString());
    }

    public function testClassLikeForNamespacedNonGenericClass(): void
    {
        // Same fallback path as above but with a namespace -- pins
        // the `$this->currentNamespace . '\\' . $short` branch
        // (line ~1465) where the tracker stamps ATTR_TEMPLATE_FQN
        // onto non-generic ClassLike nodes manually.
        $this->writeFile('User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $index = $this->index(new PhpactorWorkspace());

        $class = $index->classLikeFor('App\\Models\\User');

        self::assertNotNull($class);
        self::assertSame('User', $class->name?->toString());
    }

    public function testGlobalNamespaceBlockIsTreatedAsEmptyNamespace(): void
    {
        // `namespace { ... }` (the unnamed/global form) means
        // `$node->name` is null in nikic's AST.  Collectors must
        // resolve this to the empty-string namespace, which the
        // `$node->name?->toString() ?? ''` chain expresses.  Without
        // this fixture, both `NullSafeMethodCall` (drop `?->`) and
        // `Coalesce` (drop `?? ''`) mutants survive because no test
        // exercises a name-less Namespace_ node.
        $this->writeFile(
            'global.xphp',
            "<?php\nnamespace { class Bare<T> {} }\n",
        );
        $index = $this->index(new PhpactorWorkspace());

        $generic = iterator_to_array($index->iterGenericClasses());

        self::assertArrayHasKey('Bare', $generic);
        self::assertSame(['T'], $generic['Bare']);
    }

    public function testGlobalNamespaceBlockBoundIsCollectedUnderBareName(): void
    {
        // Same shape as the test above, but exercising
        // `collectGenericClassBounds`'s namespace tracker.
        $this->writeFile(
            'global.xphp',
            "<?php\nnamespace { class Bare<T: \\Stringable> {} }\n",
        );
        $index = $this->index(new PhpactorWorkspace());

        self::assertSame(['Stringable'], $index->boundsForGenericClass('Bare'));
    }

    public function testFilesystemWalkSkipsVendorTestFixtureSubdirs(): void
    {
        // Pins the `iterator()` SKIP_NESTED check (line ~1031).
        // Without a fixture that PLACES files inside the
        // skip-listed nested dirs, `FalseValue` (return true instead
        // of false) and `ReturnRemoval` (drop the early `return`)
        // survive because the walker never reaches the branch.
        //
        // SKIP_NESTED currently includes `test/fixture/source` and
        // `test/fixtures`.  Both should be invisible to the FQN
        // index even when they contain .xphp files declaring real
        // classes.
        $this->writeFile('src/Real.xphp', "<?php\nnamespace App;\nclass Real {}\n");
        $this->writeFile('test/fixture/source/Shadow.xphp', "<?php\nnamespace App;\nclass Shadow {}\n");
        $this->writeFile('test/fixtures/Phantom.xphp', "<?php\nnamespace App;\nclass Phantom {}\n");

        $index = $this->index(new PhpactorWorkspace());
        $fqns = $index->allClassFqns();

        self::assertContains('App\\Real', $fqns);
        self::assertNotContains(
            'App\\Shadow',
            $fqns,
            'test/fixture/source nested dir must be skipped by iterator()',
        );
        self::assertNotContains(
            'App\\Phantom',
            $fqns,
            'test/fixtures nested dir must be skipped by iterator()',
        );
    }

    public function testPublicLookupApisAcceptLeadingBackslashForm(): void
    {
        // Each `public function fooFor(string $fqn)` API starts with
        // `$needle = ltrim($fqn, '\\');` to accept both `\Foo\Bar` and
        // `Foo\Bar`.  Pins the UnwrapLtrim mutant on each method --
        // removing the ltrim would make the prefixed form miss the
        // unprefixed map keys, returning null.
        $this->writeFile('Box.xphp', "<?php\nnamespace App\\Containers;\nclass Box<T: \\Stringable> {}\n");
        $this->writeFile('greet.xphp', "<?php\nnamespace App;\nfunction greet(): void {}\n");

        $index = $this->index(new PhpactorWorkspace());

        self::assertSame(
            $index->pathFor('App\\Containers\\Box'),
            $index->pathFor('\\App\\Containers\\Box'),
            'pathFor must strip leading backslash',
        );
        self::assertEquals(
            $index->classLikeFor('App\\Containers\\Box'),
            $index->classLikeFor('\\App\\Containers\\Box'),
            'classLikeFor must strip leading backslash',
        );
        self::assertEquals(
            $index->functionFor('App\\greet'),
            $index->functionFor('\\App\\greet'),
            'functionFor must strip leading backslash',
        );
        self::assertSame(
            $index->boundsForGenericClass('App\\Containers\\Box'),
            $index->boundsForGenericClass('\\App\\Containers\\Box'),
            'boundsForGenericClass must strip leading backslash',
        );
        self::assertEquals(
            $index->locationForFqn('App\\Containers\\Box'),
            $index->locationForFqn('\\App\\Containers\\Box'),
            'locationForFqn must strip leading backslash',
        );

        // And the prefixed form must actually RESOLVE, not just match
        // the unprefixed form's null.
        self::assertNotNull($index->pathFor('\\App\\Containers\\Box'));
        self::assertNotNull($index->classLikeFor('\\App\\Containers\\Box'));
        self::assertNotNull($index->functionFor('\\App\\greet'));
    }

    public function testHandlesUnparseableFilesGracefully(): void
    {
        // A garbage file shouldn't blow up the whole index build.
        $this->writeFile('garbage.xphp', "<?php\nthis is not valid php at all{{{");
        $this->writeFile('ok.xphp', "<?php\nnamespace App;\nclass Ok {}\n");

        $index = $this->index(new PhpactorWorkspace());

        self::assertContains('App\\Ok', $index->allClassFqns());
    }

    public function testIsTypeParamFqnRecognisesClassGenericInItsOwnNamespace(): void
    {
        // Fix L: `T` referenced inside `namespace App\Containers`
        // name-resolves to `App\Containers\T`.  That's NOT a class --
        // it's the type-param of the enclosing `class Box<T>`.  The
        // locator uses this check to short-circuit the workspace walk
        // (and the stderr miss log) for that case.
        $this->writeFile('Box.xphp', "<?php\nnamespace App\\Containers;\nclass Box<T> {}\n");
        $index = $this->index(new PhpactorWorkspace());

        self::assertTrue($index->isTypeParamFqn('App\\Containers\\T'));
        self::assertTrue($index->isTypeParamFqn('\\App\\Containers\\T'), 'leading backslash tolerated');
    }

    public function testIsTypeParamFqnFalseForRealClasses(): void
    {
        // A real class FQN (even one with a single-letter name) must
        // not be mistaken for a type-param reference.
        $this->writeFile('Box.xphp', "<?php\nnamespace App\\Containers;\nclass Box<T> {}\n");
        $this->writeFile('Real.xphp', "<?php\nnamespace App\\Containers;\nclass User {}\n");
        $index = $this->index(new PhpactorWorkspace());

        self::assertFalse($index->isTypeParamFqn('App\\Containers\\User'));
        self::assertFalse($index->isTypeParamFqn('App\\Containers\\Unknown'));
    }

    public function testIsTypeParamFqnFalseForBareEmptyFqn(): void
    {
        $index = $this->index(new PhpactorWorkspace());
        self::assertFalse($index->isTypeParamFqn(''));
        self::assertFalse($index->isTypeParamFqn('\\'));
    }

    public function testIsTypeParamFqnScopedByNamespace(): void
    {
        // `Box<T>` lives in `App\Containers`.  A bare `T` reference
        // resolved under a DIFFERENT namespace (say, `App\Models\T`)
        // must NOT match this set -- the type-param is namespace-
        // scoped, and we don't want to suppress legitimate misses
        // from elsewhere in the workspace.
        $this->writeFile('Box.xphp', "<?php\nnamespace App\\Containers;\nclass Box<T> {}\n");
        $index = $this->index(new PhpactorWorkspace());

        self::assertTrue($index->isTypeParamFqn('App\\Containers\\T'));
        self::assertFalse($index->isTypeParamFqn('App\\Models\\T'));
        self::assertFalse($index->isTypeParamFqn('T'));
    }

    public function testIsTypeParamFqnIncludesFunctionScopeGenerics(): void
    {
        // Free-function generics share the same problem: `T` inside
        // `function App\Demos\identity<T>(...)` becomes `App\Demos\T`
        // after name resolution.
        $this->writeFile(
            'identity.xphp',
            "<?php\nnamespace App\\Demos;\nfunction identity<T>(T \$x): T { return \$x; }\n",
        );
        $index = $this->index(new PhpactorWorkspace());

        self::assertTrue($index->isTypeParamFqn('App\\Demos\\T'));
    }

    public function testIsTypeParamFqnIncludesMethodScopeGenerics(): void
    {
        // Method generics: `T` inside `class App\Containers\Util { function id<T> ... }`
        // becomes `App\Containers\T` after name resolution, exactly
        // like a class-scope generic in the same namespace would.
        $this->writeFile(
            'Util.xphp',
            "<?php\nnamespace App\\Containers;\nclass Util { public function id<T>(T \$x): T { return \$x; } }\n",
        );
        $index = $this->index(new PhpactorWorkspace());

        self::assertTrue($index->isTypeParamFqn('App\\Containers\\T'));
    }

    public function testIsTypeParamFqnRebuildsAfterInvalidation(): void
    {
        // After `invalidateFilesystem`, the lazy set is cleared and
        // rebuilt against the fresh filesystem state.  We add a new
        // generic class with a NEW param name; before invalidation
        // it's invisible, after invalidation it's recognised.
        $this->writeFile('Box.xphp', "<?php\nnamespace App\\Containers;\nclass Box<T> {}\n");
        $index = $this->index(new PhpactorWorkspace());

        self::assertTrue($index->isTypeParamFqn('App\\Containers\\T'));
        self::assertFalse($index->isTypeParamFqn('App\\Containers\\K'));

        $this->writeFile('Pair.xphp', "<?php\nnamespace App\\Containers;\nclass Pair<K, V> {}\n");
        $index->invalidateFilesystem();

        self::assertTrue($index->isTypeParamFqn('App\\Containers\\K'));
        self::assertTrue($index->isTypeParamFqn('App\\Containers\\V'));
        self::assertTrue($index->isTypeParamFqn('App\\Containers\\T'));
    }

    public function testIsBareBuiltinFunctionFqnRecognisesNamespacedBuiltins(): void
    {
        // Fix 3: `gettype` inside `namespace App\Demos` becomes
        // `App\Demos\gettype` after name resolution.  PHP's runtime
        // falls back to global function-lookup, but the static AST
        // view shows the prefixed form first.  Worse-reflection then
        // asks the locator for the prefixed class, which always
        // misses -- and pre-Fix-3 logged a stderr "miss" line.
        $index = $this->index(new PhpactorWorkspace());

        self::assertTrue($index->isBareBuiltinFunctionFqn('App\\Demos\\gettype'));
        self::assertTrue($index->isBareBuiltinFunctionFqn('XPHP\\Lsp\\Resolver\\max'));
        self::assertTrue($index->isBareBuiltinFunctionFqn('\\App\\Demos\\gettype'), 'leading backslash tolerated');
    }

    public function testIsBareBuiltinFunctionFqnRejectsGlobalScope(): void
    {
        // A bare `gettype` with NO namespace prefix could legitimately
        // be a global-class lookup (PHP allows classes named after
        // functions, just confusingly).  Conservative: don't claim it
        // -- the regular pathFor / miss-log path handles it.
        $index = $this->index(new PhpactorWorkspace());

        self::assertFalse($index->isBareBuiltinFunctionFqn('gettype'));
        self::assertFalse($index->isBareBuiltinFunctionFqn('max'));
    }

    public function testIsBareBuiltinFunctionFqnRejectsNonFunctionShortNames(): void
    {
        // Last segment isn't a function name -> obviously not a
        // bare-builtin shape.  These should fall through to the
        // regular miss-log path.
        $index = $this->index(new PhpactorWorkspace());

        self::assertFalse($index->isBareBuiltinFunctionFqn('App\\Models\\User'));
        self::assertFalse($index->isBareBuiltinFunctionFqn('App\\Demos\\TotallyNotAFunction'));
    }

    public function testIsBareBuiltinFunctionFqnRejectsEmptyAndBackslashOnly(): void
    {
        $index = $this->index(new PhpactorWorkspace());

        self::assertFalse($index->isBareBuiltinFunctionFqn(''));
        self::assertFalse($index->isBareBuiltinFunctionFqn('\\'));
    }

    public function testIsBareBuiltinFunctionFqnRejectsUserDefinedFunctions(): void
    {
        // `ReflectionFunction::isInternal()` must come back FALSE for
        // a user-defined function.  We declare a GLOBAL one in the
        // test process and confirm the predicate doesn't classify it
        // as a builtin -- otherwise a workspace that happened to load
        // a vendor file matching a user-class short-name could
        // accidentally suppress a legitimate class-name miss.
        if (!function_exists('xphp_test_user_func_global')) {
            eval('function xphp_test_user_func_global(): void {}');
        }
        $index = $this->index(new PhpactorWorkspace());

        // function_exists('xphp_test_user_func_global') === true,
        // ReflectionFunction(...)->isInternal() === false -> predicate
        // must return false.
        self::assertFalse($index->isBareBuiltinFunctionFqn(
            'App\\Demos\\xphp_test_user_func_global',
        ));
    }

    private function index(PhpactorWorkspace $workspace): FqnIndex
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        return new FqnIndex($workspace, $cache, $parser, $this->root);
    }

    private function writeFile(string $relativePath, string $contents): void
    {
        $path = $this->root . '/' . $relativePath;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        file_put_contents($path, $contents);
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
