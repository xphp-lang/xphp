<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Walks an AST and feeds generic definitions and instantiations into a Registry.
 *
 * Definitions come from `ClassLike` nodes (Class_/Interface_/Trait_) carrying `xphp:genericParams` + `xphp:templateFqn`.
 * Instantiations come from `Name` nodes carrying `xphp:genericArgs` + `xphp:templateFqn`,
 * regardless of the surrounding expression (works for `new`, type hints, return types, etc).
 */
final class RegistryCollector extends NodeVisitorAbstract
{
    private string $currentFile = '';

    public function __construct(private readonly Registry $registry)
    {
    }

    /**
     * @param list<Node\Stmt> $ast
     */
    public function collect(array $ast, string $sourceFile): void
    {
        $this->currentFile = $sourceFile;

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this);
        $traverser->traverse($ast);
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof ClassLike && $node->name !== null) {
            $params = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
            $fqn = $node->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
            if (is_array($params) && $params !== [] && is_string($fqn) && !$this->isAlreadyRecorded($fqn)) {
                $this->registry->recordDefinition(
                    $fqn,
                    $node->name->toString(),
                    $params,
                    $node,
                    $this->currentFile,
                );
            }
        }

        if ($node instanceof Name) {
            $args = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
            $fqn = $node->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
            if (is_array($args) && $args !== [] && is_string($fqn) && self::allConcrete($args)) {
                $this->registry->recordInstantiation($fqn, $args);
            }
        }

        return null;
    }

    private function isAlreadyRecorded(string $fqn): bool
    {
        return $this->registry->definition($fqn) !== null;
    }

    /**
     * @param list<TypeRef> $args
     */
    private static function allConcrete(array $args): bool
    {
        foreach ($args as $a) {
            if (!$a->isConcrete()) {
                return false;
            }
        }
        return true;
    }
}
