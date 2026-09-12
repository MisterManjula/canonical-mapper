<?php

declare(strict_types=1);

namespace CanonicalMapper\Domain\Resolution;

use CanonicalMapper\Domain\InvariantViolated;

/**
 * The name of the system a flag tells someone to go and open.
 *
 * A flag is a work item, and the first thing it has to answer is "where do I
 * do this?". The domain needs that answer in order to write the sentence, and
 * it needs nothing else about the source: not how many there are, not which
 * ones exist, not what they are called in a CLI argument.
 *
 * It used to need all of that, because the answer arrived as an enum with one
 * case per source. That enum was the domain's copy of a fact that belongs to
 * the outside — a fourth source meant editing a file in the canonical model,
 * which is exactly backwards. It is now in Infrastructure, where adding a
 * source is supposed to cause edits, and what crosses into the domain is this:
 * one name, already chosen.
 *
 * A value object rather than a string, because a string parameter beside
 * $sourceProductId and $detail is three strings in a row that a caller can
 * order wrongly, and because "not blank" is worth saying once rather than at
 * every call site. It deliberately cannot enumerate anything: there is no list
 * of valid names here, and adding one would put the enum back.
 */
final class SourceName
{
    /**
     * @param non-empty-string $value
     */
    private function __construct(public readonly string $value)
    {
    }

    /**
     * @throws InvariantViolated
     */
    public static function of(string $value): self
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvariantViolated(
                'A flag names the system someone has to open, and a blank name names nothing.',
            );
        }

        return new self($trimmed);
    }
}
