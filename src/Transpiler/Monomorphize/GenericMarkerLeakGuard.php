<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\NodeFinder;
use RuntimeException;

/**
 * Last-resort backstop over fully specialized output: no generic marker may survive into
 * emitted PHP.
 *
 * Specialization consumes the parser's generic markers as it grounds each site — the call
 * rewriter nulls {@see XphpSourceParser::ATTR_METHOD_GENERIC_ARGS} on every turbofish it
 * successfully rewrites into a concrete dispatch, and a grounded closure template loses its
 * {@see XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS} when its dispatcher is built. A marker
 * still present on an emitted node therefore means a site the pipeline could NOT ground reached
 * output ungrounded: its type-parameter hints print as references to non-existent classes
 * (`App\T`), a guaranteed `TypeError` the moment the value is invoked, behind an otherwise clean
 * gate. A handful of enclosing-parameter-grounded turbofish shapes hit exactly this today.
 *
 * The remedy is a loud native failure at compile time instead of the silent runtime fatal. This
 * guard runs over the specialized artifacts only (specialized classes; GMC-appended specialized
 * functions/methods) — never over templates or top-level dispatchers, which legitimately still
 * carry markers before the emit-time rewrite replaces / grounds them.
 *
 * Two signals, both scanned:
 *  - **primary** — a call node ({@see FuncCall}/{@see MethodCall}/{@see StaticCall}/
 *    {@see NullsafeMethodCall}) carrying a non-null `ATTR_METHOD_GENERIC_ARGS`: a turbofish the
 *    rewriter never grounded. Corpus-clean on every working path.
 *  - **defense-in-depth** — a {@see Closure}/{@see ArrowFunction} carrying an
 *    `ATTR_METHOD_GENERIC_PARAMS` array: an un-grounded generic closure template. Sound only
 *    once the dispatcher-build path clears its own copy of the marker.
 */
final class GenericMarkerLeakGuard
{
    public const CODE = 'xphp.unspecialized_generic_leak';

    /**
     * Throw if any generic marker survives into a specialized AST subtree.
     *
     * @param Node|list<Node> $specialized  the emitted specialized node(s)
     * @param string          $label        the specialization's identity, for the error message
     */
    public static function assertNoLeak(Node|array $specialized, string $label): void
    {
        $nodes = is_array($specialized) ? $specialized : [$specialized];
        $finder = new NodeFinder();

        $leak = $finder->findFirst($nodes, static function (Node $n): bool {
            if ($n instanceof FuncCall
                || $n instanceof MethodCall
                || $n instanceof StaticCall
                || $n instanceof NullsafeMethodCall
            ) {
                return $n->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS) !== null;
            }
            if ($n instanceof Closure || $n instanceof ArrowFunction) {
                return is_array($n->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS));
            }

            return false;
        });

        if ($leak === null) {
            return;
        }

        // @infection-ignore-all Concat ConcatOperandRemoval — the diagnostic wording is not
        // behavior: the tests pin that a leak throws and that the message names the label, the
        // line, and the code; reordering or dropping a prose clause changes none of those.
        throw new RuntimeException(sprintf(
            'A generic turbofish/closure marker survived specialization into the emitted output for %s '
            . '(near line %d). This site could not be grounded to a concrete type, so its type-parameter '
            . 'hints would reach the emitted PHP as references to non-existent classes — a runtime TypeError. '
            . 'This shape (a turbofish grounded only by an enclosing function/class parameter) is a known '
            . 'unsupported position; call it with an explicit concrete turbofish, or move it out of the '
            . 'enclosing generic scope. [%s]',
            $label,
            $leak->getStartLine(),
            self::CODE,
        ));
    }
}
