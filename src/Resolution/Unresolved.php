<?php

declare(strict_types=1);

namespace CanonicalMapper\Resolution;

/**
 * A value that could not be determined, and the flag saying what a human must
 * check before it can be.
 *
 * It carries no payload, which is why it is not generic. The type of the value
 * that failed to resolve is not information anyone can act on; the flag is.
 *
 * Implementing Resolution<never> under a covariant template makes one Unresolved
 * satisfy Resolution<Money>, Resolution<Sku> and Resolution<Item> at once, so
 * propagating one upward costs nothing. Propagating is still not the same as
 * cascading: an adapter returns the same Unresolved when it is simply passing a
 * failure along, and builds a new one with its own flag when a composite is
 * withheld because of a component.
 *
 * @implements Resolution<never>
 */
final class Unresolved implements Resolution
{
    private function __construct(public readonly Flag $flag)
    {
    }

    public static function because(Flag $flag): self
    {
        return new self($flag);
    }
}
