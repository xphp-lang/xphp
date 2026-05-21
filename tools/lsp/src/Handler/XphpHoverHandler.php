<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\Promise;
use Amp\Success;
use PhpParser\Node\Stmt\ClassLike;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Hover;
use Phpactor\LanguageServerProtocol\HoverOptions;
use Phpactor\LanguageServerProtocol\HoverParams;
use Phpactor\LanguageServerProtocol\MarkupContent;
use Phpactor\LanguageServerProtocol\MarkupKind;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\Registry;
use XPHP\Transpiler\Monomorphize\TypeParam;
use XPHP\Transpiler\Monomorphize\TypeRef;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * `textDocument/hover` handler.
 *
 * Two cases for MVP:
 *
 *  1. Cursor over a Name node carrying ATTR_GENERIC_ARGS with all-concrete args
 *     -- show "Specializes to: <generated FQN>" plus the template and args.
 *
 *  2. Cursor over a 1-segment Name whose identifier matches a type-param
 *     declared on an enclosing ClassLike -- show "Type parameter T of <FQN>"
 *     plus the bound, if any.
 *
 * Not yet covered:
 *
 *  - Hover over bound names in template headers (`class Box<T: \Stringable>`):
 *    XphpSourceParser strips the `<…>` clause before parsing, so the AST has no
 *    Name node positioned over `\Stringable`. Surfacing this needs an extra
 *    scanner pass; defer to a follow-up alongside DefinitionHandler.
 *  - Hover over method-scoped type-params.
 */
final class XphpHoverHandler implements Handler, CanRegisterCapabilities
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $cache,
    ) {
    }

    public function methods(): array
    {
        return [
            'textDocument/hover' => 'hover',
        ];
    }

    // `registerCapabiltiies` is misspelled in phpactor's Handler interface (sic).
    // We match the typo deliberately — overriding requires the same name.
    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        $capabilities->hoverProvider = new HoverOptions();
    }

    /**
     * @return Promise<Hover|null>
     */
    public function hover(HoverParams $params): Promise
    {
        if (!$this->workspace->has($params->textDocument->uri)) {
            return new Success(null);
        }
        $item = $this->workspace->get($params->textDocument->uri);
        $result = $this->cache->getOrParse($params->textDocument->uri, $item->version, $item->text);
        if ($result->ast === null) {
            return new Success(null);
        }

        $positionMap = new PositionMap($item->text);
        $offset = $positionMap->positionToOffset(
            $params->position->line,
            $params->position->character,
        );

        $hit = AstPositionResolver::nameAtOffset($result->ast, $offset);
        if ($hit === null) {
            return new Success(null);
        }

        $markdown = $this->buildHoverMarkdown($hit['name'], $hit['classScope']);
        if ($markdown === null) {
            return new Success(null);
        }

        return new Success(new Hover(new MarkupContent(MarkupKind::MARKDOWN, $markdown)));
    }

    /**
     * @param list<ClassLike> $classScope
     */
    private function buildHoverMarkdown(\PhpParser\Node\Name $name, array $classScope): ?string
    {
        $args = $name->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
        $templateFqn = $name->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);

        // Case 1: hover over a generic instantiation.
        if (is_array($args) && $args !== [] && is_string($templateFqn) && self::allConcrete($args)) {
            $generatedFqn = Registry::generatedFqn($templateFqn, $args);
            $argsText = implode(', ', array_map(static fn (TypeRef $r): string => $r->toDisplayString(), $args));
            return sprintf(
                "**`%s<%s>`**\n\nSpecializes to: `\\%s`",
                $templateFqn,
                $argsText,
                $generatedFqn,
            );
        }

        // Case 2: hover over a single-segment Name that matches an enclosing
        // template's type-param.
        $parts = $name->getParts();
        if (count($parts) !== 1) {
            return null;
        }
        $shortName = $parts[0];
        foreach (array_reverse($classScope) as $classLike) {
            $params = $classLike->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
            if (!is_array($params)) {
                continue;
            }
            foreach ($params as $param) {
                if (!$param instanceof TypeParam || $param->name !== $shortName) {
                    continue;
                }
                $owner = $classLike->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
                $boundLine = $param->boundFqn !== null
                    ? sprintf("\n\nbounded by `\\%s`", $param->boundFqn)
                    : '';
                return sprintf(
                    "**Type parameter `%s`** of `%s`%s",
                    $param->name,
                    is_string($owner) ? $owner : (string) $classLike->name,
                    $boundLine,
                );
            }
        }

        return null;
    }

    /**
     * @param list<TypeRef> $args
     */
    private static function allConcrete(array $args): bool
    {
        foreach ($args as $a) {
            if (!$a->isConcrete()) {
                return false;
            }
        }
        return true;
    }
}
