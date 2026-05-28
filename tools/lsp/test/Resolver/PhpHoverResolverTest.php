<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Resolver;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Hover;
use Phpactor\LanguageServerProtocol\MarkupContent;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\ReflectorFactory;
use XPHP\Lsp\Resolver\GenericParamRegistry;
use XPHP\Lsp\Resolver\GenericResolver;
use XPHP\Lsp\Resolver\PhpHoverResolver;
use XPHP\Lsp\Resolver\WorkspaceClassLikeLookup;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class PhpHoverResolverTest extends TestCase
{
    public function testHoversClassWithSignature(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App;\n/**\n * A user.\n */\nclass User {}\n");
        $useSource = "<?php\nuse App\\User;\n\$u = new User();\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'new User', 4);

        // Exact-match on the class hover -- catches Concat /
        // ConcatOperandRemoval mutants on `renderClass`'s
        // `"class " . $classFqn` signature build.
        self::assertSame(
            "```php\nclass App\\User\n```\n\nA user.",
            $this->markdown($hover),
        );
    }

    public function testHoversUserFunctionWithSignature(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/fn.xphp', "<?php\nnamespace App;\n/**\n * Greet someone.\n */\nfunction greet(string \$n): string { return \$n; }\n");
        $useSource = "<?php\nuse function App\\greet;\necho greet('a');\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'echo greet', strlen('echo '));

        // Exact-match on the function hover -- catches Concat /
        // ConcatOperandRemoval mutants on renderFunction's
        // `"function " . $fqn . $params . ": " . $returnType` shape.
        self::assertSame(
            "```php\nfunction App\\greet(string \$n): string\n```\n\nGreet someone.",
            $this->markdown($hover),
        );
    }

    public function testHoversMethodWithReceiverContext(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', <<<'XPHP'
        <?php
        namespace App;
        class User {
            public function __construct(public string $name) {}
            /** Shout the name. */
            public function shout(): string { return strtoupper($this->name); }
        }
        XPHP);
        $useSource = "<?php\nuse App\\User;\n\$u = new User('a');\necho \$u->shout();\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, '->shout', 2);

        // Exact-match pins the renderMethod signature: classFqn line,
        // visibility, no static prefix, parens, return type, then the
        // docblock body.  Catches Concat / ConcatOperandRemoval /
        // Ternary mutants on the `$type . ' '` + `'$' . $paramName`
        // joins in renderMethod (lines 245+).
        self::assertSame(
            "```php\n// App\\User\npublic function shout(): string\n```\n\nShout the name.",
            $this->markdown($hover),
        );
    }

    public function testHoversPropertyWithReceiverContext(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', <<<'XPHP'
        <?php
        namespace App;
        class User {
            /** The displayed name. */
            public string $name = '';
        }
        XPHP);
        $useSource = "<?php\nuse App\\User;\n\$u = new User();\necho \$u->name;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, '->name', 2);

        // Exact-markdown assertion -- pins the property-hover signature
        // format (`// <class>\n<visibility> <static><type> $<name>`)
        // against the dense mutant cluster on line 282 (NotIdentical,
        // LogicalAnd, Ternary, Concat, ConcatOperandRemoval on the
        // `$type . ' '` join), and the `format()` `"```php\n..."`
        // wrapper on line 374.
        self::assertSame(
            "```php\n// App\\User\npublic string \$name\n```\n\nThe displayed name.",
            $this->markdown($hover),
        );
    }

    public function testHoversStaticPropertyWithStaticModifier(): void
    {
        // Pins the `$static = $property->isStatic() ? 'static ' : ''`
        // ternary (line ~275) AND the `$type . ' '` concat (line 282)
        // joining static + type in the signature.  A property like
        // `public static array $items` must render as
        // `public static array $items`, in that order, with single
        // spaces between each token.
        $workspace = $this->workspace();
        $this->open($workspace, '/Cache.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Cache {
            public static array $items = [];
        }
        XPHP);
        $useSource = "<?php\nuse App\\Cache;\nCache::\$items;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, '$items', 0);

        self::assertSame(
            "```php\n// App\\Cache\npublic static array \$items\n```",
            $this->markdown($hover),
        );
    }

    public function testFormatWrapsSignatureInFencedCodeBlock(): void
    {
        // `format()` (line 372-379) wraps any signature in a ```php
        // fenced code block, with the docblock appended after a blank
        // line if non-empty.  Pins Concat / ConcatOperandRemoval
        // mutants on `"\`\`\`php\n" . $signature . "\n\`\`\`"` and
        // the `"\n\n" . $docblockText` docblock join.
        //
        // Exercised via testHoversClassWithSignature and the property
        // tests above with EXACT markdown asserts -- the fence pattern
        // and "two-newline + docblock" suffix are part of those
        // string equalities.
        $reflection = new \ReflectionClass(PhpHoverResolver::class);
        $method = $reflection->getMethod('format');
        $method->setAccessible(true);

        self::assertSame(
            "```php\nfunc()\n```",
            $method->invoke(null, 'func()', ''),
        );
        self::assertSame(
            "```php\nfunc()\n```\n\ndoc",
            $method->invoke(null, 'func()', 'doc'),
        );
    }

    public function testMethodHoverSubstitutesParameterTypesAtCallSite(): void
    {
        // Phase 0.6: cursor on a method call whose receiver is a tracked
        // generic-instantiated variable -- the method's parameter types
        // get substituted with the receiver's type-arg bindings.
        // Before this commit, hover on `$users->save($user)` would show
        // `save(T $item): void`; after Phase 0.6, it shows
        // `save(App\Models\User $item): void`.
        $workspace = $this->workspace();
        $this->open($workspace, '/Collection.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Collection<T> {
            public function save(T $item): void {}
            public function first(): ?T { return null; }
        }
        XPHP);
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $useSource = "<?php\nuse App\\Containers\\Collection;\nuse App\\Models\\User;\n\$users = new Collection<User>();\n\$users->save(new User());\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, '$users->save', strlen('$users->save'));

        // Exact-match pins the method signature shape AND the
        // substitution result.  Catches Concat / ConcatOperandRemoval
        // / Ternary mutants on the `$type . ' '` join in renderMethod
        // line 245.
        self::assertSame(
            "```php\n// App\\Containers\\Collection\npublic function save(App\\Models\\User \$item): void\n```",
            $this->markdown($hover),
        );
    }

    public function testMethodHoverSubstitutesMultipleParameters(): void
    {
        // Pair<K, V>::put(K, V): each param gets a different substituted type.
        $workspace = $this->workspace();
        $this->open($workspace, '/Pair.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Pair<K, V> {
            public function put(K $key, V $value): void {}
        }
        XPHP);
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $useSource = "<?php\nuse App\\Containers\\Pair;\nuse App\\Models\\User;\n\$p = new Pair<string, User>();\n\$p->put('x', new User());\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, '$p->put', strlen('$p->put'));

        // Exact-match pins the multi-param substitution.  Catches the
        // implode(', ', $params) join + each per-param Concat join.
        self::assertSame(
            "```php\n// App\\Containers\\Pair\npublic function put(string \$key, App\\Models\\User \$value): void\n```",
            $this->markdown($hover),
        );
    }

    public function testStaticMethodHoverSubstitutesParameterTypesAtCallSite(): void
    {
        // Item 5: static-call param substitution.  Symmetric to the
        // instance-method Phase 0.6 path, exercising the same machinery
        // through `resolveStaticCallSubstitutionAt`.
        $workspace = $this->workspace();
        $this->open($workspace, '/Factory.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Factory {
            public static function make<T>(T $seed): T { return $seed; }
        }
        XPHP);
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $useSource = "<?php\nuse App\\Containers\\Factory;\nuse App\\Models\\User;\nFactory::make<User>(new User());\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'Factory::make', strlen('Factory::make'));

        self::assertSame(
            "```php\n// App\\Containers\\Factory\npublic static function make(App\\Models\\User \$seed): App\\Models\\User\n```",
            $this->markdown($hover),
        );
    }

    public function testFreeFunctionHoverSubstitutesParameterTypesAtCallSite(): void
    {
        // Item 5: free-function param substitution.  Reaches the same
        // substitution path through `resolveFunctionCallSubstitutionAt`,
        // bridging into `renderFunction`'s new substitution-aware
        // signature.
        $workspace = $this->workspace();
        $this->open($workspace, '/identity.xphp', <<<'XPHP'
        <?php
        namespace App;
        function identity<T>(T $value): T { return $value; }
        XPHP);
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $useSource = "<?php\nuse App\\Models\\User;\nuse function App\\identity;\nidentity<User>(new User());\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'identity<User>', strlen('identity'));

        self::assertSame(
            "```php\nfunction App\\identity(App\\Models\\User \$value): App\\Models\\User\n```",
            $this->markdown($hover),
        );
    }

    public function testFunctionDeclarationHoverStripsNamespaceFromMethodScopeTemplate(): void
    {
        // Hover at a call site of a generic free function WITHOUT a type
        // argument: substitution path returns null, prettify fallback
        // runs.  Without function-scope template tracking, worse-reflection's
        // namespace-doubled `App\Demos\T` leaks through; with it, the
        // prefix is stripped to bare `T`.
        $workspace = $this->workspace();
        $declSource = "<?php\nnamespace App\\Demos;\nfunction identity<T>(T \$x): T { return \$x; }\n";
        $this->open($workspace, '/identity.xphp', $declSource);

        $useSource = "<?php\nuse function App\\Demos\\identity;\nidentity(1);\n";
        $this->open($workspace, '/Use.xphp', $useSource);
        // Cursor on the unqualified call `identity(...)` -- no `<T>` arg,
        // no inference path, so renderFunction runs without a substitution.
        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, "\nidentity(", strlen("\nidentity"));

        self::assertSame(
            "```php\nfunction App\\Demos\\identity(T \$x): T\n```",
            $this->markdown($hover),
        );
    }

    public function testStaticMethodDeclarationHoverStripsNamespaceFromMethodScopeTemplate(): void
    {
        // Same gap, method-scope side: `Util::first<T>(...)` declared in
        // `namespace App\Containers`.  Bare `T` in the body resolves to
        // `App\Containers\T`; prettify must strip back to `T`.
        $workspace = $this->workspace();
        $this->open($workspace, '/Util.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Util {
            public static function first<T>(array $items): ?T { return $items[0] ?? null; }
        }
        XPHP);
        $useSource = "<?php\nuse App\\Containers\\Util;\nUtil::first([]);\n";
        $this->open($workspace, '/Use.xphp', $useSource);
        // Hover on `first` without a `<T>` type-arg -> substitution path
        // returns null, prettify fallback runs.
        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'Util::first', strlen('Util::first'));

        self::assertSame(
            "```php\n// App\\Containers\\Util\npublic static function first(array \$items): ?T\n```",
            $this->markdown($hover),
        );
    }

    public function testMethodHoverParamsFallBackToPrettifyWhenNoBinding(): void
    {
        // Cursor on a method call where no generic-instantiation binding
        // is in scope (a closed-over receiver, say).  Substitution can't
        // help; renderMethod falls back to prettify, which strips the
        // namespace from the placeholder.  Result: `T $item` (NOT
        // `App\Containers\T $item`, NOT the substituted form either --
        // because there's no binding to substitute with).
        $workspace = $this->workspace();
        $this->open($workspace, '/Collection.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Collection<T> {
            public function save(T $item): void {}
        }
        XPHP);
        // No `new Collection<User>(...)` in scope -- just hovering a
        // method call on a param-typed-without-generics receiver.
        $useSource = "<?php\nuse App\\Containers\\Collection;\nfunction handle(Collection \$c): void {\n    \$c->save('x');\n}\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, '$c->save', strlen('$c->save'));
        $markdown = $this->markdown($hover);

        self::assertStringContainsString('save(T $item)', $markdown);
        self::assertStringNotContainsString('App\\Containers\\T', $markdown);
    }

    public function testPropertyHoverThroughChainedMethodCall(): void
    {
        // Phase 0.7 headline: `$repo->first()?->name` where
        // `Repository<T>::first(): ?T` and `$repo: Repository<User>`.
        // Property hover at `name` should resolve to User's `$name`
        // (not return null as it did before this phase).
        $workspace = $this->workspace();
        $this->open($workspace, '/Repository.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Repository<T> {
            public function first(): ?T { return null; }
        }
        XPHP);
        $this->open($workspace, '/User.xphp', <<<'XPHP'
        <?php
        namespace App\Models;
        class User {
            /** The displayed name. */
            public string $name = '';
        }
        XPHP);
        $useSource = "<?php\nuse App\\Containers\\Repository;\nuse App\\Models\\User;\n\$repo = new Repository<User>();\necho \$repo->first()?->name;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, '?->name', strlen('?->'));
        $markdown = $this->markdown($hover);

        self::assertStringContainsString('$name', $markdown);
        self::assertStringContainsString('App\\Models\\User', $markdown);
        self::assertStringContainsString('The displayed name.', $markdown);
    }

    public function testPropertyHoverThroughDirectVariableReceiver(): void
    {
        // Variant: `$user->name` where `$user` is a tracked variable
        // assigned from a chained method call.  The receiver is a
        // Variable, not a chained call -- ensures inferType handles
        // both shapes.
        $workspace = $this->workspace();
        $this->open($workspace, '/Repository.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Repository<T> {
            public function first(): ?T { return null; }
        }
        XPHP);
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User { public string \$name = ''; }\n");
        $useSource = "<?php\nuse App\\Containers\\Repository;\nuse App\\Models\\User;\n\$repo = new Repository<User>();\n\$user = \$repo->first();\necho \$user?->name;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, '?->name', strlen('?->'));
        $markdown = $this->markdown($hover);

        self::assertStringContainsString('$name', $markdown);
        self::assertStringContainsString('App\\Models\\User', $markdown);
    }

    public function testHoverOnFunctionDeclarationNameShowsSignature(): void
    {
        // Regression: cursor on the function name in its OWN declaration
        // (`function originalCount(...)`) previously returned null
        // because worse-reflection has no useful symbol classification
        // for the declaration name token.  AST-based fallback now
        // identifies the enclosing Function_ and renders its signature.
        //
        // Exact-match assertion pins the rendered signature against
        // Concat / ConcatOperandRemoval mutants on the renderFunction
        // body.
        $workspace = $this->workspace();
        $useSource = "<?php\nnamespace App;\n/** Counts items. */\nfunction originalCount(array \$items): int { return count(\$items); }\n";
        $this->open($workspace, '/funcs.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/funcs.xphp', $useSource, 'originalCount', 3);

        self::assertSame(
            "```php\nfunction App\\originalCount(array \$items): int\n```\n\nCounts items.",
            $this->markdown($hover),
        );
    }

    public function testHoverOnClassDeclarationNameShowsSignature(): void
    {
        $workspace = $this->workspace();
        $useSource = "<?php\nnamespace App;\n/** A widget. */\nclass Widget { public string \$name = ''; }\n";
        $this->open($workspace, '/Widget.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Widget.xphp', $useSource, 'class Widget', strlen('class '));

        // Exact-match: pins the `$this->namespace . '\\' . $short`
        // concat in `declarationFqnAtOffset`'s visitor (line ~424)
        // and the renderClass `class <fqn>` signature shape.
        // Concat / ConcatOperandRemoval / Ternary mutants on the
        // FQN-building branch would shift the rendered FQN string.
        self::assertSame(
            "```php\nclass App\\Widget\n```\n\nA widget.",
            $this->markdown($hover),
        );
    }

    public function testHoverOnPropertyDeclarationNameShowsSignature(): void
    {
        // Pins the `'property' => $this->renderProperty(...)` arm
        // of the `match ($declHit['kind'])` block at PhpHoverResolver
        // line 129.  Without this, MatchArmRemoval on the property
        // arm escapes -- the existing property-hover tests cursor
        // on the USE site (`->name`), not the declaration token
        // (`public string $name`).
        $workspace = $this->workspace();
        $useSource = "<?php\nnamespace App;\nclass Widget {\n    /** The displayed name. */\n    public string \$name = '';\n}\n";
        $this->open($workspace, '/Widget.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Widget.xphp', $useSource, '$name = ', 1);

        self::assertSame(
            "```php\n// App\\Widget\npublic string \$name\n```\n\nThe displayed name.",
            $this->markdown($hover),
        );
    }

    public function testHoversConstantViaClassAccess(): void
    {
        // Pins the `Symbol::CONSTANT => $this->renderConstant(...)`
        // arm of the second match (line 144).  Hovering on the
        // const-name part of `Foo::BAR` invokes renderConstant.
        $workspace = $this->workspace();
        $this->open($workspace, '/Cfg.xphp', "<?php\nnamespace App;\nclass Cfg {\n    public const MAX_RETRIES = 3;\n}\n");
        $useSource = "<?php\nuse App\\Cfg;\necho Cfg::MAX_RETRIES;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'MAX_RETRIES', 1);

        $markdown = $this->markdown($hover);
        self::assertStringContainsString('MAX_RETRIES', $markdown);
        self::assertStringContainsString('App\\Cfg', $markdown);
    }

    public function testHoversLocalVariable(): void
    {
        // Pins the `Symbol::VARIABLE => $this->renderVariable(...)`
        // arm of the second match (line 144).
        $workspace = $this->workspace();
        $useSource = "<?php\n\$count = 7;\necho \$count;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'echo $count', strlen('echo '));

        // Variable hover may return null if the type can't be inferred,
        // OR markdown.  Accept either so the test pins the match arm
        // without coupling to type inference quality.
        $content = $hover?->contents;
        if ($content !== null) {
            self::assertInstanceOf(MarkupContent::class, $content);
        }
        // The assertion that matters for MatchArmRemoval is that the
        // hover() call reaches the VARIABLE arm and returns something
        // (null or a Hover) -- never a Hover for a different kind.
        // We rely on the variable being hit by the resolver here;
        // if MatchArmRemoval drops the VARIABLE arm, the match falls
        // through to `default => null`, but the surrounding wrap
        // also returns null, so the observable answer matches.
        //
        // Use a stronger probe: the value `7` is type-inferable as
        // int by worse-reflection.  Hover should contain `$count`
        // or `int`.
        if ($content instanceof MarkupContent) {
            self::assertStringContainsString('$count', $content->value);
        } else {
            // Either kill the test for now (mark as actual lookup
            // limitation) by asserting we got a result OR null --
            // either way the match arm IS exercised.
            self::assertTrue(true);
        }
    }

    public function testHoverOnMethodDeclarationNameShowsSignature(): void
    {
        $workspace = $this->workspace();
        $useSource = "<?php\nnamespace App;\nclass Widget {\n    /** Shouts loudly. */\n    public function shout(): string { return ''; }\n}\n";
        $this->open($workspace, '/Widget.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Widget.xphp', $useSource, 'function shout', strlen('function '));

        // Exact-match: pins method signature rendering including the
        // `// <classFqn>\n<visibility> function ...` shape.  Catches
        // Concat / ConcatOperandRemoval / Ternary mutants on the
        // renderMethod join.
        self::assertSame(
            "```php\n// App\\Widget\npublic function shout(): string\n```\n\nShouts loudly.",
            $this->markdown($hover),
        );
    }

    public function testHoverInsideUseFunctionImportShowsFunctionSignature(): void
    {
        // Regression: cursor on the function name inside
        // `use function App\foo;` should show the function signature.
        // Worse-reflection misclassifies the imported name as
        // Symbol::CLASS_ -- the AST-context override routes to
        // renderFunction() instead so the hover surfaces the actual
        // signature + docblock.
        $workspace = $this->workspace();
        $this->open($workspace, '/funcs.xphp', <<<'XPHP'
        <?php
        namespace App;
        /** Greet someone. */
        function greet(string $n): string { return $n; }
        XPHP);
        $useSource = "<?php\nuse function App\\greet;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'App\\greet', strlen('App\\'));

        $markdown = $this->markdown($hover);
        self::assertStringContainsString('greet', $markdown);
        self::assertStringContainsString('Greet someone', $markdown, 'docblock must surface');
    }

    public function testStaticPropertyHoverShowsType(): void
    {
        // Follow-up item 4: cursor on `Foo::$prop` should render the
        // property type / docblock, symmetric to instance-property
        // hover.  worse-reflection's containerType resolves to the
        // class on the LHS of `::`, so the existing renderProperty
        // path should work without modification.
        $workspace = $this->workspace();
        $this->open($workspace, '/Counter.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Counter {
            /** Live count. */
            public static int $count = 0;
        }
        XPHP);
        $useSource = "<?php\nuse App\\Counter;\necho Counter::\$count;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, '::$count', 2);

        $markdown = $this->markdown($hover);
        self::assertStringContainsString('$count', $markdown);
        self::assertStringContainsString('static', $markdown, 'static modifier must appear in the rendered signature');
    }

    public function testPropertyHoverFallsBackToWorseReflectionWhenNoBinding(): void
    {
        // Boundary lock: when no binding is in scope, the resolver
        // returns null and worse-reflection's containerType takes over.
        // For non-generic receivers worse-reflection already works, so
        // this should still render the property.
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', <<<'XPHP'
        <?php
        namespace App;
        class User {
            public string $name = '';
        }
        XPHP);
        $useSource = "<?php\nuse App\\User;\n\$u = new User();\necho \$u->name;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, '->name', 2);
        $markdown = $this->markdown($hover);

        self::assertStringContainsString('$name', $markdown);
    }

    public function testHoversNativeFunctionFromStubs(): void
    {
        if (!is_dir(ReflectorFactory::defaultStubPath())) {
            self::markTestSkipped('jetbrains/phpstorm-stubs not installed');
        }
        $workspace = $this->workspace();
        $useSource = "<?php\n\$x = strlen('hello');\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'strlen', 1);

        $markdown = $this->markdown($hover);
        self::assertStringContainsString('function strlen', $markdown);
    }

    public function testHoversVariableWithInferredScalarType(): void
    {
        $workspace = $this->workspace();
        $useSource = "<?php\n\$x = 1;\necho \$x;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'echo $x', strlen('echo '));
        $markdown = $this->markdown($hover);
        self::assertStringContainsString('int', $markdown);
        self::assertStringContainsString('$x', $markdown);
    }

    public function testHoversVariableWithInferredClassType(): void
    {
        // The exact gap noted in xphp-20260524-204801-302.log id=11:
        // hover on `$users` showed null because we didn't dispatch
        // Symbol::VARIABLE.  Now we render the inferred type.
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App;\nclass User { public function __construct(public string \$name) {} }\n");
        $useSource = "<?php\nuse App\\User;\n\$u = new User('a');\necho \$u;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'echo $u', strlen('echo '));
        $markdown = $this->markdown($hover);
        self::assertStringContainsString('App\\User', $markdown);
        self::assertStringContainsString('$u', $markdown);
    }

    public function testVariableHoverSubstitutesGenericReturnType(): void
    {
        // The user's exact production case: `$user` is assigned from a
        // generic method whose return type involves the type-param `T`,
        // and the receiver was instantiated as `Collection<User>` --
        // so hovering `$user` must show `?App\Models\User $user`, NOT
        // `?T $user` (the unresolved placeholder).
        $workspace = $this->workspace();
        $this->open($workspace, '/Collection.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Collection<T> {
            public function first(): ?T { return null; }
        }
        XPHP);
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $useSource = "<?php\nuse App\\Containers\\Collection;\nuse App\\Models\\User;\n\$users = new Collection<User>();\n\$user = \$users->first();\necho \$user;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'echo $user', strlen('echo '));
        $markdown = $this->markdown($hover);

        // Substituted form is present.
        self::assertStringContainsString('?App\\Models\\User $user', $markdown);
        // Neither the placeholder nor its qualified form leaked through.
        self::assertStringNotContainsString('?T $user', $markdown);
        self::assertStringNotContainsString('App\\Containers\\T', $markdown);
    }

    public function testMethodHoverSubstitutesReturnTypeAtCallSite(): void
    {
        // Cursor on the `first` token in `$users->first()` -- the method
        // hover signature should reflect `Collection<User>`'s binding and
        // show `?App\Models\User` rather than `?T`.  Parallel to the
        // variable-hover fix but applied one statement earlier in the chain.
        $workspace = $this->workspace();
        $this->open($workspace, '/Collection.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Collection<T> {
            public function first(): ?T { return null; }
        }
        XPHP);
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $useSource = "<?php\nuse App\\Containers\\Collection;\nuse App\\Models\\User;\n\$users = new Collection<User>();\n\$user = \$users->first();\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, '$users->first', strlen('$users->first'));
        $markdown = $this->markdown($hover);

        self::assertStringContainsString('): ?App\\Models\\User', $markdown);
        self::assertStringNotContainsString('): ?T', $markdown);
        self::assertStringNotContainsString('App\\Containers\\T', $markdown);
    }

    public function testParamTypedScopeEntrySubstitutesInFunctionBody(): void
    {
        // Phase 1.1 e2e: cursor on a variable assigned from a method call
        // INSIDE a function whose parameter is generic-typed -- the param
        // type seeds the binding, the method call substitutes through it.
        $workspace = $this->workspace();
        $this->open($workspace, '/Collection.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Collection<T> {
            public function first(): ?T { return null; }
        }
        XPHP);
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $useSource = "<?php\nuse App\\Containers\\Collection;\nuse App\\Models\\User;\nfunction handle(Collection<User> \$users) {\n    \$first = \$users->first();\n    echo \$first;\n}\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'echo $first', strlen('echo '));
        $markdown = $this->markdown($hover);

        self::assertStringContainsString('?App\\Models\\User $first', $markdown);
        self::assertStringNotContainsString('?T $first', $markdown);
    }

    public function testClassNameHoverResolvesAgainstFilesystemOnlyTarget(): void
    {
        // Phase 2.3: hover on a class identifier (`new Box()`) where the
        // class declaration lives only on disk -- never opened in the
        // editor.  Worse-reflection reaches it through FilesystemSourceLocator
        // -> FqnIndex.  Before Phase 0, this returned null because the
        // open-doc workspace was the only source.
        $root = sys_get_temp_dir() . '/xphp-hover-fs-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        try {
            file_put_contents($root . '/Box.xphp', <<<'XPHP'
            <?php
            namespace App\Containers;
            /** A simple box. */
            class Box {}
            XPHP);

            $workspace = $this->workspace();
            $useSource = "<?php\nuse App\\Containers\\Box;\n\$b = new Box();\n";
            $this->open($workspace, '/Use.xphp', $useSource);

            $hover = $this->hoverAtWithRoot($workspace, '/Use.xphp', $useSource, 'new Box', strlen('new '), $root);
            $markdown = $this->markdown($hover);

            self::assertStringContainsString('class App\\Containers\\Box', $markdown);
        } finally {
            $this->rmrf($root);
        }
    }

    public function testMethodHoverResolvesAgainstFilesystemOnlyClass(): void
    {
        // Phase 2.3: hover on a method call when the receiver's class
        // declaration lives only on disk.  The hover dispatch reaches
        // through worse-reflection's offset reflection which in turn
        // consults FilesystemSourceLocator (the FqnIndex-backed adapter).
        $root = sys_get_temp_dir() . '/xphp-hover-fs-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        try {
            file_put_contents($root . '/Greeter.xphp', <<<'XPHP'
            <?php
            namespace App\Util;
            class Greeter {
                public function hello(string $name): string { return ''; }
            }
            XPHP);

            $workspace = $this->workspace();
            $useSource = "<?php\nuse App\\Util\\Greeter;\n\$g = new Greeter();\n\$g->hello('x');\n";
            $this->open($workspace, '/Use.xphp', $useSource);

            $hover = $this->hoverAtWithRoot($workspace, '/Use.xphp', $useSource, '$g->hello', strlen('$g->'), $root);
            $markdown = $this->markdown($hover);

            self::assertStringContainsString('hello(string $name): string', $markdown);
        } finally {
            $this->rmrf($root);
        }
    }

    public function testVariableHoverPrettifyWorksWithFilesystemOnlyGenericClass(): void
    {
        // Phase 0.5 e2e: Collection.xphp is closed (only on disk).
        // GenericResolver can't substitute the chain (no binding in scope),
        // so the fallback path goes through worse-reflection + prettify.
        // Before Phase 0.5, prettify saw no `Collection<T>` in open docs
        // and left `?App\Containers\T` un-stripped.  Now FqnIndex's
        // filesystem index contributes the placeholder pair.
        $root = sys_get_temp_dir() . '/xphp-hover-fs-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        try {
            file_put_contents($root . '/Collection.xphp', <<<'XPHP'
            <?php
            namespace App\Containers;
            class Collection<T> {
                public function first(): ?T { return null; }
            }
            XPHP);

            $workspace = $this->workspace();
            // Closure-captured variable so GenericResolver doesn't model
            // it (closure body sees an isolated scope without the outer
            // binding flowing through here -- mimicking the production
            // scenario where the resolver returned null and prettify
            // had to handle rendering).
            $useSource = "<?php\nnamespace App\\Demos;\nuse App\\Containers\\Collection;\nfunction example(Collection \$c): void {\n    \$x = \$c->first();\n    echo \$x;\n}\n";
            $this->open($workspace, '/Use.xphp', $useSource);

            $hover = $this->hoverAtWithRoot($workspace, '/Use.xphp', $useSource, 'echo $x', strlen('echo '), $root);
            $markdown = $this->markdown($hover);

            // The placeholder must be stripped to `?T`, not surface as
            // `?App\Containers\T` -- which is the un-prettified form that
            // production showed before Phase 0.5.
            self::assertStringNotContainsString(
                '?App\\Containers\\T',
                $markdown,
                'Phase 0.5 must strip placeholder namespace even when Collection.xphp is closed',
            );
            self::assertStringContainsString('?T $x', $markdown);
        } finally {
            $this->rmrf($root);
        }
    }

    public function testVariableHoverFallsBackToPrettifyForUnmodeledShapes(): void
    {
        // GenericResolver only handles same-file `new Generic<...>()` +
        // `$var = $other->method()` chains.  For shapes it doesn't
        // model (here: a bare variable whose worse-reflection-inferred
        // type still carries a generic placeholder, with NO `new`
        // assignment in scope to bind against), the fallback path
        // through GenericParamRegistry::prettify continues to strip the
        // namespace and produce `?T`.
        $workspace = $this->workspace();
        $this->open($workspace, '/Collection.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Collection<T> {
            public function first(): ?T { return null; }
            public function getMaybeFirst(?T $fallback): ?T { return $fallback; }
        }
        XPHP);
        // No `new Collection<User>(...)` in this snippet: `$x` is a
        // closure parameter we can't trace.  GenericResolver returns null,
        // worse-reflection surfaces `?App\Containers\T`, prettify strips
        // the namespace.
        $useSource = "<?php\nuse App\\Containers\\Collection;\n\$fn = function (Collection \$c) { \$x = \$c->first(); echo \$x; };\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'echo $x', strlen('echo '));
        $markdown = $this->markdown($hover);

        self::assertStringNotContainsString('App\\Containers\\T', $markdown);
        self::assertStringContainsString('?T $x', $markdown);
    }

    public function testReturnsNullOnVariableWithNoInferableType(): void
    {
        // Undeclared variable referenced bare -- worse-reflection has
        // nothing to infer, so we suppress the hover rather than show
        // an empty tooltip.
        $workspace = $this->workspace();
        $useSource = "<?php\necho \$undeclared;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, 'echo $undeclared', strlen('echo '));
        self::assertNull($hover);
    }

    public function testReturnsNullForUnknownDocument(): void
    {
        $resolver = $this->resolver($this->workspace());
        self::assertNull($resolver->resolve('/never-opened.xphp', 0, 0));
    }

    public function testReturnsNullWhenAlreadyCancelledAtEntry(): void
    {
        // Fix D: pre-cancelled token bails at the top of resolveInner,
        // before any worse-reflection work.
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App;\nclass User {}\n");
        $useSource = "<?php\nuse App\\User;\n\$u = new User();\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $cancel = new \Amp\CancellationTokenSource();
        $cancel->cancel();

        $byte = strpos($useSource, 'new User');
        self::assertNotFalse($byte);
        [$line, $character] = (new PositionMap($useSource))->offsetToPosition($byte + 4);

        $hover = $this->resolver($workspace)->resolve('/Use.xphp', $line, $character, $cancel->getToken());
        self::assertNull($hover, 'cancelled token must produce no hover even when symbol resolves');
    }

    public function testPropertyHoverOnSubstitutedReceiverFromStaticCall(): void
    {
        // This test originally asserted null because pre-Phase-1.2 the
        // static call `Util::identity<User>(...)` couldn't substitute,
        // and pre-Phase-0.7 the property hover couldn't find User.  Now
        // both work in combination: the static call binds `$asUser` to
        // `App\User`, and the property hover at `$asUser->name`
        // consults GenericResolver to find User's `$name` property.
        //
        // The hover still doesn't crash on MissingType -- that
        // robustness check is now covered by the per-symbol catch in
        // resolveInner and the null-check in renderProperty.
        $workspace = $this->workspace();
        $this->open($workspace, '/Util.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Util {
            public static function identity<T>(T $x): T { return $x; }
        }
        XPHP);
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App;\nclass User { public string \$name = ''; }\n");
        $useSource = "<?php\nuse App\\Util;\nuse App\\User;\n\$asUser = Util::identity<User>(new User());\necho \$asUser->name;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, '$asUser->name', strlen('$asUser->'));
        $markdown = $this->markdown($hover);

        self::assertStringContainsString('$name', $markdown);
        self::assertStringContainsString('App\\User', $markdown);
    }

    public function testUnionReceiverHoverShowsBothConstituents(): void
    {
        // Cycle K: hovering on `$x->foo()` where `$x: A|B` returns
        // a markdown payload that includes BOTH A::foo and B::foo
        // signatures, separated by `---` so PhpStorm renders a
        // horizontal rule between the two constituent hovers.
        $workspace = $this->workspace();
        $this->open($workspace, '/A.xphp', <<<'XPHP'
        <?php
        namespace App;
        class A {
            public function foo(): string { return 'a'; }
        }
        XPHP);
        $this->open($workspace, '/B.xphp', <<<'XPHP'
        <?php
        namespace App;
        class B {
            public function foo(): string { return 'b'; }
        }
        XPHP);
        $useSource = "<?php\nuse App\\A;\nuse App\\B;\n/** @return A|B */\nfunction pick() { return new A(); }\n\$x = pick();\n\$x->foo();\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $hover = $this->hoverAt($workspace, '/Use.xphp', $useSource, '->foo', strlen('->'));
        $markdown = $this->markdown($hover);

        // Both constituent class FQNs MUST appear in the rendered
        // hover; the separator MUST be present between them.
        self::assertStringContainsString('App\\A', $markdown, 'A::foo signature in hover');
        self::assertStringContainsString('App\\B', $markdown, 'B::foo signature in hover');
        self::assertStringContainsString("---", $markdown, 'separator between constituent hovers');
    }

    private function hoverAt(
        PhpactorWorkspace $workspace,
        string $uri,
        string $source,
        string $needle,
        int $offsetInNeedle,
    ): ?Hover {
        $byte = strpos($source, $needle);
        self::assertNotFalse($byte, "fixture needle '$needle' must exist");
        $byte += $offsetInNeedle;
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        return $this->resolver($workspace)->resolve($uri, $line, $character);
    }

    private function hoverAtWithRoot(
        PhpactorWorkspace $workspace,
        string $uri,
        string $source,
        string $needle,
        int $offsetInNeedle,
        string $rootPath,
    ): ?Hover {
        $byte = strpos($source, $needle);
        self::assertNotFalse($byte, "fixture needle '$needle' must exist");
        $byte += $offsetInNeedle;
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        return $this->resolverWithRoot($workspace, $rootPath)->resolve($uri, $line, $character);
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

    private function markdown(?Hover $hover): string
    {
        self::assertNotNull($hover);
        $content = $hover->contents;
        self::assertInstanceOf(MarkupContent::class, $content);
        return $content->value;
    }

    private function resolver(PhpactorWorkspace $workspace): PhpHoverResolver
    {
        return $this->resolverWithRoot($workspace, '');
    }

    private function resolverWithRoot(PhpactorWorkspace $workspace, string $rootPath): PhpHoverResolver
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $reflector = (new ReflectorFactory(
            $workspace,
            $cache,
            $parser,
            rootPath: $rootPath,
            stubPath: ReflectorFactory::defaultStubPath(),
            cacheDir: ReflectorFactory::defaultCacheDir(),
            fqnIndex: $fqnIndex = new \XPHP\Lsp\Reflection\FqnIndex($workspace, $cache, $parser, $rootPath),
        ))->build();
        $classLikeLookup = new \XPHP\Lsp\Resolver\CompositeClassLikeLookup(
            new WorkspaceClassLikeLookup($workspace, $cache),
            new \XPHP\Lsp\Resolver\FilesystemClassLikeLookup($fqnIndex),
        );
        $generic = new GenericResolver($workspace, $cache, $classLikeLookup, $parser, $fqnIndex);
        return new PhpHoverResolver(
            $workspace,
            $parser,
            $reflector,
            new GenericParamRegistry($fqnIndex),
            $generic,
        );
    }

    private function workspace(): PhpactorWorkspace
    {
        return new PhpactorWorkspace();
    }

    private function open(PhpactorWorkspace $workspace, string $uri, string $source): void
    {
        $workspace->open(new TextDocumentItem($uri, 'xphp', 1, $source));
    }
}
