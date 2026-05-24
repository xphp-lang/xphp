<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Resolve a ClassLike FQN to its AST by walking open workspace documents.
 *
 * Uses `ParsedDocumentCache` so the underlying parse is shared with every
 * other LSP consumer and respects document version.  Returns the cached
 * `ClassLike` node directly -- attributes attached by `XphpSourceParser`
 * during parse (including `ATTR_GENERIC_PARAMS`) are still present.
 *
 * Scope: open documents only.  A future `FilesystemClassLikeLookup` would
 * cover classes not currently open in the editor by re-parsing on demand;
 * the interface keeps both implementations interchangeable.
 */
final class WorkspaceClassLikeLookup implements ClassLikeLookup
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $cache,
    ) {
    }

    public function find(string $fqn): ?ClassLike
    {
        $needle = ltrim($fqn, '\\');
        if ($needle === '') {
            return null;
        }
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            $hit = self::findInAst($result->ast, $needle);
            if ($hit !== null) {
                return $hit;
            }
        }
        return null;
    }

    /**
     * @param list<Node\Stmt> $ast
     */
    private static function findInAst(array $ast, string $needle): ?ClassLike
    {
        $visitor = new class($needle) extends NodeVisitorAbstract {
            public ?ClassLike $found = null;

            public function __construct(private readonly string $needle)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($this->found !== null) {
                    return null;
                }
                if (!$node instanceof ClassLike) {
                    return null;
                }
                $fqn = $node->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
                if (is_string($fqn) && $fqn === $this->needle) {
                    $this->found = $node;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->found;
    }
}
