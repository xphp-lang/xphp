<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\Promise;
use Amp\Success;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\DefinitionParams;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * `textDocument/definition` handler.
 *
 * On the cursor over a generic instantiation Name (e.g. `Box` in `new Box<Plastic>()`),
 * walks the AST of every currently-open document to find the matching
 * `ClassLike` template declaration and returns its source Location.
 *
 * The lookup is by `ATTR_TEMPLATE_FQN`: each parsed template carries the
 * fully-qualified template name as an attribute on the ClassLike node, set by
 * XphpSourceParser. We re-parse open documents on each request (cheap; the
 * analyzer is already optimized for re-parse on change). A workspace-wide
 * symbol cache is a follow-up alongside CompletionHandler.
 *
 * Returns null when:
 *   - the document isn't open
 *   - the cursor isn't on a Name node carrying ATTR_TEMPLATE_FQN
 *   - no open document defines a template with the matching FQN (the
 *     definition lives on disk but unopened, which we don't index yet)
 */
final class XphpDefinitionHandler implements Handler, CanRegisterCapabilities
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $cache,
    ) {
    }

    public function methods(): array
    {
        return [
            'textDocument/definition' => 'definition',
        ];
    }

    // `registerCapabiltiies` is misspelled in phpactor's Handler interface (sic).
    // We match the typo deliberately — overriding requires the same name.
    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        $capabilities->definitionProvider = true;
    }

    /**
     * @return Promise<Location|null>
     */
    public function definition(DefinitionParams $params): Promise
    {
        if (!$this->workspace->has($params->textDocument->uri)) {
            return new Success(null);
        }
        $currentItem = $this->workspace->get($params->textDocument->uri);
        $currentResult = $this->cache->getOrParse(
            $params->textDocument->uri,
            $currentItem->version,
            $currentItem->text,
        );
        if ($currentResult->ast === null) {
            return new Success(null);
        }

        $offset = (new PositionMap($currentItem->text))->positionToOffset(
            $params->position->line,
            $params->position->character,
        );
        $hit = AstPositionResolver::nameAtOffset($currentResult->ast, $offset);
        if ($hit === null) {
            return new Success(null);
        }

        $templateFqn = $hit['name']->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
        if (!is_string($templateFqn) || $templateFqn === '') {
            return new Success(null);
        }

        return new Success($this->findDefinitionAcrossWorkspace($templateFqn));
    }

    private function findDefinitionAcrossWorkspace(string $templateFqn): ?Location
    {
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            $found = self::findTemplateInAst($result->ast, $templateFqn);
            if ($found === null) {
                continue;
            }
            $positionMap = new PositionMap($item->text);
            [$startLine, $startChar] = $positionMap->offsetToPosition($found['startOffset']);
            [$endLine, $endChar] = $positionMap->offsetToPosition($found['endOffset']);
            return new Location(
                $uri,
                new Range(
                    new Position($startLine, $startChar),
                    new Position($endLine, $endChar),
                ),
            );
        }
        return null;
    }

    /**
     * @param list<Node\Stmt> $ast
     * @return array{startOffset: int, endOffset: int}|null
     */
    private static function findTemplateInAst(array $ast, string $templateFqn): ?array
    {
        $visitor = new class($templateFqn) extends NodeVisitorAbstract {
            public ?int $startOffset = null;
            public ?int $endOffset = null;

            public function __construct(private readonly string $templateFqn)
            {
            }

            public function enterNode(Node $node): null
            {
                if (!$node instanceof ClassLike || $node->name === null) {
                    return null;
                }
                $fqn = $node->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
                if ($fqn !== $this->templateFqn) {
                    return null;
                }
                // Range targets the class NAME, not the entire ClassLike body —
                // jumps land on the identifier the user expects, not the
                // opening line including modifiers.
                $this->startOffset = $node->name->getStartFilePos();
                $this->endOffset = $node->name->getEndFilePos() + 1;
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        if ($visitor->startOffset === null || $visitor->endOffset === null) {
            return null;
        }
        return ['startOffset' => $visitor->startOffset, 'endOffset' => $visitor->endOffset];
    }
}
