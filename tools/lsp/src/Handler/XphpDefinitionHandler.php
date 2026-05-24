<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\Promise;
use Amp\Success;
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
