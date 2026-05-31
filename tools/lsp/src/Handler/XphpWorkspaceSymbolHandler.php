<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\CancellationToken;
use Amp\Promise;
use Amp\Success;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\SymbolInformation;
use Phpactor\LanguageServerProtocol\SymbolKind;
use Phpactor\LanguageServerProtocol\WorkspaceSymbolParams;
use XPHP\Lsp\Reflection\FqnIndex;

/**
 * `workspace/symbol` handler -- backs PhpStorm's "Go to Symbol" (Cmd+Alt+O
 * with Ctrl-N as the Symbol variant) and VS Code's `@:` symbol pane.
 *
 * Filters `FqnIndex::allDeclarations()` by case-insensitive substring on
 * the **short name** (last `\`-segment); the client (PhpStorm, VS Code)
 * runs its own fuzzy matcher on top of our results, so substring is
 * sufficient and saves a per-keystroke fuzzy pass on our end.  Empty
 * query returns everything (capped).
 *
 * Result cap: 250.  Most clients render <100 visible at a time; the cap
 * bounds the response payload and the time the client spends sorting.
 *
 * `containerName` carries the namespace so clients can render
 * `Repository` with the `App\Containers` qualifier; PhpStorm's symbol
 * popup shows this as a faint suffix.
 *
 * Capability is advertised as bool `true` (NOT `WorkspaceSymbolOptions`);
 * the empty-options-object encoding trips IntelliJ's strict array/object
 * check at parse time -- same pattern as hover, documentSymbol.
 */
final class XphpWorkspaceSymbolHandler implements Handler, CanRegisterCapabilities
{
    private const RESULT_CAP = 250;

    public function __construct(
        private readonly FqnIndex $fqnIndex,
    ) {
    }

    public function methods(): array
    {
        return [
            'workspace/symbol' => 'symbol',
        ];
    }

    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        $capabilities->workspaceSymbolProvider = true;
    }

    /**
     * @return Promise<list<SymbolInformation>>
     */
    public function symbol(WorkspaceSymbolParams $params, ?CancellationToken $cancel = null): Promise
    {
        if ($cancel !== null && $cancel->isRequested()) {
            return new Success([]);
        }
        $query = strtolower(self::stripMemberSuffix($params->query));
        $results = [];
        $iterations = 0;
        foreach ($this->fqnIndex->allDeclarations() as $hit) {
            // Workspace symbol scans the whole FQN index -- in big
            // workspaces this can be slow, so we poll the cancellation
            // token every 256 iterations to bail mid-scan when the
            // user has moved on.  256 is small enough to keep the
            // perceived response time low and large enough that the
            // per-iteration overhead of `isRequested()` is amortized.
            if (($iterations++ & 255) === 0 && $cancel !== null && $cancel->isRequested()) {
                return new Success([]);
            }
            if ($query !== '' && !self::matches($hit['fqn'], $query)) {
                continue;
            }
            $results[] = self::toSymbolInformation($hit);
            if (count($results) >= self::RESULT_CAP) {
                break;
            }
        }
        return new Success($results);
    }

    /**
     * PhpStorm's symbol popup sends `Class::method` (or `Class::`) when the
     * user types the class-qualified form -- our handler doesn't index
     * methods at workspace level, so we strip the `::...` suffix and treat
     * it as a class query.  The client surfaces the class hit; user can
     * navigate, then use file-local document-symbols / GTD for the member.
     */
    private static function stripMemberSuffix(string $query): string
    {
        $idx = strpos($query, '::');
        return $idx === false ? $query : substr($query, 0, $idx);
    }

    private static function matches(string $fqn, string $lcQuery): bool
    {
        $short = strrchr($fqn, '\\');
        $short = $short !== false ? substr($short, 1) : $fqn;
        return str_contains(strtolower($short), $lcQuery);
    }

    /**
     * @param array{fqn: string, kind: string, uri: string, line: int, char: int} $hit
     */
    private static function toSymbolInformation(array $hit): SymbolInformation
    {
        $shortAndContainer = self::splitFqn($hit['fqn']);
        $location = new Location(
            $hit['uri'],
            new Range(
                new Position($hit['line'], $hit['char']),
                new Position($hit['line'], $hit['char'] + strlen($shortAndContainer[0])),
            ),
        );
        return new SymbolInformation(
            name: $shortAndContainer[0],
            kind: self::kindToLsp($hit['kind']),
            location: $location,
            containerName: $shortAndContainer[1],
        );
    }

    /**
     * @return array{0: string, 1: ?string}  [shortName, containerName-or-null]
     */
    private static function splitFqn(string $fqn): array
    {
        $pos = strrpos($fqn, '\\');
        if ($pos === false) {
            return [$fqn, null];
        }
        return [substr($fqn, $pos + 1), substr($fqn, 0, $pos)];
    }

    /**
     * @return SymbolKind::*
     */
    private static function kindToLsp(string $kind): int
    {
        return match ($kind) {
            'interface' => SymbolKind::INTERFACE,
            'enum' => SymbolKind::ENUM,
            'function' => SymbolKind::FUNCTION,
            // LSP has no TRAIT kind -- CLASS_ renders with the class icon
            // in every client we care about.
            'trait', 'class' => SymbolKind::CLASS_,
            default => SymbolKind::CLASS_,
        };
    }
}
