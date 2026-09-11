<?php

declare(strict_types=1);

namespace CanonicalMapper;

use CanonicalMapper\Canonical\CanonicalMenu;
use CanonicalMapper\Resolution\Flag;

/**
 * What was published, and what was withheld.
 *
 * The two travel together and neither is the exception. A run that withheld
 * something has not failed — it has produced a menu and a list of questions — so
 * presenting the flags as an error channel would misdescribe what happened. The
 * CLI keeps them apart on stdout and stderr for the same reason it reports exit 3
 * rather than exit 1: this is the system working.
 *
 * There is no "succeeded" flag. Whether the run needs a human is answerable from
 * the flags, and a second field saying the same thing is a field that can
 * disagree with the first.
 */
final class MappingResult
{
    /**
     * @param list<Flag> $flags
     */
    private function __construct(
        public readonly CanonicalMenu $menu,
        public readonly array $flags,
    ) {
    }

    /**
     * @param list<Flag> $flags
     */
    public static function of(CanonicalMenu $menu, array $flags): self
    {
        return new self($menu, $flags);
    }
}
