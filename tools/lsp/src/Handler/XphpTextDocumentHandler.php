<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Event\TextDocumentClosed;
use Phpactor\LanguageServer\Event\TextDocumentOpened;
use Phpactor\LanguageServer\Event\TextDocumentSaved;
use Phpactor\LanguageServer\Event\TextDocumentUpdated;
use Phpactor\LanguageServerProtocol\DidChangeTextDocumentParams;
use Phpactor\LanguageServerProtocol\DidCloseTextDocumentParams;
use Phpactor\LanguageServerProtocol\DidOpenTextDocumentParams;
use Phpactor\LanguageServerProtocol\DidSaveTextDocumentParams;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\TextDocumentSyncKind;
use Phpactor\LanguageServerProtocol\WillSaveTextDocumentParams;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Reimplementation of `phpactor/language-server`'s `TextDocumentHandler`.
 *
 * The vendor handler is declared `final`, so we can't subclass it.  We
 * reimplement the four lifecycle methods plus `CanRegisterCapabilities`
 * verbatim except for the `didChange` body, which the vendor ships as:
 *
 *     foreach ($params->contentChanges as $contentChange) {
 *         $this->dispatcher->dispatch(new TextDocumentUpdated(
 *             $params->textDocument,
 *             $contentChange['text']   // <-- bug
 *         ));
 *     }
 *
 * `$params->contentChanges` is populated by `DidChangeTextDocumentParams::fromArray()`
 * which routes each entry through `TextDocumentContentChangeFullEvent::fromArray()`
 * (under `TextDocumentSyncKind=Full`) -- producing a `TextDocumentContentChangeFullEvent`
 * OBJECT, not an array.  PHP 8 raises `Error: Cannot use object of type ... as
 * array` on the subscript, and the void-returning handler lets it propagate into
 * amphp's loop-level error handler which swallows it.  Net effect: every
 * didChange notification is dropped and `Workspace::update()` is never called,
 * so the editor's view of any buffer is frozen at didOpen time.
 *
 * Confirmed in production via `xphp-20260524-194635-862.log`: every
 * completion at `$repo->|` reads byte offset 365 (start of line 16) because
 * line 16 is empty in the v=0 didOpen snapshot, even though the user had
 * typed `$repo->` and PhpStorm had sent didChanges v1..v8.
 *
 * Fix: dispatch with `$contentChange->text` (property access).  Kept an
 * array-access fallback in case incremental sync ever lands and the
 * deserialiser produces a different shape; the fallback isn't exercised in
 * Full sync mode but it's a cheap safety net against future churn.
 */
final class XphpTextDocumentHandler implements Handler, CanRegisterCapabilities
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function methods(): array
    {
        return [
            'textDocument/didOpen' => 'didOpen',
            'textDocument/didChange' => 'didChange',
            'textDocument/didClose' => 'didClose',
            'textDocument/didSave' => 'didSave',
            'textDocument/willSave' => 'willSave',
            'textDocument/willSaveWaitUntil' => 'willSaveWaitUntil',
        ];
    }

    public function didOpen(DidOpenTextDocumentParams $params): void
    {
        $this->dispatcher->dispatch(new TextDocumentOpened($params->textDocument));
    }

    public function didChange(DidChangeTextDocumentParams $params): void
    {
        foreach ($params->contentChanges as $contentChange) {
            $text = is_object($contentChange)
                ? $contentChange->text
                : $contentChange['text'];
            $this->dispatcher->dispatch(new TextDocumentUpdated($params->textDocument, $text));
        }
    }

    public function didClose(DidCloseTextDocumentParams $params): void
    {
        $this->dispatcher->dispatch(new TextDocumentClosed($params->textDocument));
    }

    public function didSave(DidSaveTextDocumentParams $params): void
    {
        $this->dispatcher->dispatch(new TextDocumentSaved($params->textDocument, $params->text));
    }

    public function willSave(WillSaveTextDocumentParams $params): void
    {
    }

    public function willSaveWaitUntil(WillSaveTextDocumentParams $params): void
    {
    }

    // `registerCapabiltiies` is misspelled in phpactor's Handler interface (sic).
    // We match the typo deliberately -- overriding requires the same name.
    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        $capabilities->textDocumentSync = TextDocumentSyncKind::FULL;
    }
}
