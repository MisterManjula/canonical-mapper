<?php

declare(strict_types=1);

namespace CanonicalMapper;

use RuntimeException;

/**
 * The file could not be understood: a price that is not a number, a PLU with no
 * digits in it, a column the format requires and does not have.
 *
 * Deliberately distinct from an Unresolved value, and the distinction is the
 * whole argument of this repository. An Unresolved value means the file was read
 * perfectly well and one fact in it is ambiguous; the run continues, the item is
 * withheld, and a human is told which one and why. A MalformedSource means there
 * is nothing to read, so there is no item to withhold and no useful flag to
 * raise. The CLI reports the first as exit 3 and the second as exit 1.
 *
 * Named $detail and not $message because Exception already has a $message, and a
 * promoted readonly property cannot redeclare an inherited one.
 */
final class MalformedSource extends RuntimeException
{
    public function __construct(public readonly string $detail)
    {
        parent::__construct($detail);
    }
}
