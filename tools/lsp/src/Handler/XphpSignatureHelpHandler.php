<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\CancellationToken;
use Amp\Promise;
use Amp\Success;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\ParameterInformation;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\SignatureHelp;
use Phpactor\LanguageServerProtocol\SignatureHelpOptions;
use Phpactor\LanguageServerProtocol\SignatureHelpParams;
use Phpactor\LanguageServerProtocol\SignatureInformation;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocumentBuilder;
use Phpactor\WorseReflection\Core\Exception\NotFound;
use Phpactor\WorseReflection\Reflector;
use Throwable;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * `textDocument/signatureHelp` handler.
 *
 * When the cursor is inside a call's argument list -- `foo(|)`,
 * `$obj->bar($x, |)`, `Cls::baz(|)`, `new Thing(|)` -- emit the
 * callee's signature so the editor can render the parameter
 * popup, highlighting the active argument index.
 *
 * Trigger chars: `(` (open paren, start of args list) and `,`
 * (argument separator, advances activeParameter).
 *
 * Supported call shapes:
 *   - `func(...)`               FuncCall
 *   - `$obj->method(...)`       MethodCall   (uses worse-reflection
 *                                              to infer receiver type)
 *   - `Cls::method(...)`        StaticCall
 *   - `new Cls(...)`            New_         (renders __construct)
 *
 * Skipped for now: variadic / spread args (`...$xs`) don't get
 * special label treatment; nullable / union types render as
 * whatever worse-reflection's Type::__toString() yields.
 */
final class XphpSignatureHelpHandler implements Handler, CanRegisterCapabilities
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $cache,
        private readonly XphpSourceParser $parser,
        private readonly Reflector $reflector,
    ) {
    }

    public function methods(): array
    {
        return [
            'textDocument/signatureHelp' => 'signatureHelp',
        ];
    }

    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        $capabilities->signatureHelpProvider = new SignatureHelpOptions(
            triggerCharacters: ['(', ','],
        );
    }

    /**
     * @return Promise<SignatureHelp|null>
     */
    public function signatureHelp(SignatureHelpParams $params, ?CancellationToken $cancel = null): Promise
    {
        if ($cancel !== null && $cancel->isRequested()) {
            return new Success(null);
        }
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
        $offset = $positionMap->positionToOffset(
            $params->position->line,
            $params->position->character,
        );
        // Note: AST positions are in STRIPPED-source coordinates
        // (post-XphpSourceParser::strip).  For pure-PHP fixtures or
        // call positions AFTER any `<T>` clauses in the same file,
        // original == stripped.  For an xphp source where the call
        // appears BEFORE a generic clause earlier in the file, this
        // offset would be off; we accept that as a known limitation
        // until a future commit adds an original-to-stripped helper
        // to ByteOffsetMap.

        $call = self::findEnclosingCall($result->ast, $offset);
        if ($call === null) {
            return new Success(null);
        }

        try {
            $info = $this->buildSignature($call, $uri, $item->text, $offset);
        } catch (Throwable) {
            return new Success(null);
        }
        if ($info === null) {
            return new Success(null);
        }
        [$signature, $activeParameter] = $info;

        return new Success(new SignatureHelp(
            signatures: [$signature],
            activeSignature: 0,
            activeParameter: $activeParameter,
        ));
    }

    /**
     * Walk the AST for a call expression (FuncCall, MethodCall,
     * StaticCall, New_) whose arg-list parens contain `$offset`.
     * Returns the innermost match (closest scope wins) so nested
     * calls resolve to the inner-most signature popup.
     *
     * @param list<Node\Stmt> $ast
     */
    private static function findEnclosingCall(array $ast, int $offset): FuncCall|MethodCall|StaticCall|New_|null
    {
        $visitor = new class($offset) extends NodeVisitorAbstract {
            public FuncCall|MethodCall|StaticCall|New_|null $hit = null;

            public function __construct(private readonly int $offset)
            {
            }

            public function enterNode(Node $node): null
            {
                if (
                    !$node instanceof FuncCall
                    && !$node instanceof MethodCall
                    && !$node instanceof StaticCall
                    && !$node instanceof New_
                ) {
                    return null;
                }
                // The whole call's getStart/End covers the receiver
                // + name + `(args)`.  We want the args list only;
                // approximate by: cursor is INSIDE the call's
                // start/end AND past the call's name end position.
                $start = $node->getStartFilePos();
                $end = $node->getEndFilePos();
                if ($start < 0 || $this->offset < $start || $this->offset > $end + 1) {
                    return null;
                }
                // Inner-most call wins: keep overwriting as we
                // descend.
                $this->hit = $node;
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->hit;
    }

    /**
     * @return array{0: SignatureInformation, 1: int}|null
     *   [signature, activeParameter]
     */
    private function buildSignature(
        FuncCall|MethodCall|StaticCall|New_ $call,
        string $uri,
        string $source,
        int $cursorByte,
    ): ?array {
        $name = $this->calleeName($call);
        if ($name === null) {
            return null;
        }

        $reflected = $this->reflectCallee($call, $uri, $source);
        if ($reflected === null) {
            return null;
        }
        [$displayName, $parameters] = $reflected;

        $paramLabels = [];
        $paramInfos = [];
        foreach ($parameters as $param) {
            $type = (string) $param->inferredType();
            $label = ($type !== '' && $type !== '<missing>' ? $type . ' ' : '')
                . '$' . $param->name();
            $paramLabels[] = $label;
            $paramInfos[] = new ParameterInformation(label: $label);
        }

        $signatureLabel = $displayName . '(' . implode(', ', $paramLabels) . ')';
        $signature = new SignatureInformation(
            label: $signatureLabel,
            parameters: $paramInfos,
        );

        return [$signature, $this->computeActiveParameter($call, $cursorByte)];
    }

    private function calleeName(FuncCall|MethodCall|StaticCall|New_ $call): ?string
    {
        if ($call instanceof FuncCall && $call->name instanceof Node\Name) {
            return $call->name->toString();
        }
        if ($call instanceof MethodCall && $call->name instanceof Node\Identifier) {
            return $call->name->toString();
        }
        if ($call instanceof StaticCall && $call->name instanceof Node\Identifier) {
            return $call->name->toString();
        }
        if ($call instanceof New_ && $call->class instanceof Node\Name) {
            return $call->class->toString();
        }
        return null;
    }

    /**
     * @return array{0: string, 1: iterable<\Phpactor\WorseReflection\Core\Reflection\ReflectionParameter>}|null
     */
    private function reflectCallee(
        FuncCall|MethodCall|StaticCall|New_ $call,
        string $uri,
        string $source,
    ): ?array {
        if ($call instanceof FuncCall && $call->name instanceof Node\Name) {
            $fqn = $call->name->toString();
            try {
                $function = $this->reflector->reflectFunction($fqn);
            } catch (NotFound | Throwable) {
                return null;
            }
            return [(string) $function->name(), iterator_to_array($function->parameters())];
        }
        if ($call instanceof StaticCall && $call->class instanceof Node\Name && $call->name instanceof Node\Identifier) {
            // Use worse-reflection's offset-based resolution on the
            // class-name position so namespace aliases and `use`
            // imports resolve to the right FQN.
            $classFqn = $this->resolveClassNameAt($call->class, $uri, $source);
            if ($classFqn === null) {
                return null;
            }
            $methodName = $call->name->toString();
            return $this->reflectMethod($classFqn, $methodName, $methodName);
        }
        if ($call instanceof New_ && $call->class instanceof Node\Name) {
            $classFqn = $this->resolveClassNameAt($call->class, $uri, $source);
            if ($classFqn === null) {
                return null;
            }
            return $this->reflectMethod($classFqn, '__construct', $classFqn);
        }
        if ($call instanceof MethodCall && $call->name instanceof Node\Identifier) {
            // Receiver type comes from worse-reflection's offset
            // inference at the method-name position.
            $stripped = $this->parser->strip($source);
            $sourceDoc = TextDocumentBuilder::create($stripped)
                ->uri($uri)
                ->language('php')
                ->build();
            $nameStart = $call->name->getStartFilePos();
            if ($nameStart < 0) {
                return null;
            }
            try {
                $offsetCtx = $this->reflector->reflectOffset($sourceDoc, ByteOffset::fromInt($nameStart));
            } catch (Throwable) {
                return null;
            }
            $containerType = (string) $offsetCtx->nodeContext()->containerType();
            if ($containerType === '' || $containerType === '<missing>') {
                return null;
            }
            $methodName = $call->name->toString();
            return $this->reflectMethod($containerType, $methodName, $methodName);
        }
        return null;
    }

    /**
     * Use worse-reflection's offset-based name resolution to turn a
     * source `Name` node (which may be unqualified, aliased, or
     * relative) into its fully-qualified class FQN.  Mirrors the
     * preferType() path in PhpDefinitionResolver / PhpHoverResolver.
     */
    private function resolveClassNameAt(Node\Name $name, string $uri, string $source): ?string
    {
        $nameStart = $name->getStartFilePos();
        if ($nameStart < 0) {
            return null;
        }
        $stripped = $this->parser->strip($source);
        $sourceDoc = TextDocumentBuilder::create($stripped)
            ->uri($uri)
            ->language('php')
            ->build();
        try {
            $offsetCtx = $this->reflector->reflectOffset($sourceDoc, ByteOffset::fromInt($nameStart));
        } catch (Throwable) {
            return null;
        }
        $resolved = (string) $offsetCtx->nodeContext()->type();
        if ($resolved !== '' && $resolved !== '<missing>') {
            return $resolved;
        }
        // Fall back to the literal Name as written.
        return $name->toString();
    }

    /**
     * @return array{0: string, 1: iterable<\Phpactor\WorseReflection\Core\Reflection\ReflectionParameter>}|null
     */
    private function reflectMethod(string $classFqn, string $methodName, string $displayName): ?array
    {
        // Cycle C: gate the receiver's inferred class FQN before
        // `reflectClassLike`.  Static-call signature help on a
        // variable receiver (`$x::foo(` where `$x` has a union type)
        // would otherwise blow up in the locator chain.
        if (!\XPHP\Lsp\Resolver\ClassFqnPredicate::is($classFqn)) {
            return null;
        }
        try {
            $class = $this->reflector->reflectClassLike($classFqn);
            $method = $class->methods()->get($methodName);
        } catch (Throwable) {
            return null;
        }
        return [$displayName, iterator_to_array($method->parameters())];
    }

    /**
     * Count top-level commas in the call's arg list up to the cursor
     * offset.  Returns the 0-based index of the active argument.
     */
    private function computeActiveParameter(
        FuncCall|MethodCall|StaticCall|New_ $call,
        int $cursorByte,
    ): int {
        $index = 0;
        foreach ($call->args as $arg) {
            if (!$arg instanceof Arg) {
                continue;
            }
            $argEnd = $arg->getEndFilePos();
            if ($argEnd < 0) {
                continue;
            }
            if ($cursorByte <= $argEnd + 1) {
                // Cursor sits in or before this arg slot.
                return $index;
            }
            $index++;
        }
        // Cursor is past all args -- highlight the slot AFTER the
        // last arg (e.g. trailing comma case).
        return $index;
    }
}
