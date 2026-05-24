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
    ) {
    }

    public function resolve(string $uri, int $line, int $character): ?Hover
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

        $markdown = match ($symbol->symbolType()) {
            Symbol::CLASS_    => $this->renderClass(self::preferType($context, $symbol->name())),
            Symbol::FUNCTION  => $this->renderFunction($symbol->name()),
            Symbol::METHOD    => $this->renderMethod($context->containerType()->name()->__toString(), $symbol->name()),
            Symbol::PROPERTY  => $this->renderProperty($context->containerType()->name()->__toString(), $symbol->name()),
            Symbol::CONSTANT  => $this->renderConstant($context, $symbol->name()),
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
            $type = (string) $param->inferredType();
            $params[] = trim(($type !== '' && $type !== '<missing>' ? $type . ' ' : '') . '$' . $param->name());
        }
        $return = (string) $function->inferredType();
        $signature = sprintf(
            'function %s(%s)%s',
            (string) $function->name(),
            implode(', ', $params),
            $return !== '' && $return !== '<missing>' ? ': ' . $return : '',
        );
        $docblock = self::docblockText($function->docblock());
        return self::format($signature, $docblock);
    }

    private function renderMethod(string $classFqn, string $methodName): ?string
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
            $type = (string) $param->inferredType();
            $params[] = trim(($type !== '' && $type !== '<missing>' ? $type . ' ' : '') . '$' . $param->name());
        }
        $return = (string) $method->returnType();
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
        $type = (string) $property->inferredType();
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
        $container = $context->containerType()->name()->__toString();
        if ($container !== '') {
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

    private static function preferType(NodeContext $context, string $fallback): string
    {
        // `(string) $type` works for every Type subclass; calling
        // `name()` directly blows up on `MissingType` which doesn't
        // expose `name()`.
        $typeName = (string) $context->type();
        return $typeName !== '' && $typeName !== '<missing>' ? $typeName : $fallback;
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
