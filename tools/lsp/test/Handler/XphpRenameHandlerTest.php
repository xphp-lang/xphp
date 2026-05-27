<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use Amp\Failure;
use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\RenameFile;
use Phpactor\LanguageServerProtocol\RenameParams;
use Phpactor\LanguageServerProtocol\TextDocumentEdit;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use Phpactor\LanguageServerProtocol\TextEdit;
use Phpactor\LanguageServerProtocol\WorkspaceEdit;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\XphpRenameHandler;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Reflection\ReflectorFactory;
use XPHP\Lsp\Resolver\CompositeClassLikeLookup;
use XPHP\Lsp\Resolver\FilesystemClassLikeLookup;
use XPHP\Lsp\Resolver\GenericResolver;
use XPHP\Lsp\Resolver\ReferenceFinder;
use XPHP\Lsp\Resolver\RenameProvider;
use XPHP\Lsp\Resolver\WorkspaceClassLikeLookup;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

final class XphpRenameHandlerTest extends TestCase
{
    public function testMethodsMapRegistersEndpoint(): void
    {
        $handler = $this->handler(new PhpactorWorkspace());
        self::assertArrayHasKey('textDocument/rename', $handler->methods());
        self::assertSame('rename', $handler->methods()['textDocument/rename']);
    }

    public function testRenamesClassAndAllReferencesIncludingShortName(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass User {}\n"));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, "<?php\nnamespace X;\nuse App\\User;\n\$u = new User();\n"));

        $edit = $this->renameAt($workspace, '/User.xphp', 'class User', strlen('class '), 'Customer');

        self::assertInstanceOf(WorkspaceEdit::class, $edit);
        $byUri = self::indexEdits($edit);
        self::assertArrayHasKey('/User.xphp', $byUri);
        self::assertArrayHasKey('/Use.xphp', $byUri);

        // /User.xphp: declaration rename only (`class User` -> `class Customer`).
        self::assertCount(1, $byUri['/User.xphp']);
        self::assertSame('Customer', $byUri['/User.xphp'][0]->newText);

        // /Use.xphp: two edits, `use App\User;` and `new User()` -- both
        // get the short-name swap so the namespace prefix survives.
        self::assertCount(2, $byUri['/Use.xphp']);
        foreach ($byUri['/Use.xphp'] as $e) {
            self::assertSame('Customer', $e->newText);
        }
    }

    public function testRenamesFunctionAcrossCalls(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/lib.xphp', 'xphp', 1, "<?php\nnamespace App;\nfunction old(\$x) { return \$x; }\n"));
        $workspace->open(new TextDocumentItem('/use.xphp', 'xphp', 1, "<?php\nuse function App\\old;\nold(1);\nold(2);\n"));

        $edit = $this->renameAt($workspace, '/lib.xphp', 'function old', strlen('function '), 'better');

        self::assertNotNull($edit);
        $byUri = self::indexEdits($edit);
        self::assertCount(1, $byUri['/lib.xphp']);
        self::assertSame('better', $byUri['/lib.xphp'][0]->newText);
        // /use.xphp: 1 use-function import + 2 calls = 3 edits.  The
        // `use function App\old` alias is now tracked symmetric to the
        // class-import behaviour (Use_::TYPE_FUNCTION branch in
        // ReferenceFinder).
        self::assertCount(3, $byUri['/use.xphp']);
    }

    public function testRenamesMethodAcrossCallSites(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class User {
            public function shout(): string { return ''; }
        }
        XPHP));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, "<?php\nuse App\\User;\n\$u = new User();\n\$u->shout();\n\$u->shout();\n"));

        $edit = $this->renameAt($workspace, '/User.xphp', 'function shout', strlen('function '), 'cry');

        $byUri = self::indexEdits($edit);
        self::assertCount(1, $byUri['/User.xphp']);
        self::assertCount(2, $byUri['/Use.xphp']);
        foreach (array_merge($byUri['/User.xphp'], $byUri['/Use.xphp']) as $e) {
            self::assertSame('cry', $e->newText);
        }
    }

    public function testRenamesMethodAcrossSubclassInheritedCallSites(): void
    {
        // Item 1: renaming Animal::speak should also rewrite `$dog->speak()`
        // when Dog extends Animal without overriding -- the call inherits
        // the method, so the rewrite must follow.  Without the inheritance
        // walk, Dog's call site silently survives as a dangling reference
        // to the renamed-away symbol.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Animal.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Animal {
            public function speak(): string { return ''; }
        }
        XPHP));
        $workspace->open(new TextDocumentItem('/Dog.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Dog extends Animal {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        use App\Animal;
        use App\Dog;
        $a = new Animal();
        $a->speak();
        $d = new Dog();
        $d->speak();
        XPHP));

        $edit = $this->renameAt($workspace, '/Animal.xphp', 'function speak', strlen('function '), 'bark');

        $byUri = self::indexEdits($edit);
        // Declaration in /Animal.xphp + 2 calls in /Use.xphp (both `$a`
        // and `$d` flavours) = 3 edits total.
        self::assertCount(1, $byUri['/Animal.xphp']);
        self::assertCount(2, $byUri['/Use.xphp']);
        foreach (array_merge($byUri['/Animal.xphp'], $byUri['/Use.xphp']) as $e) {
            self::assertSame('bark', $e->newText);
        }
    }

    public function testRenamesProperty(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class User {
            public string $name = '';
        }
        XPHP));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, "<?php\nuse App\\User;\n\$u = new User();\necho \$u->name;\necho \$u->name;\n"));

        $edit = $this->renameAt($workspace, '/User.xphp', '$name', 1, 'label');

        $byUri = self::indexEdits($edit);
        self::assertCount(1, $byUri['/User.xphp']);
        self::assertCount(2, $byUri['/Use.xphp']);
        foreach (array_merge($byUri['/User.xphp'], $byUri['/Use.xphp']) as $e) {
            self::assertSame('label', $e->newText);
        }
    }

    public function testRejectsInvalidIdentifier(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass User {}\n"));

        $params = self::paramsFor($workspace, '/User.xphp', 'class User', strlen('class '), '123-not-valid');
        $promise = $this->handler($workspace)->rename($params);

        $this->expectException(\Throwable::class);
        wait($promise);
    }

    public function testRejectsEmptyIdentifier(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass User {}\n"));

        $params = self::paramsFor($workspace, '/User.xphp', 'class User', strlen('class '), '');
        $promise = $this->handler($workspace)->rename($params);

        $this->expectException(\Throwable::class);
        wait($promise);
    }

    public function testReturnsNullForUnknownUri(): void
    {
        $handler = $this->handler(new PhpactorWorkspace());
        $params = new RenameParams(
            new TextDocumentIdentifier('/never-opened.xphp'),
            new Position(0, 0),
            'NewName',
        );
        self::assertNull(wait($handler->rename($params)));
    }

    public function testReturnsNullWhenCursorIsNotOnRenameableSymbol(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/x.xphp', 'xphp', 1, "<?php\n\$x = 42;\n"));

        $params = self::paramsFor($workspace, '/x.xphp', '42', 0, 'Y');
        self::assertNull(wait($this->handler($workspace)->rename($params)));
    }

    public function testCursorOnAliasDeclTokenRenamesAliasLocally(): void
    {
        // Regression for prod trace xphp-20260525-140847-285.log id=21:
        // cursor on the alias token `xyz` inside `use ... as xyz`
        // previously returned null because resolveTargetAt didn't
        // recognise UseItem.alias as a renameable target.  Same effect
        // as cursor on a call site -- rename the local alias + its
        // unqualified uses; leave the source function alone.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/lib.xphp', 'xphp', 1, "<?php\nnamespace App\\Models;\nfunction foo() {}\n"));
        $workspace->open(new TextDocumentItem('/use.xphp', 'xphp', 1, "<?php\nuse function App\\Models\\{foo as xyz};\nxyz();\nxyz();\n"));

        $edit = $this->renameAt($workspace, '/use.xphp', 'as xyz', strlen('as '), 'zzz');
        $byUri = self::indexEdits($edit);

        self::assertArrayNotHasKey('/lib.xphp', $byUri, 'underlying function must stay untouched');
        self::assertCount(3, $byUri['/use.xphp'], 'alias decl + 2 call sites = 3 edits');
        foreach ($byUri['/use.xphp'] as $e) {
            self::assertSame('zzz', $e->newText);
        }
    }

    public function testCursorOnAliasedCallSiteRenamesAliasLocally(): void
    {
        // Regression for prod trace xphp-20260525-134748-448.log id=43:
        // user puts cursor on `xyz()` (a call that resolves via
        // `use function ... as xyz` to a function elsewhere) and types
        // `zzz`.  PhpStorm-style: rename the LOCAL alias, not the
        // underlying function.  The use stmt's source name and the
        // function declaration must NOT change; only the alias decl and
        // its call sites in this file get rewritten.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/lib.xphp', 'xphp', 1, "<?php\nnamespace App\\Models;\nfunction foo() {}\n"));
        $workspace->open(new TextDocumentItem('/use.xphp', 'xphp', 1, "<?php\nuse function App\\Models\\{foo as bar};\nbar();\nbar();\n"));

        $edit = $this->renameAt($workspace, '/use.xphp', 'bar();', 0, 'zzz');
        $byUri = self::indexEdits($edit);

        // /lib.xphp MUST NOT appear -- the underlying function stays.
        self::assertArrayNotHasKey('/lib.xphp', $byUri);
        // /use.xphp gets 3 edits: the alias decl `as bar` -> `as zzz`,
        // and the two `bar()` call sites.
        self::assertCount(3, $byUri['/use.xphp']);
        foreach ($byUri['/use.xphp'] as $e) {
            self::assertSame('zzz', $e->newText);
        }
    }

    public function testAliasedCallSitesArePreservedDuringRename(): void
    {
        // Regression for prod trace xphp-20260525-131659-392.log id=192:
        // `use function App\Models\{foo as bar};` + `bar()` call sites.
        // Renaming `foo` should rewrite the SOURCE side of the alias
        // (the group-use entry) but leave aliased call sites alone so
        // the alias keeps working with the new function name.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/lib.xphp', 'xphp', 1, "<?php\nnamespace App\\Models;\nfunction foo() {}\n"));
        $workspace->open(new TextDocumentItem('/use.xphp', 'xphp', 1, "<?php\nuse function App\\Models\\{foo as bar};\nbar();\nbar();\n"));

        $edit = $this->renameAt($workspace, '/lib.xphp', 'function foo', strlen('function '), 'fizz');
        $byUri = self::indexEdits($edit);

        self::assertCount(1, $byUri['/lib.xphp']);
        self::assertSame('fizz', $byUri['/lib.xphp'][0]->newText);
        // /use.xphp: ONLY the source name inside `{foo as bar}` should
        // be edited.  The `bar()` calls keep using the alias.
        self::assertCount(1, $byUri['/use.xphp']);
        self::assertSame('fizz', $byUri['/use.xphp'][0]->newText);
        $useSource = $workspace->get('/use.xphp')->text;
        $map = new PositionMap($useSource);
        $start = $map->positionToOffset(
            $byUri['/use.xphp'][0]->range->start->line,
            $byUri['/use.xphp'][0]->range->start->character,
        );
        $end = $map->positionToOffset(
            $byUri['/use.xphp'][0]->range->end->line,
            $byUri['/use.xphp'][0]->range->end->character,
        );
        self::assertSame('foo', substr($useSource, $start, $end - $start));
    }

    public function testClassRenameEmitsRenameFileOpWhenBasenameMatches(): void
    {
        // PSR-4 convention: `class Foo` lives in `Foo.xphp`.  Renaming
        // `Foo` to `Bar` must emit a RenameFile op alongside the
        // TextEdits so the file follows the class.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass User {}\n"));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, "<?php\nuse App\\User;\n\$u = new User();\n"));

        $edit = $this->renameAt($workspace, '/User.xphp', 'class User', strlen('class '), 'Customer');
        self::assertNotNull($edit);

        $renameOps = array_filter(
            $edit->documentChanges ?? [],
            fn ($c): bool => $c instanceof RenameFile,
        );
        self::assertCount(1, $renameOps, 'class rename must emit exactly one RenameFile op');
        $renameOp = array_values($renameOps)[0];
        self::assertSame('/User.xphp', $renameOp->oldUri);
        self::assertSame('/Customer.xphp', $renameOp->newUri);
        self::assertSame('rename', $renameOp->kind);
    }

    public function testClassRenameWithFileUriPrefixPreservesPrefix(): void
    {
        // Editors typically open documents with `file://` URIs.  The
        // RenameProvider strips the prefix before manipulating the path
        // and re-adds it on the new URI.  Pins the `$hasFilePrefix
        // ? 'file://' . $newPath : $newPath` ternary on line 190 of
        // RenameProvider against Concat / ConcatOperandRemoval mutants
        // (which would either drop the prefix on the new URI or
        // concatenate it in the wrong order).
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('file:///workspace/User.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass User {}\n"));
        $workspace->open(new TextDocumentItem('file:///workspace/Use.xphp', 'xphp', 1, "<?php\nuse App\\User;\n\$u = new User();\n"));

        $edit = $this->renameAt($workspace, 'file:///workspace/User.xphp', 'class User', strlen('class '), 'Customer');
        self::assertNotNull($edit);

        $renameOps = array_filter(
            $edit->documentChanges ?? [],
            fn ($c): bool => $c instanceof RenameFile,
        );
        self::assertCount(1, $renameOps);
        $renameOp = array_values($renameOps)[0];
        self::assertSame('file:///workspace/User.xphp', $renameOp->oldUri);
        self::assertSame('file:///workspace/Customer.xphp', $renameOp->newUri);
    }

    public function testClassRenameSkipsRenameFileWhenBasenameMismatch(): void
    {
        // Multiple classes per file (or any other non-PSR-4 layout) --
        // the file name doesn't match the class name, so we DON'T rename
        // the file.  Under-rename rather than rename the wrong file.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Mixed.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass User {}\nclass Account {}\n"));

        $edit = $this->renameAt($workspace, '/Mixed.xphp', 'class User', strlen('class '), 'Customer');
        self::assertNotNull($edit);
        $renameOps = array_filter(
            $edit->documentChanges ?? [],
            fn ($c): bool => $c instanceof RenameFile,
        );
        self::assertCount(0, $renameOps, 'file rename must NOT fire when basename mismatches class short name');
    }

    public function testRenameFileSuppressedWhenClientDoesntSupportRenameOp(): void
    {
        // PhpStorm's LSP plugin advertises `resourceOperations: ["create"]`
        // only -- it silently drops RenameFile ops.  When the client
        // capability is off, we must NOT emit RenameFile (the in-file
        // TextEdits still apply, but the user does the file rename
        // manually).  VS Code advertises rename support and gets the op.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass User {}\n"));

        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $fqnIndex = new FqnIndex($workspace, $cache, $parser, '');
        $reflector = (new ReflectorFactory(
            $workspace,
            $cache,
            $parser,
            rootPath: '',
            stubPath: ReflectorFactory::defaultStubPath(),
            cacheDir: ReflectorFactory::defaultCacheDir(),
            fqnIndex: $fqnIndex,
        ))->build();
        $classLikeLookup = new CompositeClassLikeLookup(
            new WorkspaceClassLikeLookup($workspace, $cache),
            new FilesystemClassLikeLookup($fqnIndex),
        );
        $genericResolver = new GenericResolver($workspace, $cache, $classLikeLookup, $parser, $fqnIndex);
        $finder = new ReferenceFinder($workspace, $cache, $fqnIndex, $parser, $reflector, $genericResolver);
        $provider = new RenameProvider($workspace, $finder, $fqnIndex, clientSupportsRenameFile: false);
        $handler = new XphpRenameHandler($workspace, $provider);

        $params = self::paramsFor($workspace, '/User.xphp', 'class User', strlen('class '), 'Customer');
        $edit = wait($handler->rename($params));
        self::assertInstanceOf(WorkspaceEdit::class, $edit);
        $renameOps = array_filter(
            $edit->documentChanges ?? [],
            fn ($c): bool => $c instanceof RenameFile,
        );
        self::assertCount(0, $renameOps, 'must not emit RenameFile when client doesn\'t support it');
    }

    public function testMethodRenameDoesNotEmitRenameFile(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass User { public function shout(): string { return ''; } }\n"));

        $edit = $this->renameAt($workspace, '/User.xphp', 'function shout', strlen('function '), 'cry');
        self::assertNotNull($edit);
        $renameOps = array_filter(
            $edit->documentChanges ?? [],
            fn ($c): bool => $c instanceof RenameFile,
        );
        self::assertCount(0, $renameOps, 'method rename must NOT touch files');
    }

    public function testFullyQualifiedRefKeepsNamespacePrefix(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass User {}\n"));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, "<?php\nfunction f(\\App\\User \$u): void {}\n"));

        $edit = $this->renameAt($workspace, '/User.xphp', 'class User', strlen('class '), 'Customer');
        $byUri = self::indexEdits($edit);

        // /Use.xphp got ONE edit that should only replace `User` -- the
        // `\App\` prefix has to survive.  The TextEdit range starts AFTER
        // the last backslash; the newText is just the short name.
        self::assertCount(1, $byUri['/Use.xphp']);
        self::assertSame('Customer', $byUri['/Use.xphp'][0]->newText);
        // Range should start past the last backslash; rebuild the
        // original text via the range positions to confirm.
        $source = $workspace->get('/Use.xphp')->text;
        $map = new PositionMap($source);
        $start = $map->positionToOffset(
            $byUri['/Use.xphp'][0]->range->start->line,
            $byUri['/Use.xphp'][0]->range->start->character,
        );
        $end = $map->positionToOffset(
            $byUri['/Use.xphp'][0]->range->end->line,
            $byUri['/Use.xphp'][0]->range->end->character,
        );
        self::assertSame('User', substr($source, $start, $end - $start));
    }

    /**
     * @return array<string, list<TextEdit>>
     */
    private static function indexEdits(?WorkspaceEdit $edit): array
    {
        self::assertNotNull($edit);
        $byUri = [];
        foreach ($edit->documentChanges ?? [] as $tde) {
            // RenameFile / CreateFile / DeleteFile ops may appear in the
            // same documentChanges array; the existing tests focus on
            // TextDocumentEdits, so filter the rest out.  Dedicated
            // file-rename tests inspect documentChanges directly.
            if (!$tde instanceof TextDocumentEdit) {
                continue;
            }
            $byUri[$tde->textDocument->uri] = $tde->edits;
        }
        return $byUri;
    }

    private function renameAt(
        PhpactorWorkspace $workspace,
        string $uri,
        string $needle,
        int $offsetInNeedle,
        string $newName,
    ): ?WorkspaceEdit {
        $params = self::paramsFor($workspace, $uri, $needle, $offsetInNeedle, $newName);
        $result = wait($this->handler($workspace)->rename($params));
        self::assertTrue($result === null || $result instanceof WorkspaceEdit);
        return $result;
    }

    public function testReturnsResultWhenCancelTokenNotRequested(): void
    {
        // Pins the cancel-poll guard at XphpRenameHandler line 61.
        // A LogicalAndSingleSubExprNegation mutant flipping
        // `isRequested` to `!isRequested` would short-circuit every
        // rename call that arrived with a non-requested cancel token.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass User {}\n"));

        $params = self::paramsFor($workspace, '/User.xphp', 'class User', strlen('class '), 'Customer');
        $cancel = new \Amp\CancellationTokenSource();
        // Deliberately NOT cancelled.

        $result = wait($this->handler($workspace)->rename($params, $cancel->getToken()));
        self::assertInstanceOf(WorkspaceEdit::class, $result);
    }

    public function testReturnsNullWhenCancelTokenAlreadyRequested(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass User {}\n"));

        $params = self::paramsFor($workspace, '/User.xphp', 'class User', strlen('class '), 'Customer');
        $cancel = new \Amp\CancellationTokenSource();
        $cancel->cancel();

        $result = wait($this->handler($workspace)->rename($params, $cancel->getToken()));
        self::assertNull($result);
    }

    private static function paramsFor(
        PhpactorWorkspace $workspace,
        string $uri,
        string $needle,
        int $offsetInNeedle,
        string $newName,
    ): RenameParams {
        $item = $workspace->get($uri);
        $byte = strpos($item->text, $needle);
        self::assertNotFalse($byte, "needle '$needle' must exist in $uri");
        $byte += $offsetInNeedle;
        [$line, $character] = (new PositionMap($item->text))->offsetToPosition($byte);
        return new RenameParams(
            new TextDocumentIdentifier($uri),
            new Position($line, $character),
            $newName,
        );
    }

    private function handler(PhpactorWorkspace $workspace): XphpRenameHandler
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $fqnIndex = new FqnIndex($workspace, $cache, $parser, '');
        $reflector = (new ReflectorFactory(
            $workspace,
            $cache,
            $parser,
            rootPath: '',
            stubPath: ReflectorFactory::defaultStubPath(),
            cacheDir: ReflectorFactory::defaultCacheDir(),
            fqnIndex: $fqnIndex,
        ))->build();
        $classLikeLookup = new CompositeClassLikeLookup(
            new WorkspaceClassLikeLookup($workspace, $cache),
            new FilesystemClassLikeLookup($fqnIndex),
        );
        $genericResolver = new GenericResolver($workspace, $cache, $classLikeLookup, $parser, $fqnIndex);
        $finder = new ReferenceFinder($workspace, $cache, $fqnIndex, $parser, $reflector, $genericResolver);
        return new XphpRenameHandler($workspace, new RenameProvider($workspace, $finder, $fqnIndex));
    }
}
