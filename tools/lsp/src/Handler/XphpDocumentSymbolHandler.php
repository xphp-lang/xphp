<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\Promise;
use Amp\Success;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\DocumentSymbol;
use Phpactor\LanguageServerProtocol\DocumentSymbolParams;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\SymbolKind;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\ByteOffsetMap;

/**
 * `textDocument/documentSymbol` handler.
 *
 * Walks the per-document AST and emits a hierarchical DocumentSymbol tree.
 * Backs PhpStorm's Structure panel and the "Go to Symbol in File" (Cmd+O)
 * popup; VS Code's outline view reads the same shape.
 *
 * Tree:
 *   class / interface / trait / enum
 *     -> property | const | method | enum-case (as applicable)
 *   function (top-level)
 *
 * Namespaces aren't emitted as wrapping symbols; they'd inflate the panel
 * with a single level of indirection most users skip past.  The class FQN
 * is already in the worse-reflection hover.
 *
 * Server capability is advertised as bool `true` (not `DocumentSymbolOptions`)
 * for the same reason hover does -- PHP's null-stripping serializer encodes
 * an empty-options object as `[]`, which IntelliJ rejects.
 */
final class XphpDocumentSymbolHandler implements Handler, CanRegisterCapabilities
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $cache,
    ) {
    }

    public function methods(): array
    {
        return [
            'textDocument/documentSymbol' => 'documentSymbol',
        ];
    }

    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        $capabilities->documentSymbolProvider = true;
    }

    /**
     * @return Promise<list<DocumentSymbol>|null>
     */
    public function documentSymbol(DocumentSymbolParams $params): Promise
    {
        $uri = $params->textDocument->uri;
        if (!$this->workspace->has($uri)) {
            return new Success(null);
        }
        $item = $this->workspace->get($uri);
        $result = $this->cache->getOrParse($uri, $item->version, $item->text);
        if ($result->ast === null) {
            return new Success(null);
        }

        $positionMap = new PositionMap($item->text);
        $byteOffsetMap = $result->byteOffsetMap;
        $symbols = [];
        foreach ($result->ast as $stmt) {
            self::collectTopLevel($stmt, $positionMap, $byteOffsetMap, $symbols);
        }
        return new Success($symbols);
    }

    /**
     * @param list<DocumentSymbol> $out
     */
    private static function collectTopLevel(Node $stmt, PositionMap $map, ByteOffsetMap $offsets, array &$out): void
    {
        if ($stmt instanceof Namespace_) {
            foreach ($stmt->stmts as $child) {
                self::collectTopLevel($child, $map, $offsets, $out);
            }
            return;
        }
        if ($stmt instanceof ClassLike) {
            $sym = self::classLikeSymbol($stmt, $map, $offsets);
            if ($sym !== null) {
                $out[] = $sym;
            }
            return;
        }
        if ($stmt instanceof Function_) {
            $sym = self::functionSymbol($stmt, $map, $offsets);
            if ($sym !== null) {
                $out[] = $sym;
            }
        }
    }

    private static function classLikeSymbol(ClassLike $node, PositionMap $map, ByteOffsetMap $offsets): ?DocumentSymbol
    {
        if ($node->name === null) {
            // Anonymous classes have no entry point in the outline.
            return null;
        }
        $children = [];
        foreach ($node->stmts as $member) {
            if ($member instanceof ClassMethod) {
                $sym = self::methodSymbol($member, $map, $offsets);
                if ($sym !== null) {
                    $children[] = $sym;
                }
                continue;
            }
            if ($member instanceof Property) {
                foreach (self::propertySymbols($member, $map, $offsets) as $sym) {
                    $children[] = $sym;
                }
                continue;
            }
            if ($member instanceof ClassConst) {
                foreach (self::classConstSymbols($member, $map, $offsets) as $sym) {
                    $children[] = $sym;
                }
                continue;
            }
            if ($member instanceof EnumCase) {
                $sym = self::enumCaseSymbol($member, $map, $offsets);
                if ($sym !== null) {
                    $children[] = $sym;
                }
            }
        }

        return new DocumentSymbol(
            name: $node->name->toString(),
            kind: self::classLikeKind($node),
            range: self::rangeOf($node, $map, $offsets),
            selectionRange: self::rangeOf($node->name, $map, $offsets),
            children: $children,
        );
    }

    /**
     * @return SymbolKind::*
     */
    private static function classLikeKind(ClassLike $node): int
    {
        if ($node instanceof Interface_) {
            return SymbolKind::INTERFACE;
        }
        if ($node instanceof Trait_) {
            // LSP has no TRAIT kind.  CLASS_ renders with the class icon
            // in every client we care about; the name is enough to
            // distinguish.
            return SymbolKind::CLASS_;
        }
        if ($node instanceof Enum_) {
            return SymbolKind::ENUM;
        }
        // Class_ falls through.
        return SymbolKind::CLASS_;
    }

    private static function functionSymbol(Function_ $node, PositionMap $map, ByteOffsetMap $offsets): ?DocumentSymbol
    {
        return new DocumentSymbol(
            name: $node->name->toString(),
            kind: SymbolKind::FUNCTION,
            range: self::rangeOf($node, $map, $offsets),
            selectionRange: self::rangeOf($node->name, $map, $offsets),
        );
    }

    private static function methodSymbol(ClassMethod $node, PositionMap $map, ByteOffsetMap $offsets): ?DocumentSymbol
    {
        $name = $node->name->toString();
        $kind = strcasecmp($name, '__construct') === 0
            ? SymbolKind::CONSTRUCTOR
            : SymbolKind::METHOD;
        return new DocumentSymbol(
            name: $name,
            kind: $kind,
            range: self::rangeOf($node, $map, $offsets),
            selectionRange: self::rangeOf($node->name, $map, $offsets),
        );
    }

    /**
     * @return list<DocumentSymbol>
     */
    private static function propertySymbols(Property $node, PositionMap $map, ByteOffsetMap $offsets): array
    {
        $out = [];
        foreach ($node->props as $prop) {
            $out[] = new DocumentSymbol(
                name: '$' . $prop->name->toString(),
                kind: SymbolKind::PROPERTY,
                range: self::rangeOf($prop, $map, $offsets),
                selectionRange: self::rangeOf($prop->name, $map, $offsets),
            );
        }
        return $out;
    }

    /**
     * @return list<DocumentSymbol>
     */
    private static function classConstSymbols(ClassConst $node, PositionMap $map, ByteOffsetMap $offsets): array
    {
        $out = [];
        foreach ($node->consts as $const) {
            $out[] = new DocumentSymbol(
                name: $const->name->toString(),
                kind: SymbolKind::CONSTANT,
                range: self::rangeOf($const, $map, $offsets),
                selectionRange: self::rangeOf($const->name, $map, $offsets),
            );
        }
        return $out;
    }

    private static function enumCaseSymbol(EnumCase $node, PositionMap $map, ByteOffsetMap $offsets): ?DocumentSymbol
    {
        return new DocumentSymbol(
            name: $node->name->toString(),
            kind: SymbolKind::ENUM_MEMBER,
            range: self::rangeOf($node, $map, $offsets),
            selectionRange: self::rangeOf($node->name, $map, $offsets),
        );
    }

    private static function rangeOf(Node|Identifier $node, PositionMap $map, ByteOffsetMap $offsets): Range
    {
        // AST offsets index into the stripped source (post `T[] -> array`
        // rewrite).  Translate back to the original-source byte offsets
        // before mapping to line/character -- otherwise the LSP client
        // scrolls to the wrong place in any file using T[] sugar.
        $start = $offsets->toOriginal($node->getStartFilePos());
        $end = $offsets->toOriginal($node->getEndFilePos() + 1);
        if ($start < 0) {
            $start = 0;
        }
        if ($end < $start) {
            $end = $start;
        }
        [$startLine, $startChar] = $map->offsetToPosition($start);
        [$endLine, $endChar] = $map->offsetToPosition($end);
        return new Range(
            new Position($startLine, $startChar),
            new Position($endLine, $endChar),
        );
    }
}
