<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\CancellationToken;
use Amp\Promise;
use Amp\Success;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\InlayHint;
use Phpactor\LanguageServerProtocol\InlayHintKind;
use Phpactor\LanguageServerProtocol\InlayHintParams;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Throwable;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Resolver\GenericResolver;

/**
 * `textDocument/inlayHint` handler.
 *
 * Renders inline type annotations after variable assignments whose
 * RHS is a call site with a generic-substituted return type that
 * the source doesn't make visible:
 *
 *   $users = new Collection<User>();   // type explicit, NO hint
 *   $first = $users->first();          // → `: ?App\Models\User`  ← hint
 *   $picks = Util::first<User>($xs);   // → `: ?App\Models\User`  ← hint
 *
 * Reuses GenericResolver's
 * `resolveMethodCallSubstitutionAt` / `resolveStaticCallSubstitutionAt` /
 * `resolveFunctionCallSubstitutionAt` -- the same substitution machinery
 * that drives hover-on-method.  An inlay hint fires when the substitution
 * produces a non-null `returnType`.
 *
 * Available since IntelliJ Platform 2025.2.2.
 *
 * Limitations called out for follow-up:
 *  - Only top-level Assign nodes are walked.  Chained assignments
 *    (`$a = $b = $c->x()`) get a hint for the outer LHS only.
 *  - Closures' captured-variable types aren't hinted.
 *  - The substitution path uses worse-reflection internally;
 *    cancellation between AST walk and substitution lookup isn't
 *    threaded all the way through GenericResolver.
 */
final class XphpInlayHintHandler implements Handler, CanRegisterCapabilities
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $cache,
        private readonly GenericResolver $genericResolver,
    ) {
    }

    public function methods(): array
    {
        return [
            'textDocument/inlayHint' => 'inlayHint',
        ];
    }

    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        $capabilities->inlayHintProvider = true;
    }

    /**
     * @return Promise<list<InlayHint>>
     */
    public function inlayHint(InlayHintParams $params, ?CancellationToken $cancel = null): Promise
    {
        if ($cancel !== null && $cancel->isRequested()) {
            return new Success([]);
        }
        $uri = $params->textDocument->uri;
        if (!$this->workspace->has($uri)) {
            return new Success([]);
        }
        $item = $this->workspace->get($uri);
        $result = $this->cache->getOrParse($uri, $item->version, $item->text);
        if ($result->ast === null) {
            return new Success([]);
        }
        $map = new PositionMap($item->text);

        $assigns = self::collectAssigns($result->ast);
        $hints = [];
        foreach ($assigns as $assign) {
            try {
                $hint = $this->hintForAssign($assign, $uri, $map);
            } catch (Throwable) {
                continue;
            }
            if ($hint !== null) {
                $hints[] = $hint;
            }
        }
        return new Success($hints);
    }

    /**
     * @param list<Node\Stmt> $ast
     * @return list<Assign>
     */
    private static function collectAssigns(array $ast): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<Assign> */
            public array $assigns = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof Assign && $node->var instanceof Variable) {
                    $this->assigns[] = $node;
                }
                return null;
            }
        };
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->assigns;
    }

    private function hintForAssign(Assign $assign, string $uri, PositionMap $map): ?InlayHint
    {
        $rhs = $assign->expr;
        $substitution = null;
        if ($rhs instanceof MethodCall && $rhs->name instanceof Node\Identifier) {
            $nameStart = $rhs->name->getStartFilePos();
            if ($nameStart >= 0) {
                $substitution = $this->genericResolver->resolveMethodCallSubstitutionAt($uri, $nameStart);
            }
        } elseif ($rhs instanceof StaticCall && $rhs->name instanceof Node\Identifier) {
            $nameStart = $rhs->name->getStartFilePos();
            if ($nameStart >= 0) {
                $substitution = $this->genericResolver->resolveStaticCallSubstitutionAt($uri, $nameStart);
            }
        } elseif ($rhs instanceof FuncCall && $rhs->name instanceof Node\Name) {
            $nameStart = $rhs->name->getStartFilePos();
            if ($nameStart >= 0) {
                $substitution = $this->genericResolver->resolveFunctionCallSubstitutionAt($uri, $nameStart);
            }
        }

        if ($substitution === null || $substitution->returnType === null) {
            return null;
        }
        // Render the hint AFTER the variable name so it visually sits
        // between the variable and the `=` sign:
        //   $first[: ?App\Models\User] = $users->first();
        $var = $assign->var;
        $varEnd = $var->getEndFilePos();
        if ($varEnd < 0) {
            return null;
        }
        [$line, $character] = $map->offsetToPosition($varEnd + 1);

        return new InlayHint(
            position: new Position($line, $character),
            label: ': ' . $substitution->returnType,
            kind: InlayHintKind::TYPE,
            paddingLeft: true,
        );
    }
}
