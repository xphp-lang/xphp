<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler\SemanticTokens;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpToken;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\ByteOffsetMap;

/**
 * Walk the xphp source + AST and emit {@see TokenSpec} entries for the
 * PHP-shaped surface (keywords, variables, numbers, strings, comments,
 * class / interface / enum / function / method / property names) plus
 * the xphp generic forms (Slice 3, not yet implemented).
 *
 * Two passes:
 *
 * 1. **Token scan** via PHP's built-in {@see PhpToken::tokenize}.  The
 *    tokens are byte-indexed into the ORIGINAL source (not the stripped
 *    buffer), so positions feed directly into {@see PositionMap}.
 *    Emits keywords, variables, numbers, single-quoted strings,
 *    double-quoted strings (as a single span -- interpolation
 *    classification is deferred to Slice 4), and comments.  Deliberately
 *    skips T_STRING (identifiers) so the AST pass can classify each
 *    identifier into its semantic role without overlap.
 *
 * 2. **AST walk** of the nikic-parsed tree.  AST node offsets index
 *    into the STRIPPED source (`<...>` clauses excised), so positions
 *    pass through {@see ByteOffsetMap::toOriginal} before
 *    {@see PositionMap}.  Emits the identifier kinds the token scan
 *    can't classify on its own: ClassLike names -> `class` /
 *    `interface` / `enum`, ClassMethod names -> `method`, Function_
 *    names -> `function`, PropertyItem names -> `property`, Param
 *    names -> `parameter`.
 *
 * Slice 3 will extend the AST pass to recognise xphp generic
 * `ATTR_GENERIC_PARAMS` / `ATTR_GENERIC_ARGS` decorations and emit
 * `typeParameter` for every `T` in the 12 audit forms.
 */
final class AstVisitor
{
    /**
     * @var array<int, string>  T_* token-id -> semantic-token type for the
     *                          subset PhpToken-based classification covers.
     */
    private static array $tokenTypeMap;

    public function __construct(
        private readonly PositionMap $positionMap,
        private readonly ByteOffsetMap $byteOffsetMap,
        private readonly string $source,
    ) {
        if (!isset(self::$tokenTypeMap)) {
            self::$tokenTypeMap = self::buildTokenTypeMap();
        }
    }

    /**
     * @param  array<int, Node> $stmts
     * @return list<TokenSpec>
     */
    public function visit(array $stmts): array
    {
        $specs = [];

        $this->collectFromTokens($specs);

        if ($stmts !== []) {
            $traverser = new NodeTraverser();
            $traverser->addVisitor($this->newAstWalker($specs));
            $traverser->traverse($stmts);
        }

        return $specs;
    }

    /**
     * Pass 1: tokenize the original source and emit specs for the token
     * classes that don't need AST context.
     *
     * @param list<TokenSpec> $out
     */
    private function collectFromTokens(array &$out): void
    {
        // Non-strict tokenization (flags=0).  TOKEN_PARSE turns
        // PhpToken into a strict-mode tokenizer that throws ParseError
        // on the `<T>` we use for generics.  In non-strict mode the
        // `<` and `T` just come back as their literal tokens; we
        // ignore unclassified single-char tokens anyway.
        $tokens = @PhpToken::tokenize($this->source);
        foreach ($tokens as $token) {
            if (!is_int($token->id)) {
                continue;
            }
            $type = self::$tokenTypeMap[$token->id] ?? null;
            if ($type === null) {
                continue;
            }
            $offset = $token->pos;
            $length = strlen($token->text);
            $this->emit($out, $offset, $length, $type);
        }
    }

    /**
     * Pass 2: walk the AST and emit specs for identifier kinds that
     * the token scan can't classify on its own.
     *
     * @param list<TokenSpec> &$out
     */
    private function newAstWalker(array &$out): NodeVisitorAbstract
    {
        $visitor = new class($out, $this) extends NodeVisitorAbstract {
            /**
             * @param list<TokenSpec> $out
             */
            public function __construct(
                private array &$out,
                private AstVisitor $emitter,
            ) {
            }

            public function enterNode(Node $node)
            {
                if ($node instanceof ClassLike && $node->name !== null) {
                    $this->emitter->emitAstIdentifier(
                        $this->out,
                        $node->name,
                        self::classLikeType($node),
                    );
                    return null;
                }
                if ($node instanceof ClassMethod) {
                    $this->emitter->emitAstIdentifier($this->out, $node->name, 'method');
                    return null;
                }
                if ($node instanceof Function_) {
                    $this->emitter->emitAstIdentifier($this->out, $node->name, 'function');
                    return null;
                }
                if ($node instanceof PropertyItem) {
                    // PropertyItem->name is a VarLikeIdentifier (no leading `$`
                    // in the AST but the `$` IS in the source span).  Skip
                    // re-emit; T_VARIABLE in pass 1 already covered it.
                    return null;
                }
                if ($node instanceof Param && $node->var instanceof Node\Expr\Variable) {
                    // Re-classify the param variable from `variable` to
                    // `parameter`.  Same source span, different type.
                    // The token-scan pass already emitted `variable` here;
                    // we add a second spec, and rely on PhpStorm/VS Code
                    // honouring the LAST one at the same position.  In
                    // practice both clients treat overlapping tokens as
                    // "later wins"; tests assert this.
                    $name = $node->var->name;
                    if (is_string($name)) {
                        // Variable name is `name` (string) -- AST positions
                        // are at the `$` of $foo.  Emit at the same offset.
                        $start = $node->var->getStartFilePos();
                        $end = $node->var->getEndFilePos();
                        if ($start >= 0 && $end >= $start) {
                            $this->emitter->emitAstSpan(
                                $this->out,
                                $start,
                                $end - $start + 1,
                                'parameter',
                            );
                        }
                    }
                    return null;
                }
                return null;
            }

            private static function classLikeType(ClassLike $node): string
            {
                if ($node instanceof Interface_) {
                    return 'interface';
                }
                if ($node instanceof Enum_) {
                    return 'enum';
                }
                if ($node instanceof Trait_) {
                    // No `trait` in LSP standard token types; map to `class`.
                    return 'class';
                }
                return 'class';
            }
        };
        return $visitor;
    }

    /**
     * Emit a spec at the given ORIGINAL-source byte offset.  Internal --
     * shared by both passes; the token pass calls directly, the AST pass
     * calls {@see emitAstSpan} which translates from stripped to
     * original first.
     *
     * @internal exposed for the anonymous AST visitor; not a public API
     *
     * @param list<TokenSpec> $out
     */
    public function emit(array &$out, int $originalOffset, int $length, string $type, array $modifiers = []): void
    {
        if ($length <= 0) {
            return;
        }
        if ($originalOffset < 0 || $originalOffset > strlen($this->source)) {
            return;
        }
        [$line, $startChar] = $this->positionMap->offsetToPosition($originalOffset);
        // Length stays in BYTES at this point -- correct for ASCII-only
        // identifiers (the vast majority of PHP source).  LSP wants
        // UTF-16 code units; for ASCII the two are equal.  Non-ASCII
        // tokens (e.g. UTF-8 strings) are an edge case Slice 4 covers.
        $out[] = new TokenSpec(
            line: $line,
            startChar: $startChar,
            length: $length,
            type: $type,
            modifiers: $modifiers,
        );
    }

    /**
     * Emit a spec from a STRIPPED-source byte span.  Translates the start
     * + end through {@see ByteOffsetMap} before delegating to
     * {@see emit}.
     *
     * @internal exposed for the anonymous AST visitor
     *
     * @param list<TokenSpec> $out
     */
    public function emitAstSpan(array &$out, int $strippedStart, int $length, string $type, array $modifiers = []): void
    {
        $origStart = $this->byteOffsetMap->toOriginal($strippedStart);
        $origEnd = $this->byteOffsetMap->toOriginal($strippedStart + $length);
        if ($origStart < 0 || $origEnd < $origStart) {
            return;
        }
        $this->emit($out, $origStart, $origEnd - $origStart, $type, $modifiers);
    }

    /**
     * @internal exposed for the anonymous AST visitor
     *
     * @param list<TokenSpec> $out
     */
    public function emitAstIdentifier(array &$out, Identifier $identifier, string $type): void
    {
        $start = $identifier->getStartFilePos();
        $end = $identifier->getEndFilePos();
        if ($start < 0 || $end < $start) {
            return;
        }
        $this->emitAstSpan($out, $start, $end - $start + 1, $type);
    }

    /**
     * @return array<int, string>
     */
    private static function buildTokenTypeMap(): array
    {
        $map = [];

        // Variables.
        $map[T_VARIABLE] = 'variable';

        // Numbers.
        $map[T_LNUMBER] = 'number';
        $map[T_DNUMBER] = 'number';

        // Strings.  Single-quoted strings + the surrounding double-quote
        // spans for un-interpolated string content.  Interpolation paths
        // (T_DOUBLE_QUOTES + T_ENCAPSED_AND_WHITESPACE + inner T_VARIABLE)
        // are decomposed by the tokenizer; the variable bits already get
        // picked up via T_VARIABLE, and the literal slabs become
        // T_ENCAPSED_AND_WHITESPACE which we also classify as string.
        $map[T_CONSTANT_ENCAPSED_STRING] = 'string';
        $map[T_ENCAPSED_AND_WHITESPACE] = 'string';

        // Comments.
        $map[T_COMMENT] = 'comment';
        $map[T_DOC_COMMENT] = 'comment';

        // Keywords.  Curated subset -- every PHP reserved word that
        // appears in normal code.  Magic constants (__CLASS__ etc.) and
        // less-common tokens (T_HALT_COMPILER, T_LIST) are not in the
        // map; they fall through to no-classification.
        $keywordTokens = [
            T_ABSTRACT,
            T_AS,
            T_BREAK,
            T_CALLABLE,
            T_CASE,
            T_CATCH,
            T_CLASS,
            T_CLONE,
            T_CONST,
            T_CONTINUE,
            T_DECLARE,
            T_DEFAULT,
            T_DO,
            T_ECHO,
            T_ELSE,
            T_ELSEIF,
            T_EMPTY,
            T_ENDDECLARE,
            T_ENDFOR,
            T_ENDFOREACH,
            T_ENDIF,
            T_ENDSWITCH,
            T_ENDWHILE,
            T_ENUM,
            T_EXIT,
            T_EXTENDS,
            T_FINAL,
            T_FINALLY,
            T_FN,
            T_FOR,
            T_FOREACH,
            T_FUNCTION,
            T_GLOBAL,
            T_GOTO,
            T_IF,
            T_IMPLEMENTS,
            T_INCLUDE,
            T_INCLUDE_ONCE,
            T_INSTANCEOF,
            T_INSTEADOF,
            T_INTERFACE,
            T_ISSET,
            T_MATCH,
            T_NAMESPACE,
            T_NEW,
            T_OPEN_TAG,
            T_OPEN_TAG_WITH_ECHO,
            T_PRINT,
            T_PRIVATE,
            T_PROTECTED,
            T_PUBLIC,
            T_READONLY,
            T_REQUIRE,
            T_REQUIRE_ONCE,
            T_RETURN,
            T_STATIC,
            T_SWITCH,
            T_THROW,
            T_TRAIT,
            T_TRY,
            T_UNSET,
            T_USE,
            T_VAR,
            T_WHILE,
            T_YIELD,
            T_YIELD_FROM,
        ];
        foreach ($keywordTokens as $id) {
            $map[$id] = 'keyword';
        }

        return $map;
    }
}
