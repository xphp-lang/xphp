<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * Walks an AST and applies two transformations:
 *  1. Every Name node carrying `xphp:genericArgs` (with all args fully concrete) is replaced
 *     with the registry's generated FQCN. Works regardless of where the Name appears
 *     (new expressions, property types, parameter types, return types).
 *  2. Every generic class / interface template is REPLACED with an empty marker interface
 *     at the same FQN — so `$x instanceof App\Containers\Box` returns true for any
 *     `Box<...>` specialization (which `implements`/`extends` the marker). Generic traits
 *     are removed entirely; PHP can't `instanceof` a trait.
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

                if ($node instanceof ClassLike && $node->name !== null) {
                    $params = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
                    if (is_array($params) && $params !== []) {
                        // Generic class/interface -> empty marker interface at the same name
                        // (so user code's `$x instanceof OriginalName` keeps working).
                        // Generic trait -> just drop (no instanceof against traits).
                        if ($node instanceof Class_ || $node instanceof Interface_) {
                            return new Interface_($node->name, ['stmts' => []], $node->getAttributes());
                        }
                        if ($node instanceof Trait_) {
                            return NodeVisitor::REMOVE_NODE;
                        }
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
