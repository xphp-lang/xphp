<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Transpiler\Monomorphize\TypeParam;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Recognises type references like `App\Containers\T` as the generic placeholder
 * `T` declared on `App\Containers\Collection<T>`, so the LSP can render them
 * sensibly in hover tooltips and completion details.
 *
 * Background: xphp's compile pipeline strips `<T>` clauses to whitespace before
 * worse-reflection sees the source.  After strip, `function first(): ?T { ... }`
 * leaves `T` standing as a normal class reference; worse-reflection resolves it
 * against the surrounding namespace (`App\Containers`) -> `?App\Containers\T`.
 * For the compiler that's fine -- monomorphization replaces these references
 * at instantiation time -- but the LSP shows the unresolved form to the user.
 *
 * This registry collects, for every generic ClassLike declaration in the open
 * workspace, the set of (namespace, paramName) pairs.  When a type string's
 * leaf segment matches a known param and its namespace is the param's host
 * namespace (or any ancestor), we know it's a placeholder and not a real class.
 *
 * Scope: workspace open documents only.  Generic classes declared in files the
 * user hasn't opened won't be recognised; the trade-off avoids a filesystem
 * walk on every hover, and in practice the file declaring `<T>` is usually
 * open in the editor when the user is reading code that uses it.  Extending
 * to filesystem indexing is a follow-up.
 */
final class GenericParamRegistry
{
    /**
     * @var array<string, true>|null  cache of "Namespace|ParamName" keys; null until first build.
     */
    private ?array $pairs = null;

    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $cache,
    ) {
    }

    /**
     * Rewrite a type string so generic-placeholder references display
     * without their namespace prefix.  Idempotent and side-effect-free.
     *
     * `?App\Containers\T`           ->  `?T`
     * `App\Containers\T`            ->  `T`
     * `App\Models\User`             ->  `App\Models\User`  (User isn't a known placeholder)
     * `array<App\Containers\T, V>`  ->  `array<T, V>`      (when both are placeholders)
     *
     * Operates on each `\`-qualified name inside the input independently;
     * non-name characters (`?`, `<`, `>`, `,`, whitespace) act as
     * delimiters.  Match is case-sensitive (PHP class names are
     * case-insensitive in practice, but in xphp source the convention is
     * single-uppercase placeholders, and we never want to mangle a
     * non-placeholder by accident).
     */
    public function prettify(string $type): string
    {
        if ($type === '' || $type === '<missing>') {
            return $type;
        }
        $pairs = $this->pairs();
        if ($pairs === []) {
            return $type;
        }
        // Match any sequence of `\`-separated identifier chars.  Identifier
        // chars match PHP's namespace grammar: letters, digits, underscore.
        return preg_replace_callback(
            '/[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+/',
            function (array $m) use ($pairs): string {
                $name = $m[0];
                $sep = strrpos($name, '\\');
                if ($sep === false) {
                    return $name;
                }
                $namespace = substr($name, 0, $sep);
                $leaf = substr($name, $sep + 1);
                return isset($pairs[$namespace . '|' . $leaf]) ? $leaf : $name;
            },
            $type,
        ) ?? $type;
    }

    /**
     * @return array<string, true>  "Namespace|ParamName" -> true
     */
    private function pairs(): array
    {
        if ($this->pairs !== null) {
            return $this->pairs;
        }
        $pairs = [];
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            foreach (self::collectPairs($result->ast) as [$namespace, $paramName]) {
                $pairs[$namespace . '|' . $paramName] = true;
            }
        }
        $this->pairs = $pairs;
        return $pairs;
    }

    /**
     * @param list<Node\Stmt> $ast
     * @return list<array{0: string, 1: string}>
     */
    private static function collectPairs(array $ast): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<array{0: string, 1: string}> */
            public array $pairs = [];

            public function enterNode(Node $node): null
            {
                if (!$node instanceof ClassLike) {
                    return null;
                }
                $templateFqn = $node->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
                $params = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
                if (!is_string($templateFqn) || !is_array($params)) {
                    return null;
                }
                $sep = strrpos($templateFqn, '\\');
                $namespace = $sep === false ? '' : substr($templateFqn, 0, $sep);
                foreach ($params as $param) {
                    if ($param instanceof TypeParam) {
                        $this->pairs[] = [$namespace, $param->name];
                    }
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->pairs;
    }

    /**
     * Drop the cached pair set so the next call re-walks the workspace.
     * Useful when tests want to add a new generic class after construction.
     */
    public function invalidate(): void
    {
        $this->pairs = null;
    }
}
