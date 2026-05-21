<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;

/**
 * Walks every open document and collects the FQNs of every ClassLike (class,
 * interface, trait). Used by the completion handler to suggest candidates
 * inside `<…>` type-arg positions.
 *
 * Parses via the shared `ParsedDocumentCache` so an unchanged workspace
 * doesn't re-parse on every completion keystroke (the original MVP did,
 * which is O(N) parses per `<`).
 */
final readonly class WorkspaceSymbols
{
    public function __construct(
        private PhpactorWorkspace $workspace,
        private ParsedDocumentCache $cache,
    ) {
    }

    /**
     * @return list<string>  Fully-qualified ClassLike names across the open workspace.
     */
    public function allClassFqns(): array
    {
        $fqns = [];
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            foreach (self::collectFqns($result->ast) as $fqn) {
                $fqns[$fqn] = true;
            }
        }
        return array_keys($fqns);
    }

    /**
     * @param list<Node\Stmt> $ast
     * @return list<string>
     */
    private static function collectFqns(array $ast): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<string> */
            public array $fqns = [];

            private string $currentNamespace = '';

            public function enterNode(Node $node): null
            {
                if ($node instanceof Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                }
                if ($node instanceof ClassLike && $node->name !== null) {
                    $short = $node->name->toString();
                    $this->fqns[] = $this->currentNamespace !== ''
                        ? $this->currentNamespace . '\\' . $short
                        : $short;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->fqns;
    }
}
