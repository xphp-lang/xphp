<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\FileRename;
use Phpactor\LanguageServerProtocol\RenameFile;
use Phpactor\LanguageServerProtocol\RenameFilesParams;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\TextDocumentEdit;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use Phpactor\LanguageServerProtocol\WorkspaceEdit;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\XphpWillRenameFilesHandler;
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

final class XphpWillRenameFilesHandlerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xphp-willrename-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->rmrf($this->root);
        }
    }

    public function testMethodsMapRegistersEndpoint(): void
    {
        $handler = $this->handler(new PhpactorWorkspace());
        self::assertArrayHasKey('workspace/willRenameFiles', $handler->methods());
        self::assertSame('willRenameFiles', $handler->methods()['workspace/willRenameFiles']);
    }

    public function testAdvertisesWillRenameCapabilityWithXphpAndPhpGlobs(): void
    {
        $handler = $this->handler(new PhpactorWorkspace());
        $caps = new ServerCapabilities();
        $handler->registerCapabiltiies($caps);

        // Spec shape: $caps->workspace is an array containing a
        // `fileOperations` slot.  We require both `.xphp` AND `.php`
        // filters so PhpStorm sends the notification for either
        // (composer PSR-4 applies to both file types).
        self::assertIsArray($caps->workspace);
        self::assertArrayHasKey('fileOperations', $caps->workspace);
        $ops = $caps->workspace['fileOperations'];
        self::assertNotNull($ops->willRename);
        $filters = $ops->willRename->filters;
        self::assertCount(2, $filters);
        $globs = array_map(fn ($f) => $f->pattern->glob, $filters);
        self::assertContains('**/*.xphp', $globs);
        self::assertContains('**/*.php', $globs);
        // The scheme limits notifications to local files -- avoid
        // racing on remote/in-memory URIs the client may surface.
        foreach ($filters as $filter) {
            self::assertSame('file', $filter->scheme);
        }
    }

    public function testRenamesClassWhenSingleDeclarationMatchesBasename(): void
    {
        // PSR-4 happy path: the file declares exactly one class, and
        // its name matches the basename stem.  Renaming Collection.xphp
        // -> Zollection.xphp should rewrite `class Collection` ->
        // `class Zollection` AND every reference.
        $declSource = "<?php\nnamespace App;\nclass Collection {}\n";
        $consumerSource = "<?php\nnamespace App\\X;\nuse App\\Collection;\n\$c = new Collection();\n";
        file_put_contents($this->root . '/Collection.xphp', $declSource);
        file_put_contents($this->root . '/Consumer.xphp', $consumerSource);

        $workspace = new PhpactorWorkspace();
        // Open the decl + consumer so findReferences sees both.
        $workspace->open(new TextDocumentItem('file://' . $this->root . '/Collection.xphp', 'xphp', 1, $declSource));
        $workspace->open(new TextDocumentItem('file://' . $this->root . '/Consumer.xphp', 'xphp', 1, $consumerSource));

        $edit = $this->dispatch($workspace, [
            new FileRename('file://' . $this->root . '/Collection.xphp', 'file://' . $this->root . '/Zollection.xphp'),
        ]);

        self::assertNotNull($edit);
        $changes = $edit->documentChanges ?? [];
        // Two TextDocumentEdits: one for the decl, one for the
        // consumer.  No RenameFile op -- the client owns the file
        // move.
        $textEdits = array_values(array_filter($changes, fn ($c) => $c instanceof TextDocumentEdit));
        self::assertCount(2, $textEdits, 'one edit per file with a reference');
        $renameOps = array_values(array_filter($changes, fn ($c) => $c instanceof RenameFile));
        self::assertSame([], $renameOps, 'no RenameFile op -- the client moves the file');

        $uris = array_map(fn (TextDocumentEdit $e) => $e->textDocument->uri, $textEdits);
        self::assertContains('file://' . $this->root . '/Collection.xphp', $uris);
        self::assertContains('file://' . $this->root . '/Consumer.xphp', $uris);
    }

    public function testReturnsNullWhenBasenameStemEqualsAcrossRename(): void
    {
        // Move-without-rename: same basename, different directory.
        // The class declaration is unchanged; no edits to emit.
        $source = "<?php\nnamespace App;\nclass Foo {}\n";
        mkdir($this->root . '/sub', 0o755, true);
        file_put_contents($this->root . '/Foo.xphp', $source);

        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('file://' . $this->root . '/Foo.xphp', 'xphp', 1, $source));

        $edit = $this->dispatch($workspace, [
            new FileRename('file://' . $this->root . '/Foo.xphp', 'file://' . $this->root . '/sub/Foo.xphp'),
        ]);

        self::assertNull($edit);
    }

    public function testReturnsNullWhenNewBasenameIsNotAValidIdentifier(): void
    {
        // Client renamed Foo.xphp -> 123-Foo.xphp (or some shell-safe
        // but PHP-invalid name).  We can't generate a valid class
        // name from this; skip silently.
        $source = "<?php\nnamespace App;\nclass Foo {}\n";
        file_put_contents($this->root . '/Foo.xphp', $source);

        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('file://' . $this->root . '/Foo.xphp', 'xphp', 1, $source));

        $edit = $this->dispatch($workspace, [
            new FileRename('file://' . $this->root . '/Foo.xphp', 'file://' . $this->root . '/123Foo.xphp'),
        ]);

        self::assertNull($edit);
    }

    public function testReturnsNullWhenFileDeclaresMultipleClasses(): void
    {
        // Multi-class file: ambiguous which one to rename.  The
        // safety guard returns null; the plugin shows a confirmation
        // modal in this case and proceeds (or doesn't) per user
        // choice without our help.
        $source = "<?php\nnamespace App;\nclass Foo {}\nclass Bar {}\n";
        file_put_contents($this->root . '/Foo.xphp', $source);

        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('file://' . $this->root . '/Foo.xphp', 'xphp', 1, $source));

        $edit = $this->dispatch($workspace, [
            new FileRename('file://' . $this->root . '/Foo.xphp', 'file://' . $this->root . '/Renamed.xphp'),
        ]);

        self::assertNull($edit);
    }

    public function testReturnsNullWhenMultipleBracketedNamespacesEachDeclareOneClass(): void
    {
        // Bracketed-namespace form: `namespace A { class X {} }
        // namespace B { class Y {} }` -- two top-level Namespace_
        // stmts, each containing one ClassLike.  The outer foreach
        // must `continue` past the first namespace to count the
        // second namespace's class too -- a `break` mutant on line
        // 253 would exit after the first, see count=1, mistake the
        // file for a single-class PSR-4 candidate, and emit a
        // rename targeting the wrong half of the file.
        $source = "<?php\nnamespace A { class First {} }\nnamespace B { class Second {} }\n";
        file_put_contents($this->root . '/First.xphp', $source);

        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('file://' . $this->root . '/First.xphp', 'xphp', 1, $source));

        $edit = $this->dispatch($workspace, [
            new FileRename('file://' . $this->root . '/First.xphp', 'file://' . $this->root . '/Renamed.xphp'),
        ]);

        self::assertNull($edit, 'multiple bracketed namespaces must be detected as multi-class');
    }

    public function testReturnsNullWhenNamespaceContainsMultipleClasses(): void
    {
        // Distinct from testReturnsNullWhenFileDeclaresMultipleClasses:
        // the multi-class declarations sit INSIDE a `namespace App { ... }`
        // block (which is structurally a single top-level Namespace_
        // node, with the ClassLikes nested under stmts).  Exercises the
        // inner foreach loop in findClassLikeNameOffset that recurses
        // into namespace bodies -- the `continue` -> `break` mutant on
        // that loop would only iterate the first nested ClassLike and
        // miss the multi-class condition.
        $source = "<?php\nnamespace App;\nclass First {}\nclass Second {}\n";
        file_put_contents($this->root . '/First.xphp', $source);

        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('file://' . $this->root . '/First.xphp', 'xphp', 1, $source));

        $edit = $this->dispatch($workspace, [
            new FileRename('file://' . $this->root . '/First.xphp', 'file://' . $this->root . '/Renamed.xphp'),
        ]);

        self::assertNull($edit, 'inside-namespace multi-class file must be rejected');
    }

    public function testBatchContinuesPastNullFileToProcessLaterEntries(): void
    {
        // The `continue` after `if ($edit === null)` is load-bearing:
        // a `break` mutant would abandon the rest of the batch the
        // moment any single file produced no edits.  Set up: file A
        // is a multi-class non-PSR-4 file (produces null), file B is
        // a clean PSR-4 file (produces edits).  With `continue` we
        // get edits for B; with `break` we get null.
        file_put_contents($this->root . '/Mixed.xphp', "<?php\nnamespace App;\nclass One {}\nclass Two {}\n");
        $bSource = "<?php\nnamespace App;\nclass Beta {}\n";
        file_put_contents($this->root . '/Beta.xphp', $bSource);

        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('file://' . $this->root . '/Mixed.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass One {}\nclass Two {}\n"));
        $workspace->open(new TextDocumentItem('file://' . $this->root . '/Beta.xphp', 'xphp', 1, $bSource));

        $edit = $this->dispatch($workspace, [
            new FileRename('file://' . $this->root . '/Mixed.xphp', 'file://' . $this->root . '/MixedRenamed.xphp'),
            new FileRename('file://' . $this->root . '/Beta.xphp', 'file://' . $this->root . '/Bett.xphp'),
        ]);

        self::assertNotNull($edit, 'Beta.xphp produces edits even though Mixed.xphp was skipped');
        $uris = array_map(fn (TextDocumentEdit $e) => $e->textDocument->uri, $edit->documentChanges ?? []);
        self::assertContains('file://' . $this->root . '/Beta.xphp', $uris);
    }

    public function testReturnsNullWhenOneSideOfRenameHasEmptyBasename(): void
    {
        // basenameStem returns null when the URI's basename is empty
        // (e.g. a bare `file:///` with trailing slash, or a directory
        // path).  The guard `$oldStem === null || $newStem === null`
        // must fire when EITHER side is null -- `&&` mutant would
        // miss the case where one side is well-formed and the other
        // isn't.
        $edit = $this->dispatch(new PhpactorWorkspace(), [
            new FileRename('file:///', 'file://' . $this->root . '/Foo.xphp'),
        ]);

        self::assertNull($edit, 'empty old basename short-circuits to null');
    }

    public function testUsesWorkspaceBytesOverDiskWhenFileIsOpen(): void
    {
        // sourceFor's first branch (`if ($this->workspace->has($uri))`)
        // ensures unsaved buffer content drives the rename even when
        // it diverges from disk.  Without this, an `IfNegation` mutant
        // would silently use stale disk bytes -- the rename would
        // target the on-disk class name, not the in-buffer one.
        $diskSource = "<?php\nnamespace App;\nclass OnDisk {}\n";
        $bufferSource = "<?php\nnamespace App;\nclass InBuffer {}\n";
        file_put_contents($this->root . '/Buffer.xphp', $diskSource);
        $uri = 'file://' . $this->root . '/Buffer.xphp';

        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem($uri, 'xphp', 2, $bufferSource));

        // Rename Buffer.xphp -> InBuffer.xphp.  The basename match
        // requires the OLD basename (Buffer) to equal the class
        // name -- but in buffer the class is `InBuffer`, on disk it's
        // `OnDisk`.  Neither matches `Buffer`, so the safety guard
        // correctly returns null in BOTH cases.  Use a different
        // scenario: rename the disk-named file to match the buffer's
        // class.  The renamer must read buffer text to know the
        // current class name (InBuffer), then realise the basename
        // (Buffer) doesn't match and skip.  But that's the same
        // result either way.
        //
        // The cleaner pinning: open under the disk-matching name and
        // assert the rename uses buffer text.  Rename Buffer.xphp to
        // InBuffer.xphp -- buffer claims class is InBuffer, but
        // basename "Buffer" doesn't match.  We want a test where
        // workspace.text drives the rename in a way disk content
        // CAN'T produce.
        //
        // Setup: file `OnDisk.xphp` on disk with `class OnDisk`, but
        // user has unsaved edits making it `class OnDiskV2`.  Rename
        // file `OnDisk.xphp` to `OnDiskV2.xphp`.  Class declaration
        // in buffer is `OnDiskV2` (already renamed by user in source),
        // so the new short name `OnDiskV2` matches the existing class
        // -> the renamer should detect this and emit ZERO text edits
        // (class already named correctly), distinguishable from the
        // disk-source path where class would still be `OnDisk` and
        // edits would be emitted.
        $editFromBufferState = $this->dispatch($workspace, [
            new FileRename($uri, 'file://' . $this->root . '/InBuffer.xphp'),
        ]);
        // class in buffer = InBuffer, basename stem = Buffer (old).
        // Buffer != InBuffer -> not PSR-4 candidate -> null.
        // (If sourceFor returned disk bytes instead, class would be
        // OnDisk, still != Buffer, still null.)  This assertion
        // alone doesn't distinguish; the next one does.
        self::assertNull($editFromBufferState, 'basename mismatches buffer class -> null');

        // Reopen with a buffer where the class matches the OLD
        // basename -- this is the in-flight-rename scenario.  Disk
        // says `class OnDisk` (would match if we read disk), buffer
        // says `class Buffer` (matches the basename).  We want the
        // renamer to use the buffer.
        $workspace2 = new PhpactorWorkspace();
        $workspace2->open(new TextDocumentItem(
            $uri,
            'xphp',
            3,
            "<?php\nnamespace App;\nclass Buffer {}\n",
        ));
        $edit2 = $this->dispatch($workspace2, [
            new FileRename($uri, 'file://' . $this->root . '/Renamed.xphp'),
        ]);
        // With workspace bytes: class is Buffer (matches old basename)
        // -> renames to Renamed -> emits edits.
        // With disk bytes: class is OnDisk (mismatches old basename)
        // -> null.
        self::assertNotNull($edit2, 'workspace text must drive the rename, not disk');
        $changes = $edit2->documentChanges ?? [];
        self::assertCount(1, $changes, 'one text edit for the in-buffer class');
    }

    public function testReturnsNullWhenBasenameMismatchesDeclaredClassName(): void
    {
        // Non-PSR-4 layout: file is `Whatever.xphp` but declares
        // `class Something`.  We don't know what the user wants
        // renamed; safest to do nothing.
        $source = "<?php\nnamespace App;\nclass Something {}\n";
        file_put_contents($this->root . '/Whatever.xphp', $source);

        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('file://' . $this->root . '/Whatever.xphp', 'xphp', 1, $source));

        $edit = $this->dispatch($workspace, [
            new FileRename('file://' . $this->root . '/Whatever.xphp', 'file://' . $this->root . '/Renamed.xphp'),
        ]);

        self::assertNull($edit);
    }

    public function testHandlesInterfaceDeclarations(): void
    {
        // Interfaces follow PSR-4 exactly like classes.  This pins
        // the contract that "ClassLike" covers all four kinds.
        $source = "<?php\nnamespace App;\ninterface Reader {}\n";
        file_put_contents($this->root . '/Reader.xphp', $source);

        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('file://' . $this->root . '/Reader.xphp', 'xphp', 1, $source));

        $edit = $this->dispatch($workspace, [
            new FileRename('file://' . $this->root . '/Reader.xphp', 'file://' . $this->root . '/Loader.xphp'),
        ]);

        self::assertNotNull($edit);
        $changes = $edit->documentChanges ?? [];
        self::assertGreaterThanOrEqual(1, count($changes));
    }

    public function testHandlesTraitDeclarations(): void
    {
        $source = "<?php\nnamespace App;\ntrait Sluggable {}\n";
        file_put_contents($this->root . '/Sluggable.xphp', $source);

        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('file://' . $this->root . '/Sluggable.xphp', 'xphp', 1, $source));

        $edit = $this->dispatch($workspace, [
            new FileRename('file://' . $this->root . '/Sluggable.xphp', 'file://' . $this->root . '/Sluggish.xphp'),
        ]);

        self::assertNotNull($edit);
    }

    public function testHandlesEnumDeclarations(): void
    {
        $source = "<?php\nnamespace App;\nenum Status {}\n";
        file_put_contents($this->root . '/Status.xphp', $source);

        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('file://' . $this->root . '/Status.xphp', 'xphp', 1, $source));

        $edit = $this->dispatch($workspace, [
            new FileRename('file://' . $this->root . '/Status.xphp', 'file://' . $this->root . '/State.xphp'),
        ]);

        self::assertNotNull($edit);
    }

    public function testBatchOfRenamesAccumulatesEdits(): void
    {
        // A single notification can carry multiple file renames
        // (e.g. multi-select rename in PhpStorm's project tree).
        // The handler should accumulate edits across all files in
        // one WorkspaceEdit response.
        $aSource = "<?php\nnamespace App;\nclass A {}\n";
        $bSource = "<?php\nnamespace App;\nclass B {}\n";
        file_put_contents($this->root . '/A.xphp', $aSource);
        file_put_contents($this->root . '/B.xphp', $bSource);

        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('file://' . $this->root . '/A.xphp', 'xphp', 1, $aSource));
        $workspace->open(new TextDocumentItem('file://' . $this->root . '/B.xphp', 'xphp', 1, $bSource));

        $edit = $this->dispatch($workspace, [
            new FileRename('file://' . $this->root . '/A.xphp', 'file://' . $this->root . '/AA.xphp'),
            new FileRename('file://' . $this->root . '/B.xphp', 'file://' . $this->root . '/BB.xphp'),
        ]);

        self::assertNotNull($edit);
        $changes = $edit->documentChanges ?? [];
        $uris = array_map(fn (TextDocumentEdit $e) => $e->textDocument->uri, $changes);
        self::assertContains('file://' . $this->root . '/A.xphp', $uris);
        self::assertContains('file://' . $this->root . '/B.xphp', $uris);
    }

    public function testHonoursCancellationBetweenFilesInBatch(): void
    {
        // With a triggered cancellation token, the handler must
        // return null without processing further files.  Use the
        // single-rename case so we can synchronously assert on the
        // null return without arranging async cancellation timing.
        $source = "<?php\nnamespace App;\nclass A {}\n";
        file_put_contents($this->root . '/A.xphp', $source);

        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('file://' . $this->root . '/A.xphp', 'xphp', 1, $source));

        $source2 = new \Amp\CancellationTokenSource();
        $source2->cancel();
        $cancel = $source2->getToken();

        $handler = $this->handler($workspace);
        $result = wait($handler->willRenameFiles(
            new RenameFilesParams([
                new FileRename('file://' . $this->root . '/A.xphp', 'file://' . $this->root . '/AA.xphp'),
            ]),
            $cancel,
        ));

        self::assertNull($result);
    }

    /**
     * @param list<FileRename> $renames
     */
    private function dispatch(PhpactorWorkspace $workspace, array $renames): ?WorkspaceEdit
    {
        $handler = $this->handler($workspace);
        $result = wait($handler->willRenameFiles(new RenameFilesParams($renames)));
        if ($result === null) {
            return null;
        }
        self::assertInstanceOf(WorkspaceEdit::class, $result);
        return $result;
    }

    private function handler(PhpactorWorkspace $workspace): XphpWillRenameFilesHandler
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $fqnIndex = new FqnIndex($workspace, $cache, $parser, $this->root);
        $reflector = (new ReflectorFactory(
            $workspace,
            $cache,
            $parser,
            rootPath: $this->root,
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
        $renameProvider = new RenameProvider($workspace, $finder, $fqnIndex, false);
        return new XphpWillRenameFilesHandler($workspace, $cache, $parser, $renameProvider);
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
