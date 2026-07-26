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
use PhpParser\Node\Name;
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
     * Find the first surviving generic marker in a specialized AST subtree, or null when
     * the subtree is clean. The scan is the guard's single source of truth — `assertNoLeak`
     * throws on it, and `check`-mode callers degrade it to a collected diagnostic so the
     * validate-only pass reports the same shapes the compile-time backstop rejects.
     *
     * `$includeClosureTemplates` toggles the defense-in-depth arm. The compile-time
     * assert keeps it on. The check-mode drain turns it off: an un-specialized closure
     * template inside a drained body always accompanies either a source-seam diagnostic
     * on its call site (a different line — the template node's own line would dodge the
     * caller's already-reported dedupe) or an orphan diagnostic from the
     * declared-but-never-specialized check, so re-flagging the template node itself only
     * double-reports; the call-site marker arm is what carries new information there.
     *
     * `$includeVariableTurbofish` toggles variable-turbofish FuncCalls (`$f::<int>`).
     * The check-mode CLASS-spec backstop turns it off: check's validate-only walk never
     * materializes closure dispatchers, so a class-spec clone legitimately carries the
     * variable marker that compile's dispatcher pass grounds — flagging it would reject
     * code compile accepts. Every genuinely-broken variable-turbofish shape is caught
     * elsewhere (the source seam in both modes, or the append-drain backstop, whose
     * check side keeps this arm on because compile's drain rejects the same body).
     *
     * @param Node|list<Node> $specialized  the emitted specialized node(s)
     */
    public static function findLeak(
        Node|array $specialized,
        bool $includeClosureTemplates = true,
        bool $includeVariableTurbofish = true,
    ): ?Node {
        $nodes = is_array($specialized) ? $specialized : [$specialized];

        return (new NodeFinder())->findFirst($nodes, static function (Node $n) use ($includeClosureTemplates, $includeVariableTurbofish): bool {
            if ($n instanceof FuncCall
                || $n instanceof MethodCall
                || $n instanceof StaticCall
                || $n instanceof NullsafeMethodCall
            ) {
                if (!$includeVariableTurbofish && $n instanceof FuncCall && !$n->name instanceof Name) {
                    return false;
                }
                return $n->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS) !== null;
            }
            if ($includeClosureTemplates && ($n instanceof Closure || $n instanceof ArrowFunction)) {
                return is_array($n->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS));
            }

            return false;
        });
    }

    /**
     * Build the guard's diagnostic message for a found leak. Shared verbatim between the
     * compile-time throw and the check-mode collected diagnostic so both modes name the
     * same site the same way.
     */
    public static function leakMessage(Node $leak, string $label): string
    {
        // @infection-ignore-all Concat ConcatOperandRemoval — the diagnostic wording is not
        // behavior: the tests pin that a leak throws and that the message names the label, the
        // line, and the code; reordering or dropping a prose clause changes none of those.
        return sprintf(
            'A generic turbofish/closure marker survived specialization into the emitted output for %s '
            . '(near line %d). This site could not be grounded to a concrete type, so its type-parameter '
            . 'hints would reach the emitted PHP as references to non-existent classes — a runtime TypeError. '
            . 'This shape (a turbofish grounded only by an enclosing function/class parameter) is a known '
            . 'unsupported position; call it with an explicit concrete turbofish, or move it out of the '
            . 'enclosing generic scope. [%s]',
            $label,
            $leak->getStartLine(),
            self::CODE,
        );
    }

    /**
     * Throw if any generic marker survives into a specialized AST subtree.
     *
     * @param Node|list<Node> $specialized  the emitted specialized node(s)
     * @param string          $label        the specialization's identity, for the error message
     */
    public static function assertNoLeak(Node|array $specialized, string $label): void
    {
        $leak = self::findLeak($specialized);

        if ($leak === null) {
            return;
        }

        throw new RuntimeException(self::leakMessage($leak, $label));
    }
}
