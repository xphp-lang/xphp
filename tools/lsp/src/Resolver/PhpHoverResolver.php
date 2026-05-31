<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use Amp\CancellationToken;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Hover;
use Phpactor\LanguageServerProtocol\MarkupContent;
use Phpactor\LanguageServerProtocol\MarkupKind;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocumentBuilder;
use Phpactor\WorseReflection\Core\Exception\NotFound;
use Phpactor\WorseReflection\Core\Exception\SourceNotFound;
use Phpactor\WorseReflection\Core\Inference\NodeContext;
use Phpactor\WorseReflection\Core\Inference\Symbol;
use Phpactor\WorseReflection\Reflector;
use Throwable;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * PHP-semantic hover backed by worse-reflection.
 *
 * Renders signature + docblock for the symbol at the cursor.  Returns null
 * when the cursor isn't on something with a reflectable definition
 * (variables, keywords, unknown identifiers).
 *
 * Format:
 *  - Fenced PHP code block holding the signature.
 *  - Plain-text docblock summary underneath (if present).
 *
 * Markdown is the LSP-default hover format; PhpStorm and VS Code both
 * render it consistently.
 *
 * Keep this resolver narrow on purpose: phpactor's main "hover" extension
 * is a 1000+ line rendering system with sectioned output and rich
 * formatting.  Our MVP target is "you can read the signature and
 * docblock"; everything else is a follow-up.
 */
final class PhpHoverResolver
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly XphpSourceParser $parser,
        private readonly Reflector $reflector,
        private readonly GenericParamRegistry $genericParams,
        private readonly GenericResolver $genericResolver,
    ) {
    }

    /**
     * Render a class-shaped hover (signature + docblock) for an
     * already-resolved FQN.  Used by XphpHoverHandler when the cursor
     * sits inside a `<...>` type-arg clause: at that offset the
     * stripped source is whitespace and worse-reflection would
     * misattribute the cursor to the enclosing `new Cls(...)`
     * expression, so the handler resolves the type-arg via
     * ATTR_GENERIC_ARGS and asks us to render it directly.
     */
    public function renderClassHover(string $fqn): ?Hover
    {
        try {
            $markdown = $this->renderClass($fqn);
        } catch (Throwable) {
            return null;
        }
        return $markdown !== null
            ? new Hover(new MarkupContent(MarkupKind::MARKDOWN, $markdown))
            : null;
    }

    public function resolve(string $uri, int $line, int $character, ?CancellationToken $cancel = null): ?Hover
    {
        // Top-level safety net -- see the matching pattern in
        // PhpDefinitionResolver::resolve().  An unexpected `Error` from
        // worse-reflection (e.g. MissingType::name()) here would write
        // to stdout and kill the LSP transport.  Always return null
        // instead.
        try {
            return $this->resolveInner($uri, $line, $character, $cancel);
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveInner(string $uri, int $line, int $character, ?CancellationToken $cancel): ?Hover
    {
        if ($cancel !== null && $cancel->isRequested()) {
            return null;
        }
        if (!$this->workspace->has($uri)) {
            return null;
        }
        $document = $this->workspace->get($uri);
        $offset = (new PositionMap($document->text))->positionToOffset($line, $character);
        $stripped = $this->parser->strip($document->text);
        $source = TextDocumentBuilder::create($stripped)->uri($uri)->language('php')->build();

        if ($cancel !== null && $cancel->isRequested()) {
            return null;
        }

        try {
            $reflectionOffset = $this->reflector->reflectOffset($source, ByteOffset::fromInt($offset));
        } catch (Throwable) {
            return null;
        }

        if ($cancel !== null && $cancel->isRequested()) {
            // worse-reflection's reflectOffset is one of the heavier
            // ops in the chain; bail before render-* if the user
            // moved on.
            return null;
        }

        $context = $reflectionOffset->nodeContext();
        $symbol = $context->symbol();

        // Worse-reflection misclassifies the imported name inside
        // `use function App\foo;` as Symbol::CLASS_, so renderClass
        // throws SourceNotFound and we return null hover.  Override to
        // FUNCTION dispatch when the AST context confirms a `use
        // function` (or group-use) statement.  Mirrors the same fix in
        // PhpDefinitionResolver.
        $useFunctionFqn = $this->useFunctionFqnAtOffset($uri, $offset);
        if ($useFunctionFqn !== null) {
            $markdown = $this->renderFunction($useFunctionFqn);
            return $markdown !== null
                ? new Hover(new MarkupContent(MarkupKind::MARKDOWN, $markdown))
                : null;
        }

        // Cursor on a declaration's own name token (`function foo`,
        // `class Foo`, `public function method()`).  Worse-reflection
        // returns an unhelpful symbol classification for these (the
        // declaration isn't a "reference" -- it IS the symbol), so the
        // standard dispatch yields null.  Walk the AST to identify the
        // enclosing declaration and render its signature directly.
        $declHit = $this->declarationFqnAtOffset($uri, $offset);
        if ($declHit !== null) {
            $markdown = match ($declHit['kind']) {
                'function' => $this->renderFunction($declHit['fqn']),
                'class'    => $this->renderClass($declHit['fqn']),
                'method'   => $this->renderMethod($declHit['className'], $declHit['memberName']),
                'property' => $this->renderProperty($declHit['className'], $declHit['memberName']),
                default    => null,
            };
            if ($markdown !== null) {
                return new Hover(new MarkupContent(MarkupKind::MARKDOWN, $markdown));
            }
        }
        if ($cancel !== null && $cancel->isRequested()) {
            return null;
        }

        // METHOD / PROPERTY / CONSTANT dispatch go through `containerOrNull`
        // so a MissingType container (when worse-reflection can't infer
        // the receiver -- e.g. result of an xphp generic method call)
        // returns "no hover" instead of crashing on the absent `name()`.
        // Cycle K: for union/intersection receiver types each
        // constituent class gets its own rendered hover snippet,
        // joined with markdown separators so the popup shows every
        // possible target side-by-side.
        $methodSubstitution = $this->genericResolver->resolveMethodCallSubstitutionAt($uri, $offset)
            ?? $this->genericResolver->resolveStaticCallSubstitutionAt($uri, $offset);
        $propertyReceiver = $this->genericResolver->resolvePropertyReceiverClassAt($uri, $offset)
            ?? self::containerOrNull($context)
            ?? '';
        $markdown = match ($symbol->symbolType()) {
            Symbol::CLASS_    => $this->fanOutRender(
                                    self::preferType($context, $symbol->name()),
                                    fn (string $fqn): ?string => $this->renderClass($fqn),
                                ),
            Symbol::FUNCTION  => $this->renderFunction(
                                    $symbol->name(),
                                    $this->genericResolver->resolveFunctionCallSubstitutionAt($uri, $offset),
                                ),
            Symbol::METHOD    => ($c = self::containerOrNull($context)) !== null
                                    ? $this->fanOutRender(
                                        $c,
                                        fn (string $fqn): ?string => $this->renderMethod(
                                            $fqn,
                                            $symbol->name(),
                                            $methodSubstitution,
                                        ),
                                    )
                                    : null,
            Symbol::PROPERTY  => $this->fanOutRender(
                                    $propertyReceiver,
                                    fn (string $fqn): ?string => $this->renderProperty(
                                        $fqn,
                                        $symbol->name(),
                                    ),
                                ),
            Symbol::CONSTANT,
            Symbol::DECLARED_CONSTANT
                              => $this->renderConstant($context, $symbol->name()),
            Symbol::VARIABLE  => $this->renderVariable($uri, $offset, $context, $symbol->name()),
            default           => null,
        };

        return $markdown !== null
            ? new Hover(new MarkupContent(MarkupKind::MARKDOWN, $markdown))
            : null;
    }

    /**
     * Run `$singleRenderer` against every constituent class FQN of
     * the type string and join the results with markdown separators
     * so PhpStorm's hover popup shows every union/intersection arm
     * side-by-side.  Single-class types short-circuit to one
     * renderer call.  Returns null when no constituent produces a
     * rendering (e.g. all FQNs are unindexed).
     *
     * @param callable(string): ?string $singleRenderer
     */
    private function fanOutRender(string $typeName, callable $singleRenderer): ?string
    {
        $typeName = ltrim($typeName, '\\');
        // Fast path: ClassFqnPredicate-shaped FQN skips the splitter.
        if (ClassFqnPredicate::is($typeName)) {
            return $singleRenderer(ltrim($typeName, '?'));
        }
        $snippets = [];
        $seen = [];
        foreach (TypeUnionSplitter::split($typeName) as $intersectionArm) {
            foreach ($intersectionArm as $componentFqn) {
                if (isset($seen[$componentFqn])) {
                    continue;
                }
                $seen[$componentFqn] = true;
                $rendered = $singleRenderer($componentFqn);
                if ($rendered !== null && $rendered !== '') {
                    $snippets[] = $rendered;
                }
            }
        }
        if ($snippets === []) {
            return null;
        }
        // Single arm = no separator (looks like an ordinary hover).
        // Multi-arm: separate with `---` so PhpStorm renders a
        // horizontal rule between each constituent's signature.
        return implode("\n\n---\n\n", $snippets);
    }

    private function renderClass(string $fqn): ?string
    {
        // Cycle C: short-circuit union / intersection / scalar-literal
        // strings before they reach the locator.  `Symbol::CLASS_`
        // routes here for every cursor whose inferred type
        // worse-reflection treats as class-shaped, including the
        // pathological `(A&B)|C` shapes 2026-05-27 prod logs surfaced.
        if (!ClassFqnPredicate::is($fqn)) {
            return null;
        }
        try {
            $class = $this->reflector->reflectClassLike($fqn);
        } catch (NotFound | SourceNotFound) {
            return null;
        }
        $kind = $class->classLikeType();
        $signature = sprintf('%s %s', $kind, (string) $class->name());
        $docblock = self::docblockText($class->docblock());
        return self::format($signature, $docblock);
    }

    private function renderFunction(string $name, ?MethodCallSubstitution $substitution = null): ?string
    {
        try {
            $function = $this->reflector->reflectFunction($name);
        } catch (NotFound | SourceNotFound) {
            return null;
        }
        $params = [];
        foreach ($function->parameters() as $param) {
            // Phase 5 follow-up: when the call site is a generic
            // function (`identity<User>(...)`), GenericResolver provides
            // substituted parameter types via the same MethodCallSubstitution
            // shape the method path uses.  Prefer the substituted type;
            // fall back to prettify(inferredType) for params with no
            // substitution entry (unannotated / union / intersection).
            $substituted = $substitution?->paramTypes[$param->name()] ?? null;
            $type = $substituted ?? $this->genericParams->prettify((string) $param->inferredType());
            $params[] = trim(($type !== '' && $type !== '<missing>' ? $type . ' ' : '') . '$' . $param->name());
        }
        $return = $substitution?->returnType
            ?? $this->genericParams->prettify((string) $function->inferredType());
        $signature = sprintf(
            'function %s(%s)%s',
            (string) $function->name(),
            implode(', ', $params),
            $return !== '' && $return !== '<missing>' ? ': ' . $return : '',
        );
        $docblock = self::docblockText($function->docblock());
        return self::format($signature, $docblock);
    }

    private function renderMethod(string $classFqn, string $methodName, ?MethodCallSubstitution $substitution = null): ?string
    {
        // Cycle C: gate the inferred receiver class.  See renderClass.
        if (!ClassFqnPredicate::is($classFqn)) {
            return null;
        }
        try {
            $class = $this->reflector->reflectClassLike($classFqn);
            $method = $class->methods()->get($methodName);
        } catch (Throwable) {
            return null;
        }
        $visibility = (string) $method->visibility();
        $static = $method->isStatic() ? 'static ' : '';
        // When the cursor is on a method call whose receiver is a tracked
        // generic-instantiated variable (e.g. `$users->first()` where
        // `$users = new Collection<User>(...)`), GenericResolver has
        // already substituted the type-params -- for both the return type
        // and each parameter type.  Use the substituted form when
        // available; fall back to prettify (drops the namespace from
        // placeholder names) for params/return the substitution doesn't
        // cover (e.g. parameters with union types).
        $params = [];
        foreach ($method->parameters() as $param) {
            $paramName = $param->name();
            $type = $substitution !== null && isset($substitution->paramTypes[$paramName])
                ? $substitution->paramTypes[$paramName]
                : $this->genericParams->prettify((string) $param->inferredType());
            $params[] = trim(($type !== '' && $type !== '<missing>' ? $type . ' ' : '') . '$' . $paramName);
        }
        $return = ($substitution !== null && $substitution->returnType !== null)
            ? $substitution->returnType
            : $this->genericParams->prettify((string) $method->returnType());
        $signature = sprintf(
            '%s %sfunction %s(%s)%s',
            $visibility,
            $static,
            $method->name(),
            implode(', ', $params),
            $return !== '' && $return !== '<missing>' ? ': ' . $return : '',
        );
        $signature = sprintf('// %s%s%s', $classFqn, "\n", $signature);
        $docblock = self::docblockText($method->docblock());
        return self::format($signature, $docblock);
    }

    private function renderProperty(?string $classFqn, string $propertyName): ?string
    {
        if ($classFqn === null) {
            return null;
        }
        // Cycle C: gate the inferred receiver class.  See renderClass.
        if (!ClassFqnPredicate::is($classFqn)) {
            return null;
        }
        try {
            $class = $this->reflector->reflectClassLike($classFqn);
            $property = $class->properties()->get($propertyName);
        } catch (Throwable) {
            return null;
        }
        $visibility = (string) $property->visibility();
        $static = $property->isStatic() ? 'static ' : '';
        $type = $this->genericParams->prettify((string) $property->inferredType());
        $signature = sprintf(
            "// %s\n%s %s%s\$%s",
            $classFqn,
            $visibility,
            $static,
            $type !== '' && $type !== '<missing>' ? $type . ' ' : '',
            $property->name(),
        );
        $docblock = self::docblockText($property->docblock());
        return self::format($signature, $docblock);
    }

    private function renderConstant(NodeContext $context, string $name): ?string
    {
        $container = self::containerOrNull($context);
        if ($container !== null) {
            // Cycle C: gate before the locator; same union/intersection
            // hazard as the other renderers.
            if (!ClassFqnPredicate::is($container)) {
                return null;
            }
            try {
                $class = $this->reflector->reflectClassLike($container);
                $constant = $class->constants()->get($name);
            } catch (Throwable) {
                return null;
            }
            $signature = sprintf("// %s\nconst %s", $container, $constant->name());
            return self::format($signature, self::docblockText($constant->docblock()));
        }

        try {
            $constant = $this->reflector->reflectConstant($name);
        } catch (NotFound | SourceNotFound) {
            return null;
        }
        return self::format(sprintf('const %s', (string) $constant->name()), '');
    }

    /**
     * Render a variable hover as `Type $name`.  worse-reflection's
     * `NodeContext::type()` carries the inferred type from prior
     * assignments / params / closure-use captures in scope.  Returns
     * null when no useful type was inferred (MissingType, references
     * to never-declared vars) so the editor doesn't pop up an empty
     * tooltip.
     *
     * Literal types get collapsed via `generalize()` so a hover on
     * `$x = 1` shows `int $x` rather than `1 $x`.  Class types are
     * left unchanged (their `generalize()` returns the same FQN).
     */
    private function renderVariable(string $uri, int $offset, NodeContext $context, string $name): ?string
    {
        // Resolver-first: when we can monomorphize the variable's source
        // (a `$x = new Generic<...>(...)` followed by `$y = $x->method()`
        // in the same file, OR a `function f(Collection<User> $users)`
        // param at scope entry), the resolver returns the substituted
        // concrete type and we render that directly.  When it can't model
        // the shape, fall through to worse-reflection + prettify -- this
        // resolver is purely additive, never regresses the fallback.
        $resolved = $this->genericResolver->resolveVariable($uri, $name, $offset);
        if ($resolved !== null) {
            return self::format(sprintf('%s $%s', $resolved, $name), '');
        }

        $type = (string) $context->type()->generalize();
        if ($type === '' || $type === '<missing>') {
            return null;
        }
        // Strip namespace prefix from generic-placeholder references so the
        // user sees `?T $user` rather than `?App\Containers\T $user` when
        // hovering a variable assigned from a `Collection<T>::first(): ?T`
        // call.  See GenericParamRegistry for the recognition logic.
        $type = $this->genericParams->prettify($type);
        // No docblock for variables -- worse-reflection's NodeContext
        // doesn't carry one for locals.  Type + name is the useful bit.
        return self::format(sprintf('%s $%s', $type, $name), '');
    }

    private static function preferType(NodeContext $context, string $fallback): string
    {
        // `(string) $type` works for every Type subclass; calling
        // `name()` directly blows up on `MissingType` which doesn't
        // expose `name()`.
        $typeName = (string) $context->type();
        return $typeName !== '' && $typeName !== '<missing>' ? $typeName : $fallback;
    }

    /**
     * Return the resolved FQN of the symbol's containing class/interface
     * for METHOD/PROPERTY/CONSTANT access, or null if worse-reflection
     * couldn't infer it (MissingType).  Same rationale as the matching
     * helper on PhpDefinitionResolver.
     */
    private static function containerOrNull(NodeContext $context): ?string
    {
        $name = (string) $context->containerType();
        return ($name === '' || $name === '<missing>') ? null : $name;
    }

    private static function format(string $signature, string $docblockText): string
    {
        $out = "```php\n" . $signature . "\n```";
        if ($docblockText !== '') {
            $out .= "\n\n" . $docblockText;
        }
        return $out;
    }


    /**
     * Detect whether the cursor sits on the name token of a Function_,
     * ClassLike, ClassMethod, or PropertyProperty declaration.  Returns
     * a {kind, fqn, [className, memberName]} hit so the caller can pick
     * the right render path.  Used when worse-reflection's reflectOffset
     * returns nothing useful for a declaration cursor (the symbol IS the
     * declaration; there's nothing to "look up").
     *
     * @return array{kind: string, fqn?: string, className?: string, memberName?: string}|null
     */
    private function declarationFqnAtOffset(string $uri, int $byteOffset): ?array
    {
        if (!$this->workspace->has($uri)) {
            return null;
        }
        $item = $this->workspace->get($uri);
        try {
            $ast = $this->parser->parseTolerant($item->text);
        } catch (Throwable) {
            return null;
        }
        if ($ast === null) {
            return null;
        }
        $visitor = new class($byteOffset) extends NodeVisitorAbstract {
            public ?array $hit = null;
            private string $namespace = '';
            /** @var list<string> */
            private array $classStack = [];

            public function __construct(private readonly int $offset)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->namespace = $node->name?->toString() ?? '';
                    return null;
                }
                if ($node instanceof Node\Stmt\ClassLike && $node->name !== null) {
                    $short = $node->name->toString();
                    $fqn = $this->namespace !== '' ? $this->namespace . '\\' . $short : $short;
                    $this->classStack[] = $fqn;
                    if ($this->hits($node->name)) {
                        $this->hit = ['kind' => 'class', 'fqn' => $fqn];
                    }
                    return null;
                }
                if ($node instanceof Node\Stmt\Function_) {
                    if ($this->hits($node->name)) {
                        $short = $node->name->toString();
                        $fqn = $this->namespace !== '' ? $this->namespace . '\\' . $short : $short;
                        $this->hit = ['kind' => 'function', 'fqn' => $fqn];
                    }
                    return null;
                }
                if ($node instanceof Node\Stmt\ClassMethod && $this->classStack !== []) {
                    if ($this->hits($node->name)) {
                        $this->hit = [
                            'kind' => 'method',
                            'className' => end($this->classStack),
                            'memberName' => $node->name->toString(),
                        ];
                    }
                    return null;
                }
                if ($node instanceof Node\PropertyItem && $this->classStack !== []) {
                    if ($this->hits($node->name)) {
                        $this->hit = [
                            'kind' => 'property',
                            'className' => end($this->classStack),
                            'memberName' => $node->name->toString(),
                        ];
                    }
                }
                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\ClassLike && $node->name !== null) {
                    array_pop($this->classStack);
                }
                return null;
            }

            private function hits(Node $name): bool
            {
                $s = $name->getStartFilePos();
                $e = $name->getEndFilePos();
                return $s >= 0 && $this->offset >= $s && $this->offset <= $e;
            }
        };
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->hit;
    }

    /**
     * Mirror of `PhpDefinitionResolver::useFunctionFqnAtOffset`: detect a
     * `use function App\foo;` (or group-use variant) cursor and return the
     * imported FQN.  Reusing the cached AST isn't worth the cache
     * dependency here -- one extra tolerant parse per hover call is fast
     * enough.
     */
    private function useFunctionFqnAtOffset(string $uri, int $byteOffset): ?string
    {
        if (!$this->workspace->has($uri)) {
            return null;
        }
        $item = $this->workspace->get($uri);
        try {
            $ast = $this->parser->parseTolerant($item->text);
        } catch (Throwable) {
            return null;
        }
        if ($ast === null) {
            return null;
        }
        $visitor = new class($byteOffset) extends NodeVisitorAbstract {
            public ?string $fqn = null;
            private bool $inside = false;
            private string $groupPrefix = '';

            public function __construct(private readonly int $offset)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Use_ && $node->type === Node\Stmt\Use_::TYPE_FUNCTION) {
                    $this->inside = true;
                }
                if ($node instanceof Node\Stmt\GroupUse && $node->type === Node\Stmt\Use_::TYPE_FUNCTION) {
                    $this->inside = true;
                    $this->groupPrefix = $node->prefix->toString();
                }
                if (!$this->inside || $this->fqn !== null) {
                    return null;
                }
                if ($node instanceof Node\UseItem) {
                    $start = $node->name->getStartFilePos();
                    $end = $node->name->getEndFilePos();
                    if ($start >= 0 && $this->offset >= $start && $this->offset <= $end) {
                        $name = $node->name->toString();
                        $this->fqn = $this->groupPrefix !== ''
                            ? $this->groupPrefix . '\\' . $name
                            : $name;
                    }
                }
                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Use_ && $node->type === Node\Stmt\Use_::TYPE_FUNCTION) {
                    $this->inside = false;
                }
                if ($node instanceof Node\Stmt\GroupUse && $node->type === Node\Stmt\Use_::TYPE_FUNCTION) {
                    $this->inside = false;
                    $this->groupPrefix = '';
                }
                return null;
            }
        };
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->fqn ?? null;
    }

    private static function docblockText(\Phpactor\WorseReflection\Core\DocBlock\DocBlock $docblock): string
    {
        if (!$docblock->isDefined()) {
            return '';
        }
        // `formatted()` returns the docblock body with the `/**`, ` * `
        // gutter, and `*/` stripped -- close enough to "human prose" for an
        // LSP hover.  Markdown renderers in editors handle the result
        // sensibly without further normalization.
        return trim($docblock->formatted());
    }
}
