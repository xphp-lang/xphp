<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\Promise;
use Amp\Success;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServerProtocol\CodeAction;

/**
 * `codeAction/resolve` handler.
 *
 * Round-trips a CodeAction the client received from
 * `textDocument/codeAction` and enriches it with the WorkspaceEdit
 * payload that actually performs the fix.  Lazy resolution keeps
 * the initial codeAction response small: building a full
 * WorkspaceEdit (which may require workspace-wide AST walks for
 * "import missing class" or "rename across files") is too
 * expensive to do for every potential action the editor's
 * lightbulb might render.
 *
 * Currently scaffolding -- there are no actions to resolve yet
 * (see XphpCodeActionHandler).  Once specific quick-fixes land,
 * each emits a `data` payload identifying its kind + target, and
 * this handler dispatches on that payload to construct the
 * WorkspaceEdit.
 *
 * Available since IntelliJ Platform 2024.2.
 */
final class XphpCodeActionResolveHandler implements Handler
{
    public function methods(): array
    {
        return [
            'codeAction/resolve' => 'resolve',
        ];
    }

    /**
     * @return Promise<CodeAction>
     */
    public function resolve(CodeAction $action): Promise
    {
        // No-op for now -- XphpCodeActionHandler emits an empty
        // action list, so this method is never called in
        // practice.  Future commits will add per-kind dispatch
        // here.
        return new Success($action);
    }
}
