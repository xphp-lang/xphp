<?php

declare(strict_types=1);

namespace XPHP\Lsp\Dispatcher;

use Phpactor\LanguageServer\Core\Dispatcher\ArgumentResolver;
use Phpactor\LanguageServer\Core\Dispatcher\Exception\CouldNotResolveArguments;
use Phpactor\LanguageServer\Core\Rpc\Message;
use Phpactor\LanguageServer\Core\Rpc\NotificationMessage;
use Phpactor\LanguageServer\Core\Rpc\RequestMessage;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Resolves LSP-protocol object payloads that aren't `*Params`
 * suffixed.
 *
 * Phpactor's built-in `LanguageSeverProtocolParamsResolver` only
 * matches handler parameter types whose class name ends in `Params`
 * (e.g. `CompletionParams`, `HoverParams`).  Several LSP methods
 * don't follow that convention:
 *
 *   completionItem/resolve  -> params is a raw `CompletionItem`
 *   codeAction/resolve      -> params is a raw `CodeAction`
 *   codeLens/resolve        -> params is a raw `CodeLens`
 *
 * Without this resolver the chain falls through to
 * `PassThroughArgumentResolver`, which hands the splatted
 * `array_values($params)` to the handler -- a list of scalar field
 * values, not a typed object.  PHP's positional arg-binding then
 * throws a `TypeError` ("string given, expected CompletionItem")
 * because `array_values()` strips the `label` / `kind` / `data`
 * keys.
 *
 * This resolver runs the same `fromArray(...)` static deserialiser
 * the framework already uses for `*Params` types, but matches
 * either `CompletionItem` or `CodeAction` -- the only two
 * non-Params LSP object types we currently accept.  Add to the
 * resolver chain BEFORE `LanguageSeverProtocolParamsResolver` and
 * `PassThroughArgumentResolver`.
 */
final class LspObjectArgumentResolver implements ArgumentResolver
{
    /**
     * Class FQNs handled by this resolver.  Each must implement a
     * static `fromArray(array $data, bool $allowUnknownKeys = false): self`
     * factory -- every Phpactor LSP-protocol class does.
     *
     * @var list<class-string>
     */
    private const SUPPORTED_TYPES = [
        \Phpactor\LanguageServerProtocol\CompletionItem::class,
        \Phpactor\LanguageServerProtocol\CodeAction::class,
        \Phpactor\LanguageServerProtocol\CodeLens::class,
    ];

    /**
     * @return list<mixed>
     */
    public function resolveArguments(object $object, string $method, Message $request): array
    {
        // `ChainArgumentResolver` advances to the next resolver only
        // when this one throws `CouldNotResolveArguments` -- returning
        // `[]` would short-circuit the chain.  Throw on every case we
        // can't handle so the chain falls through to
        // `LanguageSeverProtocolParamsResolver` /
        // `PassThroughArgumentResolver` as before.
        if (!$request instanceof RequestMessage && !$request instanceof NotificationMessage) {
            throw new CouldNotResolveArguments('Not a request/notification');
        }

        $reflection = new ReflectionMethod($object, $method);
        $parameters = $reflection->getParameters();
        if (count($parameters) < 1) {
            throw new CouldNotResolveArguments('Handler method has no parameters');
        }

        $type = $parameters[0]->getType();
        if (!$type instanceof ReflectionNamedType) {
            throw new CouldNotResolveArguments('First parameter has no concrete type');
        }
        $classFqn = $type->getName();
        if (!in_array($classFqn, self::SUPPORTED_TYPES, true)) {
            throw new CouldNotResolveArguments(sprintf(
                'Class "%s" not in LspObjectArgumentResolver supported types',
                $classFqn,
            ));
        }

        $params = $request->params ?? [];
        if (!is_array($params)) {
            throw new CouldNotResolveArguments('Request params is not an array');
        }

        $reflectionClass = new ReflectionClass($classFqn);
        $fromArray = $reflectionClass->getMethod('fromArray');

        return [$fromArray->invoke(null, $params, true)];
    }
}
