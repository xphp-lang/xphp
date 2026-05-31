<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\Promise;
use Amp\Success;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServerProtocol\CompletionItem;
use Phpactor\LanguageServerProtocol\MarkupContent;
use Phpactor\LanguageServerProtocol\MarkupKind;
use Phpactor\WorseReflection\Core\Exception\NotFound;
use Phpactor\WorseReflection\Core\Exception\SourceNotFound;
use Phpactor\WorseReflection\Reflector;
use Throwable;

/**
 * `completionItem/resolve` handler.
 *
 * Round-trips an item the client received from
 * `textDocument/completion` and enriches it with lazy details --
 * specifically, the class-level docblock pulled from worse-reflection.
 *
 * Lazy lookup keeps the initial completion response small: emitting
 * a 3,000-item list with eager docblocks would balloon the JSON
 * payload (each docblock can be 100s of bytes), where the client
 * only ever displays one docblock at a time (the focused item).
 * `data: {kind: 'class', fqn: '...'}` is enough to refetch from
 * worse-reflection on demand.
 *
 * Available since IntelliJ Platform 2024.2.  Returns the unchanged
 * item when the data payload is missing or doesn't identify a
 * resolvable class -- the client treats that as "no extra detail".
 */
final class XphpCompletionResolveHandler implements Handler
{
    public function __construct(
        private readonly ?Reflector $reflector = null,
    ) {
    }

    public function methods(): array
    {
        return [
            'completionItem/resolve' => 'resolve',
        ];
    }

    /**
     * @return Promise<CompletionItem>
     */
    public function resolve(CompletionItem $item): Promise
    {
        if ($this->reflector === null) {
            return new Success($item);
        }
        $data = $item->data;
        if (!is_array($data)) {
            return new Success($item);
        }
        $kind = $data['kind'] ?? null;
        $fqn = $data['fqn'] ?? null;
        if ($kind !== 'class' || !is_string($fqn) || $fqn === '') {
            return new Success($item);
        }

        try {
            $class = $this->reflector->reflectClassLike($fqn);
            $docblock = $class->docblock();
        } catch (NotFound | SourceNotFound | Throwable) {
            return new Success($item);
        }

        if (!$docblock->isDefined()) {
            return new Success($item);
        }
        $text = trim($docblock->formatted());
        if ($text === '') {
            return new Success($item);
        }

        $item->documentation = new MarkupContent(
            kind: MarkupKind::MARKDOWN,
            value: $text,
        );
        return new Success($item);
    }
}
