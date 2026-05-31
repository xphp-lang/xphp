<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Resolver;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\CompletionItem;
use Phpactor\LanguageServerProtocol\CompletionItemKind;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\ReflectorFactory;
use XPHP\Lsp\Resolver\PhpCompletionResolver;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class PhpCompletionResolverTest extends TestCase
{
    public function testCompletesPublicMethodsAfterArrow(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', <<<'XPHP'
        <?php
        namespace App;
        class User {
            public function __construct(public string $name) {}
            public function shout(): string { return ''; }
            public function whisper(): string { return ''; }
            private function _secret(): void {}
        }
        XPHP);
        $useSource = "<?php\nuse App\\User;\n\$u = new User('a');\n\$u->\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, '$u->', 4);

        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);
        self::assertContains('shout', $labels);
        self::assertContains('whisper', $labels);
        self::assertNotContains('_secret', $labels, 'private methods must be filtered out');
    }

    public function testPrivateAndProtectedMembersVisibleInsideSameClass(): void
    {
        // Phase 3 polish: when the cursor is inside the same class as
        // the receiver, private + protected members are visible.  The
        // common case: writing `$this->_helper()` from inside the class
        // body itself.  Source has a valid existing method so the file
        // parses cleanly; the cursor sits on the existing `$this->` of
        // that method (not mid-edit), simulating the user re-typing
        // after the dot.
        $workspace = $this->workspace();
        $this->open($workspace, '/Account.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Account
        {
            private int $balance = 0;
            protected string $owner = '';
            public string $label = '';

            public function describe(): string
            {
                $marker = $this->label;
                return $marker;
            }
        }
        XPHP);

        $source = $workspace->get('/Account.xphp')->text;
        // Cursor on the `->` AFTER $this in `$marker = $this->label;`,
        // simulating member completion fired at that position.
        $items = $this->completeAt($workspace, '/Account.xphp', $source, '$this->', strlen('$this->'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('balance', $labels, 'private prop visible from same class');
        self::assertContains('owner', $labels, 'protected prop visible from same class');
        self::assertContains('label', $labels);
        self::assertContains('describe', $labels);
    }

    public function testPrivatePropertyVisibleWhenFileIsMidEditAndRegularParseFails(): void
    {
        // Regression for prod trace xphp-20260525-092358-161.log:
        // user types `$th` inside Collection::first() before triggering
        // completion at `$this->|`.  The mid-edit `$th\n        return ...`
        // makes the non-tolerant nikic parse throw, so the cached
        // ParseResult.ast is null.  enclosingClassFqnAt previously
        // returned null on cache miss -> isSameClass=false -> private
        // $items dropped silently.  Fix: fall back to parseTolerant.
        //
        // Worse-reflection still finds the class via FilesystemSourceLocator
        // (the on-disk copy is clean even when the in-memory buffer is
        // dirty), so the regression hinges purely on whether our
        // enclosingClassFqnAt recovers the enclosing class.  Use a
        // filesystem fixture so worse-reflection can reflect Collection
        // the same way it does in prod.
        $root = sys_get_temp_dir() . '/xphp-vis-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        try {
            $cleanSource = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Containers;\n\nclass Collection<T>\n{\n    private T[] \$items;\n\n    public function __construct(T ...\$items)\n    {\n        \$this->items = \$items;\n    }\n\n    public function first(): ?T\n    {\n        return \$this->items[0] ?? null;\n    }\n}\n";
            file_put_contents($root . '/Collection.xphp', $cleanSource);

            $workspace = $this->workspace();
            // In-memory buffer with the mid-edit `$th` that breaks the
            // non-tolerant parse.
            $dirtySource = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Containers;\n\nclass Collection<T>\n{\n    private T[] \$items;\n\n    public function __construct(T ...\$items)\n    {\n        \$this->items = \$items;\n    }\n\n    public function first(): ?T\n    {\n        \$th\n        return \$this->items[0] ?? null;\n    }\n}\n";
            $this->open($workspace, $root . '/Collection.xphp', $dirtySource);

            $items = $this->resolverWithRoot($workspace, $root)->complete(
                $root . '/Collection.xphp',
                ...$this->lineCharFor($dirtySource, 'return $this->items[0]', strlen('return $this->')),
            );
            $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

            self::assertContains(
                'items',
                $labels,
                'private $items must surface even when the regular parse failed mid-edit',
            );
        } finally {
            if (is_dir($root)) {
                foreach (scandir($root) ?: [] as $e) {
                    if ($e !== '.' && $e !== '..') {
                        unlink($root . '/' . $e);
                    }
                }
                rmdir($root);
            }
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function lineCharFor(string $source, string $needle, int $offsetInNeedle): array
    {
        $byte = strpos($source, $needle);
        self::assertNotFalse($byte);
        $byte += $offsetInNeedle;
        return (new PositionMap($source))->offsetToPosition($byte);
    }

    private function resolverWithRoot(PhpactorWorkspace $workspace, string $rootPath): PhpCompletionResolver
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $workspaceSymbols = new \XPHP\Lsp\Handler\WorkspaceSymbols($workspace, $cache);
        $completionIndex = new \XPHP\Lsp\Resolver\CompletionIndex(
            $workspaceSymbols,
            ReflectorFactory::defaultStubPath(),
        );
        $fqnIndex = new \XPHP\Lsp\Reflection\FqnIndex($workspace, $cache, $parser, $rootPath);
        $reflector = (new ReflectorFactory(
            $workspace,
            $cache,
            $parser,
            rootPath: $rootPath,
            stubPath: ReflectorFactory::defaultStubPath(),
            cacheDir: ReflectorFactory::defaultCacheDir(),
            fqnIndex: $fqnIndex,
        ))->build();
        $classLikeLookup = new \XPHP\Lsp\Resolver\WorkspaceClassLikeLookup($workspace, $cache);
        return new PhpCompletionResolver(
            $workspace,
            $parser,
            $reflector,
            $completionIndex,
            $cache,
            new \XPHP\Lsp\Resolver\GenericParamRegistry($fqnIndex),
            new \XPHP\Lsp\Resolver\GenericResolver($workspace, $cache, $classLikeLookup, $parser, $fqnIndex),
        );
    }

    public function testPrivatePropertyVisibleInsideGenericClassWithTBracketSugar(): void
    {
        // Regression for the prod trace from xphp-20260525-015638-815.log
        // id=82/117 -- the `$this->|` completion inside Collection<T>
        // returned only the 3 public methods, dropping the private
        // `$items`.  T[] -> array rewrites the source (+2 bytes); the AST
        // class END is in stripped coords while enclosingClassFqnAt
        // receives the original-source cursor offset.  Reproduces the
        // EXACT prod file layout (declare + blank lines + two T[]
        // occurrences) so any byte-coord shift surfaces.
        $workspace = $this->workspace();
        $this->open($workspace, '/Collection.xphp', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Containers;\n\nclass Collection<T>\n{\n    private T[] \$items;\n\n    public function __construct(T ...\$items)\n    {\n        \$this->items = \$items;\n    }\n\n    public function first(): ?T\n    {\n        return \$this->items[0] ?? null;\n    }\n\n    public function all(): T[]\n    {\n        return \$this->items;\n    }\n\n    public function count(): int\n    {\n        return count(\$this->items);\n    }\n}\n");

        $source = $workspace->get('/Collection.xphp')->text;
        // Cursor right after the `->` of `return $this->items[0]` (inside
        // first() -- this is the position that broke in prod id=82/117).
        $items = $this->completeAt(
            $workspace,
            '/Collection.xphp',
            $source,
            'return $this->items[0]',
            strlen('return $this->'),
        );
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('items', $labels, 'private $items must surface from inside same class even with T[] sugar');
    }

    public function testPrivateAndProtectedMembersHiddenFromOutside(): void
    {
        // Cursor inside a DIFFERENT class -- the previous default
        // (public-only) still applies.  No regression.
        $workspace = $this->workspace();
        $this->open($workspace, '/Account.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Account
        {
            private int $balance = 0;
            public string $label = '';
        }
        XPHP);
        $useSource = "<?php\nuse App\\Account;\n\$a = new Account();\n\$a->\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, '$a->', 4);
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('label', $labels);
        self::assertNotContains('balance', $labels, 'private prop must NOT leak across classes');
    }

    public function testStaticPropertyCompletionAfterColonColonDollar(): void
    {
        // Phase 3: `Cls::$|` -- only static properties surface.  Methods,
        // instance properties, and constants must NOT appear.
        $workspace = $this->workspace();
        $this->open($workspace, '/Counter.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Counter
        {
            public static int $total = 0;
            public static string $label = '';
            public int $instance = 0;
            public const VERSION = 1;
            public static function tick(): void {}
        }
        XPHP);
        // `Counter::$` -- cursor at the bare-`$` position.  Use an
        // existing `Counter::$total` in source so the file parses.
        $useSource = "<?php\nuse App\\Counter;\necho Counter::\$total;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, 'Counter::$', strlen('Counter::$'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        // Labels carry the `$` prefix so PhpStorm's popup filter (which
        // matches against the text typed since the last word boundary)
        // accepts them when the user has typed `Stats::$` -- without the
        // `$`, PhpStorm drops every item.  insertText is the bare name
        // (`total`) so the `$` already in source isn't double-inserted.
        self::assertContains('$total', $labels);
        self::assertContains('$label', $labels);
        self::assertNotContains('instance', $labels, 'instance prop must not surface on static-prop completion');
        self::assertNotContains('$instance', $labels, 'instance prop must not surface on static-prop completion');
        self::assertNotContains('VERSION', $labels, 'constants must not surface on static-prop completion');
        self::assertNotContains('tick', $labels, 'methods must not surface on static-prop completion');

        // Each item must carry a textEdit so PhpStorm doesn't extend the
        // replacement range backwards through the `$` -- the regression
        // diagnosed via prod log id=28 (xphp-20260525-172338-536.log).
        // newText is the bare property name; range stays at the cursor
        // when the typed prefix is empty.
        foreach ($items as $item) {
            self::assertNotNull($item->textEdit, 'static-prop item must carry a textEdit anchor');
            self::assertSame(
                ltrim($item->label, '$'),
                $item->textEdit->newText,
                'textEdit.newText must be the bare property name (no leading $)',
            );
        }
    }

    public function testBareStaticContextSurfacesStaticProperties(): void
    {
        // Prod scenario: `class InMemoryRepository<T> { public static
        // string $test = '...'; }` plus `$repo::|` -- typing `::` on
        // an instance variable should still bring up the static
        // property `$test`.  Pre-fix the `Cls::|` branch in
        // `itemsForClass` only iterated constants and methods; static
        // properties were silently skipped (`$repo::$test` could only
        // surface from the narrower `Cls::$|` branch which the user
        // hits AFTER typing `$`).
        $workspace = $this->workspace();
        $this->open($workspace, '/Repo.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Repo {
            public static string $test = '';
            public static int $count = 0;
        }
        XPHP);
        $useSource = "<?php\nuse App\\Repo;\n\$r = new Repo();\necho \$r::;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        // Cursor sits right after `$r::` on line 3 (0-indexed).
        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, '$r::', strlen('$r::'));

        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);
        self::assertContains('$test', $labels, 'static property `$test` must appear on `$r::|`');
        self::assertContains('$count', $labels, 'all static properties surface');

        // Shape check: the `$test` item must self-insert the `$` so
        // accept produces `$r::$test`, not `$r::test`.  filterText is
        // bare so PhpStorm filters the typed prefix (which doesn't
        // yet include `$`) against the candidate; textEdit pins the
        // replacement range so the inserted `$` lands consistently.
        $testItem = null;
        foreach ($items as $candidate) {
            if ($candidate->label === '$test') {
                $testItem = $candidate;
                break;
            }
        }
        self::assertNotNull($testItem);
        self::assertSame('$test', $testItem->insertText);
        self::assertSame('test', $testItem->filterText);
        self::assertNotNull($testItem->textEdit);
        self::assertSame('$test', $testItem->textEdit->newText);
    }

    public function testStaticPropertyCompletionFiltersByPrefix(): void
    {
        // `Cls::$la|` -- prefix filter narrows to props matching `la*`.
        $workspace = $this->workspace();
        $this->open($workspace, '/Counter.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Counter
        {
            public static int $total = 0;
            public static string $label = '';
            public static string $latest = '';
        }
        XPHP);
        $useSource = "<?php\nuse App\\Counter;\necho Counter::\$label;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        // Cursor inside `$label` after the `la` prefix.
        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, '$label', strlen('$la'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('$label', $labels);
        self::assertContains('$latest', $labels);
        self::assertNotContains('$total', $labels, 'prefix `la` must exclude `total`');
    }

    public function testProtectedMembersVisibleInsideSubclass(): void
    {
        // Phase 3 polish: subclass-protected -- when the cursor is inside
        // a class that extends the receiver, the receiver's protected
        // members must surface.  Private members must NOT (that's the
        // declaring-class-only gate).
        $workspace = $this->workspace();
        $this->open($workspace, '/Animal.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Animal {
            protected int $age = 0;
            private string $secret = '';
            protected function sniff(): void {}
            private function dream(): void {}
        }
        XPHP);
        // Subclass body uses `$this->` -- the receiver class FQN is
        // `Animal`, and the caller's enclosing class is `Dog`.
        $this->open($workspace, '/Dog.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Dog extends Animal
        {
            public function run(): void
            {
                $marker = $this->label;
            }
        }
        XPHP);
        $useSource = $workspace->get('/Dog.xphp')->text;

        $items = $this->completeAt($workspace, '/Dog.xphp', $useSource, '$this->', strlen('$this->'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        // Protected members of the ancestor surface in the subclass.
        self::assertContains('age', $labels, 'protected prop must surface in subclass');
        self::assertContains('sniff', $labels, 'protected method must surface in subclass');
        // Private members stay invisible -- subclass can't see private.
        self::assertNotContains('secret', $labels, 'private prop must stay hidden in subclass');
        self::assertNotContains('dream', $labels, 'private method must stay hidden in subclass');
    }

    public function testCompletesPublicPropertiesAfterArrow(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', <<<'XPHP'
        <?php
        namespace App;
        class User {
            public string $name = '';
            private int $age = 0;
        }
        XPHP);
        $useSource = "<?php\nuse App\\User;\n\$u = new User();\n\$u->\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, '$u->', 4);
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('name', $labels);
        self::assertNotContains('age', $labels);
    }

    public function testFiltersMembersByPrefix(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', <<<'XPHP'
        <?php
        namespace App;
        class User {
            public function shout(): string { return ''; }
            public function whisper(): string { return ''; }
            public function murmur(): string { return ''; }
        }
        XPHP);
        $useSource = "<?php\nuse App\\User;\n\$u = new User();\n\$u->sh";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, '$u->sh', strlen('$u->sh'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('shout', $labels);
        self::assertNotContains('whisper', $labels);
        self::assertNotContains('murmur', $labels);
    }

    public function testCompletesStaticMethodsAfterDoubleColon(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/Util.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Util {
            public static function shout(string $s): string { return ''; }
            public function bark(): string { return ''; }
        }
        XPHP);
        $useSource = "<?php\nuse App\\Util;\nUtil::";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, 'Util::', strlen('Util::'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('shout', $labels, 'static methods must be included');
        self::assertNotContains('bark', $labels, 'instance methods must be excluded on static access');
    }

    public function testCompletesClassConstantsAfterDoubleColon(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/Cfg.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Cfg {
            public const MAX = 10;
            public const MIN = 0;
        }
        XPHP);
        $useSource = "<?php\nuse App\\Cfg;\nCfg::";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, 'Cfg::', strlen('Cfg::'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('MAX', $labels);
        self::assertContains('MIN', $labels);
    }

    public function testCompletesInterfaceConstantsAfterDoubleColon(): void
    {
        // Regression for the `Cls::|` completion fataling on interface
        // receivers: pre-fix, `worse-reflection`'s `ReflectionInterface`
        // omits a `properties()` method, and our completion path called
        // `$class->properties()` unconditionally -- `Call to undefined
        // method` propagated to the top-level catch which silently
        // returned `[]`.  Symptom: `\DateTimeInterface::|` showed no
        // completions while `\DateTime::|` worked fine.
        $workspace = $this->workspace();
        $this->open($workspace, '/Status.xphp', <<<'XPHP'
        <?php
        namespace App;
        interface Status {
            public const ACTIVE = 'active';
            public const ARCHIVED = 'archived';
            public function transition(): void;
        }
        XPHP);
        $useSource = "<?php\nuse App\\Status;\nStatus::";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, 'Status::', strlen('Status::'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('ACTIVE', $labels, 'interface constants must be offered');
        self::assertContains('ARCHIVED', $labels, 'interface constants must be offered');
    }

    public function testReturnsEmptyForNonMemberContext(): void
    {
        $workspace = $this->workspace();
        $useSource = "<?php\n\$x = 1;\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, '$x = ', strlen('$x = '));
        self::assertSame([], $items);
    }

    public function testReturnsEmptyForUnknownReceiverType(): void
    {
        $workspace = $this->workspace();
        $useSource = "<?php\n\$mystery->";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, '$mystery->', strlen('$mystery->'));
        self::assertSame([], $items);
    }

    public function testReturnsEmptyForUnknownDocument(): void
    {
        $resolver = $this->resolver($this->workspace());
        self::assertSame([], $resolver->complete('/never-opened.xphp', 0, 0));
    }

    public function testVariableCompletionEmitsTextEditPreservingDollar(): void
    {
        // Prod log id=178 of xphp-20260529-104259-087.log captured
        // `{"label":"$item","kind":6,"insertText":"item"}` -- no textEdit,
        // so PhpStorm extended the implicit replacement range backward
        // through the `$` and accept dropped it.  The textEdit must
        // anchor the replacement range to start at the typed prefix's
        // first character (right after the `$`), so the `$` already
        // in source survives.
        $workspace = $this->workspace();
        $source = "<?php\n\$item = 1;\nif (\$ite) {}\n";
        $this->open($workspace, '/doc.xphp', $source);

        $items = $this->completeAt($workspace, '/doc.xphp', $source, 'if ($ite', strlen('if ($ite'));
        $itemItem = null;
        foreach ($items as $candidate) {
            if ($candidate->label === '$item') {
                $itemItem = $candidate;
                break;
            }
        }
        self::assertNotNull($itemItem, '$item must surface from a `$ite` prefix');
        self::assertSame('item', $itemItem->insertText, 'insertText is the bare name');
        self::assertSame('$item', $itemItem->filterText, 'filterText keeps the popup matching `$ite`');
        self::assertNotNull($itemItem->textEdit, 'textEdit pins the replacement range');
        // Range start = character of the typed `i` (after `$`).  Source
        // `if ($ite` has the `i` of `ite` at column 5 (0-based) on
        // line 2 (0-based).  Cursor sits at column 8 after `e`.  Prefix
        // length is 3.
        self::assertSame(2, $itemItem->textEdit->range->start->line);
        self::assertSame(5, $itemItem->textEdit->range->start->character);
        self::assertSame(2, $itemItem->textEdit->range->end->line);
        self::assertSame(8, $itemItem->textEdit->range->end->character);
        self::assertSame('item', $itemItem->textEdit->newText);
    }

    public function testCompletesVariablesInScopeAfterDollar(): void
    {
        // Use an already-syntactically-valid completion site (inside an `if`
        // condition) so the source parses cleanly -- a cursor in the middle
        // of an unterminated `echo $re` would have nikic refuse the document
        // and our resolver fall back to empty.
        $workspace = $this->workspace();
        $source = "<?php\n\$repo = 1;\n\$report = 2;\nforeach (\$items as \$item) {}\nif (\$re) {}\n";
        $this->open($workspace, '/doc.xphp', $source);

        $items = $this->completeAt($workspace, '/doc.xphp', $source, 'if ($re', strlen('if ($re'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('$repo', $labels);
        self::assertContains('$report', $labels);
        // `re` doesn't prefix-match `items` or `item`, so they're excluded.
        self::assertNotContains('$items', $labels);
        self::assertNotContains('$item', $labels);
    }

    public function testVariableCompletionRespectsFunctionScopeBoundary(): void
    {
        // Phase 3 polish: function bodies are scope barriers in PHP --
        // variables from outside don't leak in.  Before this commit the
        // collector dumped every variable in the document; cursor inside
        // `inner()` saw `$outer` despite that being inaccessible.
        $workspace = $this->workspace();
        $source = "<?php\n\$outer = 1;\nfunction inner(\$param): int {\n    \$local = \$param;\n    return \$local;\n}\n";
        $this->open($workspace, '/doc.xphp', $source);

        // Cursor on `$local` inside `inner()`.
        $items = $this->completeAt($workspace, '/doc.xphp', $source, 'return $local', strlen('return $'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('$local', $labels);
        self::assertContains('$param', $labels);
        // $outer is at top-level scope; the function scope barrier hides it.
        self::assertNotContains('$outer', $labels);
    }

    public function testVariableCompletionInClosureRespectsUseClause(): void
    {
        // PHP closures only see the outer scope variables they explicitly
        // import via `use (...)`.  Other outer-scope vars stay hidden.
        $workspace = $this->workspace();
        $source = "<?php\n\$visible = 1;\n\$hidden = 2;\n\$cb = function () use (\$visible) {\n    \$inner = \$visible;\n    return \$inner;\n};\n";
        $this->open($workspace, '/doc.xphp', $source);

        // Cursor inside the closure body.
        $items = $this->completeAt($workspace, '/doc.xphp', $source, 'return $inner', strlen('return $'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('$inner', $labels);
        self::assertContains('$visible', $labels);
        // $hidden was NOT imported via use; closures don't auto-capture.
        self::assertNotContains('$hidden', $labels);
        // $cb is OUTSIDE the closure (top-level), but it's the closure's
        // own assignment target -- arguably visible from outside the
        // closure but we're inside it: stays hidden.
        self::assertNotContains('$cb', $labels);
    }

    public function testVariableCompletionInArrowFnAutoCapturesOuter(): void
    {
        // PHP arrow functions auto-capture all outer-scope vars by value.
        // Inside `fn () => ...`, the user should see outer vars without
        // needing an explicit use clause.
        $workspace = $this->workspace();
        $source = "<?php\n\$outer = 1;\n\$factor = 2;\n\$cb = fn (\$x) => \$x * \$factor;\n";
        $this->open($workspace, '/doc.xphp', $source);

        // Cursor on `$factor` inside the arrow's body.
        $items = $this->completeAt($workspace, '/doc.xphp', $source, '* $factor', strlen('* $'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('$x', $labels);
        self::assertContains('$factor', $labels);
        self::assertContains('$outer', $labels, 'arrow fn auto-captures outer scope vars');
    }

    public function testVariableCompletionInMethodHidesTopLevelVars(): void
    {
        // Methods are scope barriers -- top-level vars from above the
        // class declaration must NOT surface inside method bodies.
        $workspace = $this->workspace();
        $source = "<?php\n\$topLevel = 1;\nclass Foo {\n    public function bar(\$arg): int {\n        \$inMethod = \$arg;\n        return \$inMethod;\n    }\n}\n";
        $this->open($workspace, '/doc.xphp', $source);

        $items = $this->completeAt($workspace, '/doc.xphp', $source, 'return $inMethod', strlen('return $'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('$inMethod', $labels);
        self::assertContains('$arg', $labels);
        self::assertNotContains('$topLevel', $labels, 'method scope hides top-level vars');
    }

    public function testCompletesVariablesAfterBareDollarSign(): void
    {
        // Cursor immediately after `$` -- we seek inside an existing
        // `$alpha` reference so the source still parses.  The detector
        // sees prefix="" and char-before-prefix="$" -> variable context.
        $workspace = $this->workspace();
        $source = "<?php\n\$alpha = 1;\n\$beta = 2;\necho \$alpha;\n";
        $this->open($workspace, '/doc.xphp', $source);

        $items = $this->completeAt($workspace, '/doc.xphp', $source, 'echo $', strlen('echo $'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('$alpha', $labels);
        self::assertContains('$beta', $labels);
    }

    public function testCompletesUserClassesInExpressionPosition(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $useSource = "<?php\n\$x = new Use";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, 'new Use', strlen('new Use'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        // Short-name match `User` against prefix `Use`.
        self::assertContains('User', $labels);

        // After `new`, only classes -- no functions.
        $kinds = array_map(static fn (CompletionItem $i): int => $i->kind ?? -1, $items);
        self::assertNotContains(\Phpactor\LanguageServerProtocol\CompletionItemKind::FUNCTION, $kinds);
    }

    public function testClassCompletionInsertTextIsFqWithLeadingBackslashWhenNotImported(): void
    {
        // Different namespace, no `use App\Models\User;` → must be FQ
        // with leading backslash, otherwise the inserted bare
        // `App\Models\User` would namespace-prepend to `App\Demos\App\Models\User`.
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $useSource = "<?php\nnamespace App\\Demos;\n\$x = new Use";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, 'new Use', strlen('new Use'));
        $userItem = self::findFirstWithLabel($items, 'User');
        self::assertNotNull($userItem);
        self::assertSame('\\App\\Models\\User', $userItem->insertText);
    }

    public function testClassCompletionInsertTextIsShortNameWhenAlreadyImported(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $useSource = "<?php\nnamespace App\\Demos;\nuse App\\Models\\User;\n\$x = new Use";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, 'new Use', strlen('new Use'));
        $userItem = self::findFirstWithLabel($items, 'User');
        self::assertNotNull($userItem);
        self::assertSame('User', $userItem->insertText);
    }

    public function testClassCompletionInsertTextIsShortNameWhenSameNamespace(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        // Same namespace as User → bare short name (no use statement needed).
        $useSource = "<?php\nnamespace App\\Models;\n\$x = new Use";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, 'new Use', strlen('new Use'));
        $userItem = self::findFirstWithLabel($items, 'User');
        self::assertNotNull($userItem);
        self::assertSame('User', $userItem->insertText);
    }

    public function testClassCompletionInsertTextRespectsAliasedUse(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $useSource = "<?php\nnamespace App\\Demos;\nuse App\\Models\\User as Account;\n\$x = new Use";
        $this->open($workspace, '/Use.xphp', $useSource);

        // The label remains the FQN's last segment (`User`) — completion
        // doesn't currently index aliases by label, so prefix-matching
        // goes via the short name. The relevant assertion is on
        // insertText, which must use the file's bound alias `Account`.
        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, 'new Use', strlen('new Use'));
        $userItem = self::findFirstWithLabel($items, 'User');
        self::assertNotNull($userItem);
        self::assertSame('Account', $userItem->insertText);
    }

    public function testClassCompletionInsertTextFallsBackToFqOnConflictingShortName(): void
    {
        // Two `User`s in the workspace; the file imports App\Other\User.
        // Completing App\Models\User cannot emit bare `User` (would
        // resolve to the imported other one) — must emit the FQ form.
        $workspace = $this->workspace();
        $this->open($workspace, '/Models_User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $this->open($workspace, '/Other_User.xphp', "<?php\nnamespace App\\Other;\nclass User {}\n");
        $useSource = "<?php\nnamespace App\\Demos;\nuse App\\Other\\User;\n\$x = new Use";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, 'new Use', strlen('new Use'));
        $modelsItem = self::findFirstWithDetail($items, 'App\\Models\\User');
        $otherItem = self::findFirstWithDetail($items, 'App\\Other\\User');
        self::assertNotNull($modelsItem);
        self::assertNotNull($otherItem);
        self::assertSame('\\App\\Models\\User', $modelsItem->insertText);
        self::assertSame('User', $otherItem->insertText);
    }

    public function testNewWithEmptyPrefixReturnsEmpty(): void
    {
        // Empty prefix in `new ` would otherwise dump every class FQN in
        // the workspace + stubs into the popup.  Resolver guards against
        // it; user has to type at least one char.
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App;\nclass User {}\n");
        $useSource = "<?php\n\$x = new ";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, 'new ', strlen('new '));
        self::assertSame([], $items);
    }

    public function testCompletesWorkspaceFunctionsByPrefix(): void
    {
        $workspace = $this->workspace();
        $this->open($workspace, '/funcs.xphp', <<<'XPHP'
        <?php
        namespace App;
        function greet(string $n): string { return $n; }
        function gravity(): float { return 9.8; }
        function unrelated(): void {}
        XPHP);
        $useSource = "<?php\nuse function App\\greet;\necho gr";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, 'echo gr', strlen('echo gr'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('greet', $labels);
        self::assertContains('gravity', $labels);
        self::assertNotContains('unrelated', $labels);
    }

    public function testCompletesNativeFunctionsFromStubsByPrefix(): void
    {
        if (!is_dir(ReflectorFactory::defaultStubPath())) {
            self::markTestSkipped('jetbrains/phpstorm-stubs not installed');
        }
        $workspace = $this->workspace();
        $source = "<?php\necho strl";
        $this->open($workspace, '/doc.xphp', $source);

        $items = $this->completeAt($workspace, '/doc.xphp', $source, 'echo strl', strlen('echo strl'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        // `strl` prefix matches `strlen` (and possibly nothing else in stubs).
        self::assertContains('strlen', $labels);
    }

    public function testCompletesMembersOnReceiverFromGenericInstantiation(): void
    {
        // Mirrors the user-reported failing case in
        // xphp-20260524-150655-167.log:  `$users = new Collection<User>(...)`
        // followed by `$users->|` on the next line.  The xphp strip turns
        // `<User>` into whitespace; worse-reflection should still infer
        // `$users: App\Containers\Collection` from the ctor and surface
        // Collection's methods.  Production returned empty here; if this
        // test passes locally, the gap is environmental rather than logic.
        $workspace = $this->workspace();
        $this->open($workspace, '/Collection.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Collection<T>
        {
            private T[] $items;
            public function __construct(T ...$items)
            {
                $this->items = $items;
            }
            public function first(): ?T
            {
                return $this->items[0] ?? null;
            }
            public function count(): int
            {
                return count($this->items);
            }
        }
        XPHP);
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User { public function __construct(public string \$name) {} }\n");

        $useSource = "<?php\nuse App\\Containers\\Collection;\nuse App\\Models\\User;\n\$users = new Collection<User>(new User('a'));\n\$users->\necho 'done';\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, '$users->', strlen('$users->'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        // Methods of Collection
        self::assertContains('first', $labels);
        self::assertContains('count', $labels);
        // __construct is suppressed by the magic-method filter
        self::assertNotContains('__construct', $labels);
    }

    public function testCompletesMembersOnNullableReceiverType(): void
    {
        // worse-reflection surfaces a nullable return type like
        //     function getMaybeUser(): ?User
        // as the receiver type `?App\Models\User` for `getMaybeUser()?->|`
        // chained access.  Without stripping the `?`, reflectClassLike
        // treats it as part of the FQN and throws SourceNotFound -- the
        // exact shape captured in xphp-20260524-202152-019.log.
        $workspace = $this->workspace();
        $this->open($workspace, '/User.xphp', <<<'XPHP'
        <?php
        namespace App;
        class User {
            public string $name = '';
            public function shout(): string { return ''; }
        }
        XPHP);
        $this->open($workspace, '/Repo.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Repo {
            public function getMaybeUser(): ?User { return null; }
        }
        XPHP);
        $useSource = "<?php\nuse App\\Repo;\n\$r = new Repo();\n\$r->getMaybeUser()?->\necho 'x';\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt(
            $workspace,
            '/Use.xphp',
            $useSource,
            '?->',
            strlen('?->'),
        );
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('shout', $labels);
        self::assertContains('name', $labels);
    }

    public function testCompletesUnionOfMembersForUnionReceiver(): void
    {
        // Cycle K.1: cursor on `$x->|` where `$x: A|B` shows every
        // method from either A or B (user-spec union semantics).
        // A-only and B-only methods both surface; the popup is the
        // most permissive shape.
        $workspace = $this->workspace();
        $this->open($workspace, '/A.xphp', <<<'XPHP'
        <?php
        namespace App;
        class A {
            public function alpha(): string { return 'a'; }
            public function common(): string { return 'c'; }
        }
        XPHP);
        $this->open($workspace, '/B.xphp', <<<'XPHP'
        <?php
        namespace App;
        class B {
            public function beta(): string { return 'b'; }
            public function common(): string { return 'c'; }
        }
        XPHP);
        // Docblock @var triggers worse-reflection's union inference
        // for local variables.  Native PHP 8 union return types from
        // function calls aren't traced through assignments by the
        // current worse-reflection -- the docblock annotation is the
        // most reliable way to seed a union in a test fixture.
        $useSource = "<?php\nuse App\\A;\nuse App\\B;\n/** @var A|B \$x */\n\$x = new A();\n\$x->\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, '$x->', 4);
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        // alpha + beta + common (common deduped to one) -- union of
        // members across A and B.
        self::assertContains('alpha', $labels, 'A-only method surfaces in union completion');
        self::assertContains('beta', $labels, 'B-only method surfaces in union completion');
        self::assertContains('common', $labels);
        self::assertSame(1, count(array_filter($labels, fn ($l) => $l === 'common')), 'shared method deduped to one entry');
    }

    public function testCompletesIntersectionOfMembersForIntersectionReceiver(): void
    {
        // Cycle K.1: cursor on `$x->|` where `$x: A&B` shows ONLY
        // members common to BOTH A and B (user-spec intersection
        // semantics).  A-only and B-only methods are hidden.
        $workspace = $this->workspace();
        $this->open($workspace, '/A.xphp', <<<'XPHP'
        <?php
        namespace App;
        interface A {
            public function alpha(): string;
            public function common(): string;
        }
        XPHP);
        $this->open($workspace, '/B.xphp', <<<'XPHP'
        <?php
        namespace App;
        interface B {
            public function beta(): string;
            public function common(): string;
        }
        XPHP);
        // Docblock @var with intersection syntax.  See the union
        // test above for why docblocks beat native param types in
        // these fixtures.
        $useSource = "<?php\nuse App\\A;\nuse App\\B;\n/** @var A&B \$x */\n\$x = null;\n\$x->\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, '$x->', 4);
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        // Only `common` -- the only method on BOTH A AND B.
        self::assertContains('common', $labels, 'shared method surfaces');
        self::assertNotContains('alpha', $labels, 'A-only method hidden in intersection completion');
        self::assertNotContains('beta', $labels, 'B-only method hidden in intersection completion');
    }

    public function testCompletesVariablesWhenSourceMidEditDoesNotParseStrictly(): void
    {
        // The user types `$us` with cursor on the `s` -- nikic refuses the
        // document because `$us` isn't a complete statement.  Before the
        // fix the resolver returned [] outright; now we fall back to a
        // tolerant parse so variables declared on PRIOR lines still
        // surface.  This is the exact shape captured in
        // xphp-20260524-202152-019.log at line 13 char 2-3.
        $workspace = $this->workspace();
        $source = "<?php\n\$users = [];\n\$usage = 1;\n\$other = 2;\n\$us";
        $this->open($workspace, '/doc.xphp', $source);

        $items = $this->completeAt($workspace, '/doc.xphp', $source, '$us', strlen('$us'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('$users', $labels, 'prior `$users = []` must surface');
        self::assertContains('$usage', $labels, 'prior `$usage = 1` must surface');
        // `other` doesn't prefix-match `us`.
        self::assertNotContains('$other', $labels);
    }

    public function testMemberCompletionSubstitutesGenericReceiverViaResolver(): void
    {
        // Mirrors the user-reported gap in xphp-20260524-214251-685.log:
        //     $users = new Collection<User>();
        //     $u = $users->first();      // $u is ?T -> ?App\Models\User
        //     $u->|                      // member completion here returned 0
        // worse-reflection sees `?App\Containers\T` for the receiver, so
        // reflectClassLike fails.  The completion path now consults
        // GenericResolver to swap the placeholder for the substituted
        // concrete -- the user gets User's members instead of empty.
        $workspace = $this->workspace();
        $this->open($workspace, '/Collection.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Collection<T> {
            public function first(): ?T { return null; }
        }
        XPHP);
        $this->open($workspace, '/User.xphp', <<<'XPHP'
        <?php
        namespace App\Models;
        class User {
            public string $name = '';
            public function shout(): string { return ''; }
        }
        XPHP);
        $useSource = "<?php\nuse App\\Containers\\Collection;\nuse App\\Models\\User;\n\$users = new Collection<User>();\n\$u = \$users->first();\n\$u->\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, '$u->', strlen('$u->'));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('shout', $labels, 'User::shout must surface via resolver-substituted receiver');
        self::assertContains('name', $labels, 'User::$name must surface');
        self::assertNotContains('first', $labels, 'Collection::first must NOT leak through (receiver was swapped)');
    }

    public function testMemberCompletionThroughChainedMethodCallReceiver(): void
    {
        // Phase 0.7-completion: cursor at `$repo->first()?->|` -- the
        // receiver of the chained `?->` is a MethodCall (not a Variable),
        // so the old variable-symbolType-only swap didn't fire.  The
        // generalised resolveMemberAccessReceiverClassAt walks the
        // receiver expression and substitutes via inferType.
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
            public string $name = '';
            public function shout(): string { return ''; }
        }
        XPHP);
        $useSource = "<?php\nuse App\\Containers\\Repository;\nuse App\\Models\\User;\n\$repo = new Repository<User>();\n\$repo->first()?->\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt(
            $workspace,
            '/Use.xphp',
            $useSource,
            '?->',
            strlen('?->'),
        );
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $items);

        self::assertContains('name', $labels, 'User::$name must surface via chained-receiver substitution');
        self::assertContains('shout', $labels, 'User::shout must surface');
    }

    public function testMemberCompletionDetailRendersGenericPlaceholderAsBareName(): void
    {
        // The user reported -- xphp-20260524-204801-302.log id=9 -- that the
        // `first` completion entry's `detail` read `(): ?App\Containers\T`,
        // exposing the post-strip placeholder qualification.  Wiring the
        // GenericParamRegistry through CompletionItem assembly should
        // collapse that to `?T`.
        $workspace = $this->workspace();
        $this->open($workspace, '/Collection.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Collection<T> {
            public function first(): ?T { return null; }
            public function count(): int { return 0; }
        }
        XPHP);
        $this->open($workspace, '/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $useSource = "<?php\nuse App\\Containers\\Collection;\nuse App\\Models\\User;\n\$users = new Collection<User>();\n\$users->\n";
        $this->open($workspace, '/Use.xphp', $useSource);

        $items = $this->completeAt($workspace, '/Use.xphp', $useSource, '$users->', strlen('$users->'));
        $byLabel = [];
        foreach ($items as $i) {
            $byLabel[$i->label] = $i;
        }

        self::assertArrayHasKey('first', $byLabel);
        self::assertSame('(): ?T', $byLabel['first']->detail);
        // Non-placeholder returns are left untouched.
        self::assertArrayHasKey('count', $byLabel);
        self::assertSame('(): int', $byLabel['count']->detail);
    }

    public function testExpressionPositionEmptyPrefixReturnsEmpty(): void
    {
        // Same guard as `new` -- no completion without a prefix at
        // expression position; the alternative is dumping ~6000 stub
        // FQNs on the user.
        $workspace = $this->workspace();
        $source = "<?php\necho ";
        $this->open($workspace, '/doc.xphp', $source);

        $items = $this->completeAt($workspace, '/doc.xphp', $source, 'echo ', strlen('echo '));
        self::assertSame([], $items);
    }

    private function completeAt(
        PhpactorWorkspace $workspace,
        string $uri,
        string $source,
        string $needle,
        int $offsetInNeedle,
    ): array {
        $byte = strpos($source, $needle);
        self::assertNotFalse($byte, "fixture needle '$needle' must exist");
        $byte += $offsetInNeedle;
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        return $this->resolver($workspace)->complete($uri, $line, $character);
    }

    private function resolver(PhpactorWorkspace $workspace): PhpCompletionResolver
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $workspaceSymbols = new \XPHP\Lsp\Handler\WorkspaceSymbols($workspace, $cache);
        $completionIndex = new \XPHP\Lsp\Resolver\CompletionIndex(
            $workspaceSymbols,
            ReflectorFactory::defaultStubPath(),
        );
        $fqnIndex = new \XPHP\Lsp\Reflection\FqnIndex($workspace, $cache, $parser, '');
        $reflector = (new ReflectorFactory(
            $workspace,
            $cache,
            $parser,
            rootPath: '',
            stubPath: ReflectorFactory::defaultStubPath(),
            cacheDir: ReflectorFactory::defaultCacheDir(),
            fqnIndex: $fqnIndex,
        ))->build();
        $classLikeLookup = new \XPHP\Lsp\Resolver\WorkspaceClassLikeLookup($workspace, $cache);
        return new PhpCompletionResolver(
            $workspace,
            $parser,
            $reflector,
            $completionIndex,
            $cache,
            new \XPHP\Lsp\Resolver\GenericParamRegistry($fqnIndex),
            new \XPHP\Lsp\Resolver\GenericResolver($workspace, $cache, $classLikeLookup, $parser, $fqnIndex),
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

    /**
     * @param list<CompletionItem> $items
     */
    private static function findFirstWithLabel(array $items, string $label): ?CompletionItem
    {
        foreach ($items as $item) {
            if ($item->label === $label) {
                return $item;
            }
        }
        return null;
    }

    /**
     * @param list<CompletionItem> $items
     */
    private static function findFirstWithDetail(array $items, string $detail): ?CompletionItem
    {
        foreach ($items as $item) {
            if ($item->detail === $detail) {
                return $item;
            }
        }
        return null;
    }
}
