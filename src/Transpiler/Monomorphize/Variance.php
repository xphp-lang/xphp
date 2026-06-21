<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

/**
 * Variance marker on a type parameter.
 *
 *  - `Invariant` (the default, no prefix): T can appear in both input and
 *    output positions; specializations are unrelated unless their args are
 *    identical.
 *  - `Covariant` (`+T`): T can appear in method return positions (and in a
 *    plain constructor parameter — see below). `T1 <: T2` lifts to
 *    `Box<T1> <: Box<T2>`.
 *  - `Contravariant` (`-T`): T can appear in method parameter positions (and in
 *    a plain constructor parameter). `T1 <: T2` lifts to `Box<T2> <: Box<T1>`
 *    (flipped).
 *
 * A **public/protected** property position (mutable AND readonly, including a
 * public/protected *promoted* constructor parameter), bounds, and defaults are
 * strict-invariant for both `+T` and `-T`: PHP enforces invariant property types
 * across `extends` chains regardless of `readonly`, so a covariance allowance
 * there would PHP-fatal at autoload when the variance edge emits. A **private**
 * property (declared or promoted, mutable or readonly), by contrast, may carry
 * any variance: PHP does not type-check private slots across the chain and a
 * private slot is invisible to the variance surface, so the real substituted
 * type is emitted soundly. A **by-reference** parameter (`T &$x`) is invariant —
 * it is read and written back, acting as input and output at once. A plain
 * (non-promoted, non-by-ref) constructor parameter may also carry any variance:
 * a constructor isn't part of the visible variance surface and PHP exempts
 * `__construct` from LSP, so its real type is emitted.
 *
 * String-backed so the registry JSON serializes cleanly.
 */
enum Variance: string
{
    case Invariant = 'invariant';
    case Covariant = 'covariant';
    case Contravariant = 'contravariant';
}
