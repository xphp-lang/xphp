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
use XPHP\Lsp\Resolver\PhpDefinitionResolver;
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
        private readonly WorkspaceSymbols $workspaceSymbols,
        private readonly ?PhpDefinitionResolver $phpResolver = null,
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

        // Path 1: cursor on a Name carrying ATTR_TEMPLATE_FQN -- the
        // outer site of a generic instantiation (`Box` in
        // `new Box<Plastic>()`) or a generic function call (`identity` in
        // `identity<User>(...)`).  Navigate to the matching ClassLike
        // template declaration.
        $hit = AstPositionResolver::nameAtOffset($currentResult->ast, $offset);
        if ($hit !== null) {
            $templateFqn = $hit['name']->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
            if (is_string($templateFqn) && $templateFqn !== '') {
                $location = $this->findDefinitionAcrossWorkspace($templateFqn);
                if ($location !== null) {
                    return new Success($location);
                }
            }
        }

        // Path 2: cursor on a type-arg identifier INSIDE a generic clause
        // (`User` in `identity<User>(...)`).  These don't survive into the
        // AST as Name nodes -- XphpSourceParser strips them into TypeRef
        // marker entries on the surrounding call -- so AstPositionResolver
        // never lands a hit on them.  Use the source-level
        // TypeArgPositionDetector to extract the identifier under the
        // cursor and resolve it via WorkspaceSymbols (short-name match).
        $identifier = TypeArgPositionDetector::identifierAt($currentItem->text, $offset);
        if ($identifier !== null) {
            $shortName = self::lastSegment($identifier);
            $location = $this->workspaceSymbols->findClassByName($shortName);
            if ($location !== null) {
                return new Success($location);
            }
        }

        // Path 3: PHP-semantic GTD via worse-reflection.  Handles everything
        // the xphp-specific paths above don't: `use App\Models\User;`,
        // `new User(...)`, `$obj->method()`, `Cls::method()`, `strlen(...)`
        // (resolves to phpstorm-stubs), etc.  The resolver returns null
        // gracefully on unknown / unresolvable symbols, matching the LSP
        // expectation of "no answer" => no "Cannot find declaration"
        // noise from us.
        if ($this->phpResolver !== null) {
            return new Success($this->phpResolver->resolve(
                $params->textDocument->uri,
                $params->position->line,
                $params->position->character,
            ));
        }

        return new Success(null);
    }

    private static function lastSegment(string $identifier): string
    {
        $idx = strrpos($identifier, '\\');
        return $idx === false ? $identifier : substr($identifier, $idx + 1);
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
