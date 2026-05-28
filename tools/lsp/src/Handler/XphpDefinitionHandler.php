<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\CancellationToken;
use Amp\Promise;
use Amp\Success;
use PhpParser\Node;
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
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Resolver\PhpDefinitionResolver;
use XPHP\Lsp\Resolver\ReferenceFinder;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * `textDocument/definition` handler.
 *
 * On the cursor over a generic instantiation Name (e.g. `Box` in `new
 * Box<Plastic>()`), uses `FqnIndex` to locate the matching `ClassLike`
 * template declaration across BOTH open documents and the on-disk
 * filesystem under rootPath -- the jump works whether the target file is
 * open in the editor or sitting on disk untouched.
 *
 * The lookup is by `ATTR_TEMPLATE_FQN`: each parsed template carries the
 * fully-qualified template name as an attribute on the ClassLike node, set
 * by XphpSourceParser.  Open documents win on FQN collisions (the editor's
 * unsaved buffer beats the on-disk copy).
 *
 * Returns null when:
 *   - the document isn't open
 *   - the cursor isn't on a Name node carrying ATTR_TEMPLATE_FQN AND
 *     isn't on a type-arg identifier inside a `<…>` clause
 *   - no declaration with the matching FQN / short name exists anywhere
 *     in the indexed workspace
 */
final class XphpDefinitionHandler implements Handler, CanRegisterCapabilities
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $cache,
        private readonly WorkspaceSymbols $workspaceSymbols,
        private readonly FqnIndex $fqnIndex,
        private readonly ReferenceFinder $referenceFinder,
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
    public function definition(DefinitionParams $params, ?CancellationToken $cancel = null): Promise
    {
        if ($cancel !== null && $cancel->isRequested()) {
            return new Success(null);
        }
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

        // Path 0: cursor sits on a declaration's own name token
        // (`function foo`, `class Foo`, `public function method()`,
        // `public $prop`).  Standard GTD would either no-op (already AT
        // the decl) or return null.  Promote this to "find usages" --
        // when the user Ctrl+Clicks their own declaration they almost
        // always mean "show me everywhere this is used".  Return the
        // reference locations (excluding the decl itself).  PhpStorm
        // handles `Location[]` by popping a multi-target navigator.
        if (self::isOnDeclarationName($currentResult->ast, $offset)) {
            $references = $this->referenceFinder->findReferences(
                $params->textDocument->uri,
                $offset,
                includeDeclaration: false,
            );
            if ($references !== []) {
                return new Success($references);
            }
            // Fall through to normal GTD if there are no usages -- the
            // user sees "no targets" instead of nothing.
        }

        // Path 1: cursor on a Name carrying ATTR_TEMPLATE_FQN -- the
        // outer site of a generic instantiation (`Box` in
        // `new Box<Plastic>()`) or a generic function call (`identity` in
        // `identity<User>(...)`).  Navigate to the matching ClassLike
        // template declaration.  Phase 2.3: FqnIndex consults both open
        // docs and the filesystem so the jump works even when the
        // template lives in an unopened .xphp file.
        $hit = AstPositionResolver::nameAtOffset($currentResult->ast, $offset);
        if ($hit !== null) {
            $templateFqn = $hit['name']->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
            if (is_string($templateFqn) && $templateFqn !== '') {
                $location = self::locationToLsp($this->fqnIndex->locationForFqn($templateFqn));
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
        // cursor and resolve it via FqnIndex (open docs + filesystem
        // short-name match).
        $identifier = TypeArgPositionDetector::identifierAt($currentItem->text, $offset);
        if ($identifier !== null) {
            $shortName = self::lastSegment($identifier);
            $location = self::locationToLsp($this->fqnIndex->locationByShortName($shortName));
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
            // Cycle K: `resolveAll` returns 0..N locations.  Empty
            // collapses to null (LSP convention), single returns a
            // single Location, multi returns the array so PhpStorm
            // renders a picker for union/intersection receivers.
            $locations = $this->phpResolver->resolveAll(
                $params->textDocument->uri,
                $params->position->line,
                $params->position->character,
                $cancel,
            );
            return new Success(self::collapseLocations($locations));
        }

        return new Success(null);
    }

    /**
     * @param list<\Phpactor\LanguageServerProtocol\Location> $locations
     * @return \Phpactor\LanguageServerProtocol\Location|list<\Phpactor\LanguageServerProtocol\Location>|null
     */
    private static function collapseLocations(array $locations)
    {
        if ($locations === []) {
            return null;
        }
        if (count($locations) === 1) {
            return $locations[0];
        }
        return $locations;
    }

    /**
     * Detect whether the byte offset falls on the name token of a
     * Function_, ClassLike, ClassMethod, or PropertyItem declaration.
     * Used by Path 0 to promote GTD-on-decl into find-usages.
     *
     * @param list<Node\Stmt> $ast
     */
    private static function isOnDeclarationName(array $ast, int $byteOffset): bool
    {
        $visitor = new class($byteOffset) extends NodeVisitorAbstract {
            public bool $hit = false;

            public function __construct(private readonly int $offset)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($this->hit) {
                    return null;
                }
                $nameNode = null;
                if ($node instanceof Node\Stmt\ClassLike) {
                    $nameNode = $node->name;
                } elseif ($node instanceof Node\Stmt\Function_) {
                    $nameNode = $node->name;
                } elseif ($node instanceof Node\Stmt\ClassMethod) {
                    $nameNode = $node->name;
                } elseif ($node instanceof Node\PropertyItem) {
                    $nameNode = $node->name;
                }
                if ($nameNode === null) {
                    return null;
                }
                $s = $nameNode->getStartFilePos();
                $e = $nameNode->getEndFilePos();
                if ($s >= 0 && $this->offset >= $s && $this->offset <= $e) {
                    $this->hit = true;
                }
                return null;
            }
        };
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->hit;
    }

    private static function lastSegment(string $identifier): string
    {
        $idx = strrpos($identifier, '\\');
        return $idx === false ? $identifier : substr($identifier, $idx + 1);
    }

    /**
     * @param array{uri: string, line: int, char: int, short: string}|null $hit
     */
    private static function locationToLsp(?array $hit): ?Location
    {
        if ($hit === null) {
            return null;
        }
        $endChar = $hit['char'] + strlen($hit['short']);
        return new Location(
            $hit['uri'],
            new Range(
                new Position($hit['line'], $hit['char']),
                new Position($hit['line'], $endChar),
            ),
        );
    }

}
