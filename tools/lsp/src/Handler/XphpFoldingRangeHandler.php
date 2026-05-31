<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\Promise;
use Amp\Success;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\FoldingRange;
use Phpactor\LanguageServerProtocol\FoldingRangeKind;
use Phpactor\LanguageServerProtocol\FoldingRangeParams;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\ByteOffsetMap;

/**
 * `textDocument/foldingRange` handler.
 *
 * Emits one folding region per top-level declaration body that spans
 * more than one line:
 *
 *   class / interface / trait / enum  (entire body, from `{` line
 *                                       through closing `}` line)
 *   function / method                  (body block)
 *
 * Single-line declarations are skipped -- LSP says a folding range
 * must have endLine > startLine to be valid.
 *
 * Server capability is advertised as bool `true` for the same reason
 * the other handlers do: phpactor's JSON serializer null-strips empty
 * options objects to `[]`, which IntelliJ's LSP4J rejects.
 *
 * Available since IntelliJ Platform 2025.2.2; rendered as code-folding
 * regions in the editor gutter.
 */
final class XphpFoldingRangeHandler implements Handler, CanRegisterCapabilities
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $cache,
    ) {
    }

    public function methods(): array
    {
        return [
            'textDocument/foldingRange' => 'foldingRange',
        ];
    }

    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        $capabilities->foldingRangeProvider = true;
    }

    /**
     * @return Promise<list<FoldingRange>|null>
     */
    public function foldingRange(FoldingRangeParams $params): Promise
    {
        $uri = $params->textDocument->uri;
        if (!$this->workspace->has($uri)) {
            return new Success(null);
        }
        $item = $this->workspace->get($uri);
        $result = $this->cache->getOrParse($uri, $item->version, $item->text);
        if ($result->ast === null) {
            return new Success([]);
        }
        $map = new PositionMap($item->text);
        $offsets = $result->byteOffsetMap;
        $ranges = [];
        foreach ($result->ast as $stmt) {
            self::collect($stmt, $map, $offsets, $ranges);
        }
        return new Success($ranges);
    }

    /**
     * @param list<FoldingRange> $out
     */
    private static function collect(Node $node, PositionMap $map, ByteOffsetMap $offsets, array &$out): void
    {
        if ($node instanceof Namespace_) {
            // Namespaces themselves aren't folded (PhpStorm doesn't fold
            // PHP namespaces by default and the convention is "fold what
            // a reader would visually collapse" -- which is bodies, not
            // file-level wrappers).  Recurse into the children.
            foreach ($node->stmts as $child) {
                self::collect($child, $map, $offsets, $out);
            }
            return;
        }

        if ($node instanceof ClassLike) {
            self::addRange($node, $map, $offsets, $out);
            // Recurse: each method gets its own fold so the user can
            // collapse method bodies while keeping the class outline.
            foreach ($node->stmts as $member) {
                if ($member instanceof ClassMethod) {
                    self::addRange($member, $map, $offsets, $out);
                }
            }
            return;
        }

        if ($node instanceof Function_) {
            self::addRange($node, $map, $offsets, $out);
        }
    }

    /**
     * @param list<FoldingRange> $out
     */
    private static function addRange(Node $node, PositionMap $map, ByteOffsetMap $offsets, array &$out): void
    {
        $start = $offsets->toOriginal($node->getStartFilePos());
        $end = $offsets->toOriginal($node->getEndFilePos() + 1);
        if ($start < 0 || $end <= $start) {
            return;
        }
        [$startLine] = $map->offsetToPosition($start);
        [$endLine] = $map->offsetToPosition($end);
        if ($endLine <= $startLine) {
            // LSP requires endLine > startLine for the range to be
            // valid; single-line declarations have nothing to fold.
            return;
        }
        $out[] = new FoldingRange(
            startLine: $startLine,
            endLine: $endLine,
            kind: FoldingRangeKind::REGION,
        );
    }
}
