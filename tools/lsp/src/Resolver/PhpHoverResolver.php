<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

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

    public function resolve(string $uri, int $line, int $character): ?Hover
    {
        // Top-level safety net -- see the matching pattern in
        // PhpDefinitionResolver::resolve().  An unexpected `Error` from
        // worse-reflection (e.g. MissingType::name()) here would write
        // to stdout and kill the LSP transport.  Always return null
        // instead.
        try {
            return $this->resolveInner($uri, $line, $character);
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveInner(string $uri, int $line, int $character): ?Hover
    {
        if (!$this->workspace->has($uri)) {
            return null;
        }
        $document = $this->workspace->get($uri);
        $offset = (new PositionMap($document->text))->positionToOffset($line, $character);
        $stripped = $this->parser->strip($document->text);
        $source = TextDocumentBuilder::create($stripped)->uri($uri)->language('php')->build();

        try {
            $reflectionOffset = $this->reflector->reflectOffset($source, ByteOffset::fromInt($offset));
        } catch (Throwable) {
            return null;
        }

        $context = $reflectionOffset->nodeContext();
        $symbol = $context->symbol();

        // METHOD / PROPERTY / CONSTANT dispatch go through `containerOrNull`
        // so a MissingType container (when worse-reflection can't infer
        // the receiver -- e.g. result of an xphp generic method call)
        // returns "no hover" instead of crashing on the absent `name()`.
        $markdown = match ($symbol->symbolType()) {
            Symbol::CLASS_    => $this->renderClass(self::preferType($context, $symbol->name())),
            Symbol::FUNCTION  => $this->renderFunction($symbol->name()),
            Symbol::METHOD    => ($c = self::containerOrNull($context)) !== null
                                    ? $this->renderMethod($c, $symbol->name(), $this->genericResolver->resolveMethodReturnTypeAt($uri, $offset))
                                    : null,
            Symbol::PROPERTY  => ($c = self::containerOrNull($context)) !== null
                                    ? $this->renderProperty($c, $symbol->name())
                                    : null,
            Symbol::CONSTANT  => $this->renderConstant($context, $symbol->name()),
            Symbol::VARIABLE  => $this->renderVariable($uri, $context, $symbol->name()),
            default           => null,
        };

        return $markdown !== null
            ? new Hover(new MarkupContent(MarkupKind::MARKDOWN, $markdown))
            : null;
    }

    private function renderClass(string $fqn): ?string
    {
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

    private function renderFunction(string $name): ?string
    {
        try {
            $function = $this->reflector->reflectFunction($name);
        } catch (NotFound | SourceNotFound) {
            return null;
        }
        $params = [];
        foreach ($function->parameters() as $param) {
            $type = $this->genericParams->prettify((string) $param->inferredType());
            $params[] = trim(($type !== '' && $type !== '<missing>' ? $type . ' ' : '') . '$' . $param->name());
        }
        $return = $this->genericParams->prettify((string) $function->inferredType());
        $signature = sprintf(
            'function %s(%s)%s',
            (string) $function->name(),
            implode(', ', $params),
            $return !== '' && $return !== '<missing>' ? ': ' . $return : '',
        );
        $docblock = self::docblockText($function->docblock());
        return self::format($signature, $docblock);
    }

    private function renderMethod(string $classFqn, string $methodName, ?string $substitutedReturnType = null): ?string
    {
        try {
            $class = $this->reflector->reflectClassLike($classFqn);
            $method = $class->methods()->get($methodName);
        } catch (Throwable) {
            return null;
        }
        $visibility = (string) $method->visibility();
        $static = $method->isStatic() ? 'static ' : '';
        $params = [];
        foreach ($method->parameters() as $param) {
            $type = $this->genericParams->prettify((string) $param->inferredType());
            $params[] = trim(($type !== '' && $type !== '<missing>' ? $type . ' ' : '') . '$' . $param->name());
        }
        // When the cursor is on a method call whose receiver is a tracked
        // generic-instantiated variable (e.g. `$users->first()` where
        // `$users = new Collection<User>(...)`), GenericResolver has already
        // substituted the type-params in the return type for us; use that
        // instead of worse-reflection's unsubstituted view.  Otherwise
        // fall back to prettify (drops the namespace from placeholder names).
        $return = $substitutedReturnType
            ?? $this->genericParams->prettify((string) $method->returnType());
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

    private function renderProperty(string $classFqn, string $propertyName): ?string
    {
        try {
            $class = $this->reflector->reflectClassLike($classFqn);
            $property = $class->properties()->get($propertyName);
        } catch (Throwable) {
            return null;
        }
        $visibility = (string) $property->visibility();
        $type = $this->genericParams->prettify((string) $property->inferredType());
        $signature = sprintf(
            "// %s\n%s %s\$%s",
            $classFqn,
            $visibility,
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
    private function renderVariable(string $uri, NodeContext $context, string $name): ?string
    {
        // Resolver-first: when we can monomorphize the variable's source
        // (a `$x = new Generic<...>(...)` followed by `$y = $x->method()`
        // in the same file), the resolver returns the substituted concrete
        // type and we render that directly.  When it can't model the
        // shape, fall through to worse-reflection + prettify -- this
        // resolver is purely additive, never regresses the fallback.
        $resolved = $this->genericResolver->resolveVariable($uri, $name);
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
