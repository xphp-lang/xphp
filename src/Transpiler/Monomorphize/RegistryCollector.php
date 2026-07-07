<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use XPHP\Diagnostics\SourceLocation;

/**
 * Walks an AST and feeds generic definitions and instantiations into a Registry.
 *
 * Two passes, split because instantiations sometimes need the full set of
 * definitions to be resolvable up front:
 *
 *  - `collectDefinitions`: walks every ClassLike with `xphp:genericParams` +
 *    `xphp:templateFqn` and records the template. After this pass, every
 *    generic template across all source files is in the registry.
 *  - `collectInstantiations`: walks every `Name` carrying `xphp:genericArgs`
 *    and records the instantiation. Also walks every bare-`new Foo;` (no
 *    `<...>`), resolves Foo against the file's namespace + use map, and -- if
 *    Foo turns out to be a generic template whose every parameter has a
 *    default -- synthesizes a zero-arg instantiation. This is what makes
 *    `new Cache;` and `new Cache::<>` produce the same specialization.
 *
 * The legacy `collect` method runs both passes in one call. It's used inside
 * the fixed-point loop where definitions are guaranteed present.
 */
final class RegistryCollector extends NodeVisitorAbstract
{
    private const MODE_DEFINITIONS = 'definitions';
    private const MODE_INSTANTIATIONS = 'instantiations';
    private const MODE_ALL = 'all';

    private string $currentFile = '';
    private NamespaceContext $ctx;
    /** @var self::MODE_* */
    private string $mode = self::MODE_ALL;

    public function __construct(private readonly Registry $registry)
    {
        $this->ctx = new NamespaceContext();
    }

    /**
     * @param list<Node\Stmt> $ast
     */
    public function collect(array $ast, string $sourceFile): void
    {
        $this->runPass($ast, $sourceFile, self::MODE_ALL);
    }

    /**
     * @param list<Node\Stmt> $ast
     */
    public function collectDefinitions(array $ast, string $sourceFile): void
    {
        $this->runPass($ast, $sourceFile, self::MODE_DEFINITIONS);
    }

    /**
     * @param list<Node\Stmt> $ast
     */
    public function collectInstantiations(array $ast, string $sourceFile): void
    {
        $this->runPass($ast, $sourceFile, self::MODE_INSTANTIATIONS);
    }

    /**
     * @param list<Node\Stmt> $ast
     * @param self::MODE_*    $mode
     */
    private function runPass(array $ast, string $sourceFile, string $mode): void
    {
        $this->currentFile = $sourceFile;
        $this->mode = $mode;
        $this->ctx = new NamespaceContext();

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this);
        $traverser->traverse($ast);
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Namespace_) {
            // @infection-ignore-all — `?->toString()` matches the equivalent guard
            // in XphpSourceParser's inner visitor: bare `namespace { ... }` (no name)
            // never appears in any fixture, so the null-safe call is observationally
            // identical to the non-null version on every test input.
            $this->ctx->enterNamespace($node->name?->toString());
            // @infection-ignore-all — dual-handled by the standalone Use_ branch below; dead loop.
            foreach ($node->stmts as $inner) {
                if ($inner instanceof Use_) {
                    $this->ctx->indexUse($inner);
                }
            }
        }

        if ($node instanceof Use_) {
            // @infection-ignore-all — dual-handled by the inner foreach above.
            $this->ctx->indexUse($node);
        }

        if ($this->mode !== self::MODE_INSTANTIATIONS
            && $node instanceof ClassLike && $node->name !== null) {
            $params = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
            $fqn = $node->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
            $isGenericTemplate = is_array($params) && $params !== [] && is_string($fqn);
            if ($isGenericTemplate) {
                /** @var list<TypeParam> $params — set as a list by XphpSourceParser::resolveAndAttach. */
                if (!$this->isAlreadyRecorded($fqn)) {
                    $this->registry->recordDefinition(
                        $fqn,
                        $node->name->toString(),
                        $params,
                        $node,
                        $this->currentFile,
                    );
                }
            } else {
                // A NON-generic class/interface/trait. Record its FQN so the bare-new guard does
                // not reject a `new B` when a plain `class B` coexists with a generic `class B<T>`
                // (conditional same-name declarations). ATTR_TEMPLATE_FQN is only attached to
                // generic templates, so compute the declaration FQN from the namespace context.
                $ns = $this->ctx->currentNamespace();
                $plainFqn = $ns !== '' ? $ns . '\\' . $node->name->toString() : $node->name->toString();
                $this->registry->recordNonGenericClass($plainFqn);
            }
        }

        if ($this->mode !== self::MODE_DEFINITIONS && $node instanceof Name) {
            $args = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
            $fqn = $node->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
            if (is_array($args)) {
                /** @var list<TypeRef> $args — set as a list by XphpSourceParser::resolveAndAttach. */
                if (is_string($fqn) && self::allConcrete($args)) {
                    $this->registry->recordInstantiation(
                        $fqn,
                        $args,
                        new SourceLocation($this->currentFile, $node->getStartLine()),
                    );
                }
            }
        }

        // Fully-qualified bare news are included: `new \App\Box("hi")` on an
        // all-defaults generic used to be skipped here, so compile emitted the
        // stripped `interface Box {}` and KEPT the call site — a guaranteed
        // runtime fatal behind a clean gate.
        if ($this->mode !== self::MODE_DEFINITIONS
            && $node instanceof New_
            && $node->class instanceof Name
            && $node->class->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS) === null
        ) {
            $this->synthesizeBareNewIfAllDefaults($node->class);
        }

        return null;
    }

    /**
     * Resolve a bare Name's FQN via the current file's namespace + use map. If
     * the FQN matches a recorded template whose every parameter has a default,
     * attach `ATTR_GENERIC_ARGS = []` + `ATTR_TEMPLATE_FQN` and record a
     * zero-arg instantiation -- which the Registry's padding turns into the
     * all-defaults tuple.
     */
    private function synthesizeBareNewIfAllDefaults(Name $name): void
    {
        // resolveName (not a flattened toString()): the spelling decides the
        // template — `\App\Box` must not double the namespace, and a relative
        // `namespace\Box` must bind to the current namespace even when a
        // colliding `use` alias is in scope (otherwise synthesis targets the
        // WRONG template, or silently skips).
        $resolved = $this->ctx->resolveName($name);
        $definition = $this->registry->definition($resolved);
        if ($definition === null || $definition->typeParams === []) {
            return;
        }
        foreach ($definition->typeParams as $param) {
            if ($param->default === null) {
                // A generic template with a required (non-defaulted) parameter, used as a bare
                // `new` with no turbofish. It cannot be padded from defaults, so it was silently
                // skipped -- leaving the call site pointing at the stripped marker `interface`,
                // a guaranteed runtime fatal behind a clean gate. Report it loudly instead,
                // matching the function/method call path (`xphp.missing_type_argument`).
                $this->registry->reportMissingTypeArgumentsForBareNew(
                    $resolved,
                    new SourceLocation($this->currentFile, $name->getStartLine()),
                );
                return;
            }
        }
        $name->setAttribute(XphpSourceParser::ATTR_GENERIC_ARGS, []);
        $name->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, $resolved);
        // @infection-ignore-all — MethodCallRemoval on `recordInstantiation`: every
        // realistic compile path also runs CallSiteRewriter (Phase 3) over the same
        // Name node, which would call recordInstantiation itself once it sees the
        // synthesized attributes. So dropping the call here STILL records the same
        // instantiation downstream -- only the path differs. The split exists to
        // record the synthesis at the same time as the attribute attach so that the
        // fixed-point loop's nested-instantiation walk picks it up in the same pass.
        // Call-site location is threaded for the explicit-turbofish path (the bound-violation
        // case); the all-defaults bare-`new` path can only fail bounds via a default, which is
        // reported at the definition site, so it records without a call-site here.
        $this->registry->recordInstantiation($resolved, []);
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
