<?php

declare(strict_types=1);

namespace CanonicalMapper\Resolution;

/**
 * A value that either resolved or did not.
 *
 * This interface deliberately declares nothing, and that emptiness is the reason
 * the guarantee holds. There is no member on a Resolution to reach for, so the
 * only way to obtain the value is to narrow to Resolved first, and the only way
 * to narrow is instanceof — a fact the analyser checks, rather than a promise the
 * author makes in an annotation.
 *
 * Signatures throughout the codebase name the union `Resolved<X>|Unresolved` and
 * never this interface. That is not cosmetic. PHPStan narrows by subtracting a
 * class from a *union*; it cannot subtract a subtype from a base type, so a
 * method declared as returning Resolution<Money> would still be Resolution<Money>
 * after `if ($x instanceof Unresolved) { return $x; }`, the guard-clause style
 * would stop working, and the whole mechanism would silently switch itself off.
 *
 * The union in the signature is therefore what seals the type. Nothing stops a
 * fourth implementation of this interface from being written; what stops it
 * mattering is that no existing method could return one without its own
 * signature being edited first.
 *
 * @template-covariant T
 */
interface Resolution
{
}
