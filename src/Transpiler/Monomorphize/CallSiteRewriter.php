<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * Walks an AST and applies two transformations:
 *  1. Every Name node carrying `xphp:genericArgs` (with all args fully concrete) is replaced
 *     with the registry's generated FQCN. Works regardless of where the Name appears
 *     (new expressions, property types, parameter types, return types).
 *  2. Every generic class definition (Class_ with `xphp:genericParams`) is removed from the
 *     output — the specialized classes carry the actual implementation.
 */
final class CallSiteRewriter
{
    public function __construct(private readonly Registry $registry)
    {
    }

    /**
     * @param list<Node\Stmt> $ast
     * @return list<Node\Stmt>
     */
    public function rewrite(array $ast): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($this->registry) extends NodeVisitorAbstract {
            public function __construct(private Registry $registry)
            {
            }

            public function leaveNode(Node $node): Node|int|null
            {
                if ($node instanceof Name && !$node instanceof FullyQualified) {
                    $args = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
                    $fqn = $node->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
                    if (is_array($args) && $args !== [] && is_string($fqn) && self::allConcrete($args)) {
                        $instantiation = $this->registry->recordInstantiation($fqn, $args);
                        return new FullyQualified($instantiation->generatedFqn, $node->getAttributes());
                    }
                }

                if ($node instanceof Class_ && $node->name !== null) {
                    $params = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
                    if (is_array($params) && $params !== []) {
                        return NodeVisitor::REMOVE_NODE;
                    }
                }

                return null;
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
        });

        return $traverser->traverse($ast);
    }
}
