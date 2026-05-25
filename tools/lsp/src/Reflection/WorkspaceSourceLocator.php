<?php

declare(strict_types=1);

namespace XPHP\Lsp\Reflection;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\TextDocument\TextDocument;
use Phpactor\TextDocument\TextDocumentBuilder;
use Phpactor\WorseReflection\Core\Exception\SourceNotFound;
use Phpactor\WorseReflection\Core\Name;
use Phpactor\WorseReflection\Core\SourceCodeLocator;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Serve worse-reflection from the LSP's in-memory workspace (the documents
 * PhpStorm has `didOpen`'d).  When worse-reflection asks "give me the source
 * for `App\Models\User`", we walk the open documents, find the one declaring
 * that class/interface/trait/function, strip its `<…>` generic clauses via
 * `XphpSourceParser::strip()`, and hand back a `TextDocument` with the
 * stripped source.
 *
 * Byte offsets in the stripped source are identical to the original .xphp
 * bytes (equal-length whitespace replacement), so any `Location` worse-reflection
 * derives from the parsed source maps cleanly back onto the editor's view.
 *
 * Index strategy: lazy + per-version-keyed.  We re-walk the workspace on each
 * `locate()` call, but every `$cache->getOrParse()` short-circuits to the cached
 * AST when the document version hasn't changed -- so the steady state is "N
 * cheap dict lookups per locate" for N open documents.  Building a persistent
 * inverted index (FQN -> URI) is a follow-up if profiling shows this is hot.
 *
 * Functions and ClassLike-s are both indexed; constants are not (constant FQNs
 * use a separate worse-reflection lookup path that we don't wire yet -- the
 * MVP GTD scope doesn't include `const`).
 */
final class WorkspaceSourceLocator implements SourceCodeLocator
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $cache,
        private readonly XphpSourceParser $parser,
    ) {
    }

    public function locate(Name $name): TextDocument
    {
        $needle = ltrim((string) $name, '\\');
        if ($needle === '') {
            throw new SourceNotFound('Empty name');
        }

        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            if (!self::declares($result->ast, $needle)) {
                continue;
            }
            return TextDocumentBuilder::create($this->parser->strip($item->text))
                ->uri((string) $uri)
                ->language('php')
                ->build();
        }

        throw new SourceNotFound(sprintf(
            'No open workspace document declares "%s"',
            $needle,
        ));
    }

    /**
     * @param list<Node\Stmt> $ast
     */
    private static function declares(array $ast, string $fqn): bool
    {
        $visitor = new class($fqn) extends NodeVisitorAbstract {
            public bool $found = false;
            private string $currentNamespace = '';

            public function __construct(private readonly string $needle)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($this->found) {
                    return null;
                }
                if ($node instanceof Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    return null;
                }
                if ($node instanceof ClassLike && $node->name !== null) {
                    if ($this->fqn($node->name->toString()) === $this->needle) {
                        $this->found = true;
                    }
                    return null;
                }
                if ($node instanceof Function_) {
                    if ($this->fqn($node->name->toString()) === $this->needle) {
                        $this->found = true;
                    }
                }
                return null;
            }

            private function fqn(string $short): string
            {
                return $this->currentNamespace !== ''
                    ? $this->currentNamespace . '\\' . $short
                    : $short;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->found;
    }
}
