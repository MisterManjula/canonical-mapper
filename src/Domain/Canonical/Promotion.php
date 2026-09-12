<?php

declare(strict_types=1);

namespace CanonicalMapper\Domain\Canonical;

use CanonicalMapper\Domain\InvariantViolated;
use CanonicalMapper\Domain\RoundingRequired;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A price that applies instead of the usual one, over an inclusive range of days.
 *
 * The canonical model stores the resulting price, not the mechanism that produced
 * it (ADR-002). One source states "20% off", another states "1.20 until the end
 * of the month", and both mean the same thing to a customer. Keeping the
 * mechanism would make two identical menus serialise differently depending on
 * which system exported them — a difference in bookkeeping presented as a
 * difference in the menu, and precisely the kind of accident the canonical model
 * exists to absorb.
 *
 * What that costs is stated plainly: the percentage is not recoverable from the
 * canonical output. A consumer that needs to display "20% off" rather than the
 * new price cannot get it from here.
 */
final class Promotion
{
    /**
     * Dates are days, not instants. Both ends are inclusive, and both are pinned
     * to UTC midnight so that two promotions parsed in different runs, or by
     * different adapters, compare equal rather than nearly equal.
     */
    private const DAY_FORMAT = '!Y-m-d';

    private function __construct(
        public readonly Money $price,
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
    ) {
    }

    /**
     * For a source that states the promotional price directly.
     *
     * @throws InvariantViolated
     */
    public static function atPrice(Money $price, string $from, string $to): self
    {
        return self::over($price, $from, $to);
    }

    /**
     * For a source that states a percentage off the usual price.
     *
     * @param int<0, 100> $percent
     *
     * @throws InvariantViolated
     * @throws RoundingRequired
     */
    public static function percentageOff(Money $basePrice, int $percent, string $from, string $to): self
    {
        return self::over($basePrice->lessPercentage($percent), $from, $to);
    }

    /**
     * Whether both promotions are in force on at least one shared day.
     *
     * The test the conflict case is built on: two overlapping promotions with
     * different prices leave no single price to publish on the days they share,
     * which is what makes the item unresolvable rather than merely untidy.
     */
    public function overlaps(self $other): bool
    {
        return $this->from <= $other->to && $other->from <= $this->to;
    }

    /**
     * @throws InvariantViolated
     */
    private static function over(Money $price, string $from, string $to): self
    {
        $start = self::day($from);
        $end = self::day($to);

        if ($start > $end) {
            throw new InvariantViolated(sprintf('Promotion runs from %s to %s, which ends before it starts.', $from, $to));
        }

        return new self($price, $start, $end);
    }

    /**
     * @throws InvariantViolated
     */
    private static function day(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(self::DAY_FORMAT, $value, new DateTimeZone('UTC'));

        if ($parsed === false) {
            throw new InvariantViolated(sprintf('Promotion date "%s" is not an ISO 8601 date.', $value));
        }

        // createFromFormat is forgiving in a way that would quietly change the
        // data: "2026-02-30" parses, and comes back as the 2nd of March. The
        // round trip is what rejects it. Without this, a typo in a date would
        // silently move a promotion rather than be reported.
        if ($parsed->format('Y-m-d') !== $value) {
            throw new InvariantViolated(sprintf('Promotion date "%s" is not a real date.', $value));
        }

        return $parsed;
    }
}
