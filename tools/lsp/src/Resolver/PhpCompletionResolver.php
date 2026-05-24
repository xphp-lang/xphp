<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\CompletionItem;
use Phpactor\LanguageServerProtocol\CompletionItemKind;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocumentBuilder;
use Phpactor\WorseReflection\Core\Inference\Symbol;
use Phpactor\WorseReflection\Core\Reflection\ReflectionMember;
use Phpactor\WorseReflection\Core\Visibility;
use Phpactor\WorseReflection\Reflector;
use Throwable;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Hand-rolled member / static-member completion backed by worse-reflection.
 *
 * Triggers off `PhpCompletionContext::detect()` which classifies the cursor
 * into `member` (`$obj->|`) or `static` (`Cls::|`).  For each shape:
 *
 *  - Member: reflect the byte offset just BEFORE the `->` to get the
 *    receiver's inferred type via `NodeContext::type()`, then reflect
 *    that class and enumerate methods + properties.
 *  - Static: same approach -- reflectOffset at the byte before `::` so
 *    worse-reflection resolves `self`, `parent`, `static`, and bare
 *    class names against the surrounding use-import scope.
 *
 * Filter:
 *  - Public members always included (until we have access-control aware
 *    callsite tracking, which is hard with non-existent receiver info
 *    on the line under construction).
 *  - Magic `__*` methods excluded -- editors are noisy enough about them.
 *
 * Returns an empty list (not null) for contexts we don't handle so the
 * caller can compose this with other completion sources without
 * special-casing nulls.
 *
 * Why not use phpactor/completion-worse?  The package is abandoned on
 * packagist (entire phpactor/completion-* tree); pulling in an abandoned
 * dep was rejected for long-term maintenance reasons.  See plan risk #4.
 */
final class PhpCompletionResolver
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly XphpSourceParser $parser,
        private readonly Reflector $reflector,
    ) {
    }

    /**
     * @return list<CompletionItem>
     */
    public function complete(string $uri, int $line, int $character): array
    {
        // Top-level safety net for parity with PhpDefinitionResolver and
        // PhpHoverResolver -- any unexpected `Error` from worse-reflection
        // becomes "no completion" instead of an uncaught fatal that
        // poisons the LSP transport via stdout.
        try {
            return $this->completeInner($uri, $line, $character);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<CompletionItem>
     */
    private function completeInner(string $uri, int $line, int $character): array
    {
        if (!$this->workspace->has($uri)) {
            return [];
        }
        $document = $this->workspace->get($uri);
        $cursorOffset = (new PositionMap($document->text))->positionToOffset($line, $character);

        $hit = PhpCompletionContext::detect($document->text, $cursorOffset);
        if ($hit === null) {
            return [];
        }

        $stripped = $this->parser->strip($document->text);
        $source = TextDocumentBuilder::create($stripped)->uri($uri)->language('php')->build();

        // Worse-reflection wants an offset INSIDE the receiver expression
        // (one byte before the operator) so the resolver classifies the
        // expression rather than the trailing `->` / `::`.
        $receiverProbe = max(0, $hit['receiverEnd'] - 1);
        try {
            $offsetReflection = $this->reflector->reflectOffset($source, ByteOffset::fromInt($receiverProbe));
        } catch (Throwable) {
            return [];
        }

        $context = $offsetReflection->nodeContext();
        // `(string) $type` is safe for every Type subclass (MissingType,
        // PrimitiveType, ClassType, ...); calling `name()` directly blows
        // up on MissingType.  MissingType stringifies to `<missing>`.
        $typeName = (string) $context->type();
        if ($typeName === '' || $typeName === '<missing>') {
            return [];
        }

        try {
            $class = $this->reflector->reflectClassLike($typeName);
        } catch (Throwable) {
            return [];
        }

        $items = [];
        $isStatic = $hit['kind'] === 'static';

        foreach ($class->methods() as $method) {
            if (str_starts_with($method->name(), '__')) {
                continue;
            }
            if ($isStatic xor $method->isStatic()) {
                continue;
            }
            if (!self::isVisibleFromCaller($method->visibility())) {
                continue;
            }
            if (!self::matchesPrefix($method->name(), $hit['prefix'])) {
                continue;
            }
            $items[] = self::methodItem($method);
        }

        // Properties only show on instance-member access.  Static
        // properties via `Cls::$prop` use a `$` prefix that
        // `PhpCompletionContext` doesn't currently recognise as a
        // separate shape, so we skip statics here -- punt to a
        // follow-up.
        if (!$isStatic) {
            foreach ($class->properties() as $property) {
                if (!self::isVisibleFromCaller($property->visibility())) {
                    continue;
                }
                if ($property->isStatic()) {
                    continue;
                }
                if (!self::matchesPrefix($property->name(), $hit['prefix'])) {
                    continue;
                }
                $items[] = self::propertyItem($property);
            }
        } else {
            // Static constants surface on `Cls::|`.
            foreach ($class->constants() as $constant) {
                if (!self::matchesPrefix((string) $constant->name(), $hit['prefix'])) {
                    continue;
                }
                $items[] = new CompletionItem(
                    label: (string) $constant->name(),
                    kind: CompletionItemKind::CONSTANT,
                    detail: (string) $class->name(),
                );
            }
        }

        /** @var list<CompletionItem> $items */
        return $items;
    }

    private static function methodItem($method): CompletionItem
    {
        $params = [];
        foreach ($method->parameters() as $p) {
            $type = (string) $p->inferredType();
            $params[] = trim(($type !== '' && $type !== '<missing>' ? $type . ' ' : '') . '$' . $p->name());
        }
        $return = (string) $method->returnType();
        $signature = sprintf(
            '(%s)%s',
            implode(', ', $params),
            $return !== '' && $return !== '<missing>' ? ': ' . $return : '',
        );
        return new CompletionItem(
            label: $method->name(),
            kind: CompletionItemKind::METHOD,
            detail: $signature,
            insertText: $method->name(),
        );
    }

    private static function propertyItem($property): CompletionItem
    {
        $type = (string) $property->inferredType();
        return new CompletionItem(
            label: $property->name(),
            kind: CompletionItemKind::PROPERTY,
            detail: $type !== '' && $type !== '<missing>' ? $type : null,
            insertText: $property->name(),
        );
    }

    private static function isVisibleFromCaller(Visibility $visibility): bool
    {
        // MVP: public only.  Private/protected need to know the caller's
        // class scope, which worse-reflection's offset reflection gives
        // us via `scope()` -- threading that through here is a follow-up.
        return $visibility->isPublic();
    }

    private static function matchesPrefix(string $candidate, string $prefix): bool
    {
        if ($prefix === '') {
            return true;
        }
        return stripos($candidate, $prefix) === 0;
    }
}
