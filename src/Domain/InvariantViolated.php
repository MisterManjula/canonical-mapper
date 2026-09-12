<?php

declare(strict_types=1);

namespace CanonicalMapper\Domain;

use RuntimeException;

/**
 * A canonical type was asked to hold a value it is not allowed to hold.
 *
 * The domain needs an exception of its own because it is not entitled to the
 * one it used to throw. MalformedSource is part of the SourceAdapter port's
 * contract, and it says something the domain cannot know: that a *file* was
 * unreadable. A negative price and a duplicate SKU are broken invariants
 * whoever produced them, and naming the cause is the boundary's job rather than
 * the model's.
 *
 * So the wording here describes the rule that was broken and never the format
 * that broke it: "a product identifier must be a sequence of digits", and
 * never the name of a format or the path of a field. The adapter catches this
 * and rethrows it as MalformedSource with the path and the raw text it alone
 * can supply, which is why the two messages read better together than either
 * did alone.
 *
 * Distinct from RoundingRequired, which is a LogicException and stays one. That
 * is raised when this codebase's own assumptions are wrong and nobody's input
 * is at fault; this is raised when the input is.
 *
 * Named $detail rather than $message for the reason MalformedSource is: a
 * promoted readonly property cannot redeclare the one Exception already has.
 */
final class InvariantViolated extends RuntimeException
{
    public function __construct(public readonly string $detail)
    {
        parent::__construct($detail);
    }
}
