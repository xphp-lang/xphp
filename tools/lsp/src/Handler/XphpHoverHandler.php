<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\CancellationToken;
use Amp\Promise;
use Amp\Success;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Hover;
use Phpactor\LanguageServerProtocol\HoverParams;
use Phpactor\LanguageServerProtocol\MarkupContent;
use Phpactor\LanguageServerProtocol\MarkupKind;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Resolver\PhpHoverResolver;
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
        private readonly ?PhpHoverResolver $phpResolver = null,
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
        // Use the bool form of `hoverProvider`, NOT `new HoverOptions()`.
        //
        // `hoverProvider` is `Either<Boolean, HoverOptions>` in the LSP spec.
        // PHP's json_encode + phpactor's null-stripping serializer turns a
        // default-constructed `HoverOptions` (workDoneProgress=null, the
        // class's only field) into `[]` -- because an empty associative
        // array is indistinguishable from an empty indexed array in PHP,
        // and json_encode picks the array form.  IntelliJ's LSP4J client
        // then rejects the response with:
        //
        //   Unexpected token BEGIN_ARRAY: expected BOOLEAN | BEGIN_OBJECT
        //
        // ...and kills the server before the handshake completes.
        // `true` is a valid Either value, encodes unambiguously, and matches
        // the shape we already use for definitionProvider just below.
        $capabilities->hoverProvider = true;
    }

    /**
     * @return Promise<Hover|null>
     */
    public function hover(HoverParams $params, ?CancellationToken $cancel = null): Promise
    {
        if ($cancel !== null && $cancel->isRequested()) {
            return new Success(null);
        }
        if (!$this->workspace->has($params->textDocument->uri)) {
            return new Success(null);
        }
        $item = $this->workspace->get($params->textDocument->uri);
        $result = $this->cache->getOrParse($params->textDocument->uri, $item->version, $item->text);
        if ($result->ast === null) {
            return new Success(null);
        }
        if ($cancel !== null && $cancel->isRequested()) {
            // Parse completed but the user has moved on -- skip the
            // (potentially expensive) PhpHoverResolver / FQN lookup
            // path below.
            return new Success(null);
        }

        $positionMap = new PositionMap($item->text);
        $offset = $positionMap->positionToOffset(
            $params->position->line,
            $params->position->character,
        );

        $hit = AstPositionResolver::nameAtOffset($result->ast, $offset);
        if ($hit !== null) {
            $markdown = $this->buildHoverMarkdown($hit['name'], $hit['classScope']);
            if ($markdown !== null) {
                return new Success(new Hover(new MarkupContent(MarkupKind::MARKDOWN, $markdown)));
            }
        }

        // Cursor inside a `<...>` type-arg clause of a generic
        // instantiation?  XphpSourceParser replaces `<...>` with
        // equal-length whitespace before parsing, so AstPositionResolver
        // doesn't find a Name node here and worse-reflection
        // misattributes the offset to the enclosing `new Cls(...)`
        // expression -- giving a hover on `Cls` for a cursor on a
        // type-arg.  Resolve via ATTR_GENERIC_ARGS on the enclosing
        // Name node and render the type-arg's class hover instead.
        $typeArgFqn = self::typeArgFqnAt($result->ast, $item->text, $offset);
        if ($typeArgFqn !== null) {
            if ($this->phpResolver !== null) {
                $hover = $this->phpResolver->renderClassHover($typeArgFqn);
                if ($hover !== null) {
                    return new Success($hover);
                }
            }
            // No resolver wired, or worse-reflection couldn't find the
            // class -- still emit a minimal markdown so the user sees
            // the resolved FQN rather than the misattributed outer
            // class.
            return new Success(new Hover(new MarkupContent(
                MarkupKind::MARKDOWN,
                sprintf('**`class \\%s`**', $typeArgFqn),
            )));
        }

        // Fall through to PHP-semantic hover via worse-reflection.  Handles
        // everything the xphp-specific paths above don't: class names,
        // function calls, method/property access, native functions
        // (from phpstorm-stubs), etc.
        if ($this->phpResolver !== null) {
            $hover = $this->phpResolver->resolve(
                $params->textDocument->uri,
                $params->position->line,
                $params->position->character,
                $cancel,
            );
            if ($hover !== null) {
                return new Success($hover);
            }
        }

        return new Success(null);
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

    /**
     * Resolve the FQN of the top-level type-arg the cursor sits inside
     * for a generic instantiation's `<...>` clause.  Returns null when
     * the cursor is outside any angle clause, on a type-param ref,
     * on a scalar, or on a nested arg (nested handling is a follow-up).
     *
     * @param list<Node\Stmt> $ast
     */
    private static function typeArgFqnAt(array $ast, string $source, int $offset): ?string
    {
        $hit = self::angleClauseAt($ast, $source, $offset);
        if ($hit === null) {
            return null;
        }
        $relativeOffset = $offset - $hit['innerStart'];
        $argIndex = self::topLevelArgIndexAt($hit['innerText'], $relativeOffset);
        if ($argIndex === null || !isset($hit['args'][$argIndex])) {
            return null;
        }
        $arg = $hit['args'][$argIndex];
        if ($arg->isTypeParam || $arg->isScalar || $arg->name === '') {
            return null;
        }
        return $arg->name;
    }

    /**
     * Walk the AST for a Name node carrying ATTR_GENERIC_ARGS whose
     * original-source angle clause (`<...>`) strictly contains
     * $offset (between `<` and `>` exclusive).  Uses NodeFinder +
     * closure (not a NodeTraverser visitor) so the mutation-test
     * ignore rules attribute every guard inside this routine to
     * `angleClauseAt` rather than to an opaque anonymous-class
     * `enterNode`.
     *
     * @param list<Node\Stmt> $ast
     * @return array{args: list<TypeRef>, innerStart: int, innerText: string}|null
     */
    private static function angleClauseAt(array $ast, string $source, int $offset): ?array
    {
        $finder = new NodeFinder();
        foreach ($finder->find($ast, static fn (Node $n): bool => $n instanceof Name) as $node) {
            $args = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
            if (!is_array($args) || $args === []) {
                continue;
            }
            $nameEnd = $node->getEndFilePos();
            if ($nameEnd < 0) {
                continue;
            }
            $range = self::findAngleRange($source, $nameEnd);
            if ($range === null) {
                continue;
            }
            // Strictly inside: cursor on `<` or `>` doesn't count;
            // those positions sit on the angle delimiters which
            // belong to the generic-syntax sugar, not any arg.
            if ($offset <= $range['openPos'] || $offset >= $range['closePos']) {
                continue;
            }
            return [
                'args' => array_values($args),
                'innerStart' => $range['openPos'] + 1,
                'innerText' => substr(
                    $source,
                    $range['openPos'] + 1,
                    $range['closePos'] - $range['openPos'] - 1,
                ),
            ];
        }
        return null;
    }

    /**
     * Locate the angle-clause byte range immediately following a Name
     * node, skipping whitespace.  Returns positions of `<` and the
     * matching `>` in the original source, or null when no clause
     * is present or it's unterminated.
     *
     * @return array{openPos: int, closePos: int}|null
     */
    public static function findAngleRange(string $source, int $nameEnd): ?array
    {
        $n = strlen($source);
        $i = $nameEnd + 1;
        while ($i < $n && ctype_space($source[$i])) {
            $i++;
        }
        if ($i >= $n || $source[$i] !== '<') {
            return null;
        }
        $openPos = $i;
        $depth = 1;
        $j = $i + 1;
        while ($j < $n && $depth > 0) {
            $c = $source[$j];
            if ($c === '<') {
                $depth++;
            } elseif ($c === '>') {
                $depth--;
            }
            $j++;
        }
        if ($depth !== 0) {
            return null;
        }
        return ['openPos' => $openPos, 'closePos' => $j - 1];
    }

    /**
     * Index of the top-level arg containing $offset within the inner
     * text of a `<...>` clause (between `<` and `>` exclusive).
     * Counts `,` at nesting depth 0; nested `<...>` clauses don't
     * split the outer arg.
     */
    private static function topLevelArgIndexAt(string $innerText, int $offset): ?int
    {
        $n = strlen($innerText);
        if ($offset < 0 || $offset > $n) {
            return null;
        }
        $depth = 0;
        $index = 0;
        for ($i = 0; $i < $offset; $i++) {
            $c = $innerText[$i];
            if ($c === '<') {
                $depth++;
            } elseif ($c === '>') {
                if ($depth > 0) {
                    $depth--;
                }
            } elseif ($c === ',' && $depth === 0) {
                $index++;
            }
        }
        return $index;
    }
}
