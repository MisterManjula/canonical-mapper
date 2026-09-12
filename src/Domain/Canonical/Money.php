<?php

declare(strict_types=1);

namespace CanonicalMapper\Domain\Canonical;

use CanonicalMapper\Domain\InvariantViolated;
use CanonicalMapper\Domain\RoundingRequired;

/**
 * A gross, customer-facing amount in minor units of a single currency.
 *
 * Never a float. Money in floating point is wrong in a way that stays hidden
 * until a total is compared for equality, and the equivalence guarantee here is
 * exactly such a comparison.
 *
 * Minor units are the only way in. Reading "1.50" or "1,50" is a fact about a
 * file and belongs to whichever adapter meets that file; this class would have
 * needed one parser per separator, which is one per source, which is the
 * arrangement that made a decimal point a concept in the domain.
 *
 * Tax arithmetic stayed, and the line between the two is worth stating: turning
 * "1,50" into 150 is transcription, and turning a net 100 at 10% into a gross
 * 110 is a rule about what a price means. One of them changes if a source
 * changes its mind about punctuation; the other changes if the business does.
 *
 * Always gross, including tax, whatever the source stores (ADR-002). One of the
 * three sources keeps net prices and a tax code; converting on the way in rather
 * than on the way out means the canonical model holds the number a customer is
 * shown, and every consumer of it is spared the question of which basis applies.
 *
 * There is no currency field. Multi-currency is out of scope, and an EUR-only
 * field carried on every amount would be a promise this project does not keep.
 * The currency is stated once, at the top of the serialised output.
 */
final class Money
{
    /**
     * An amount above this is not a menu price, and refusing it here keeps every
     * multiplication below out of overflow range: the largest intermediate this
     * class computes is minorUnits * 200, which is four orders of magnitude below
     * PHP_INT_MAX on a 64-bit platform.
     */
    private const MAXIMUM_MINOR_UNITS = 1_000_000_000_000;

    /**
     * @param int<0, max> $minorUnits
     */
    private function __construct(public readonly int $minorUnits)
    {
    }

    /**
     * @throws InvariantViolated
     */
    public static function fromMinorUnits(int $minorUnits): self
    {
        if ($minorUnits < 0) {
            throw new InvariantViolated(sprintf('A price of %d minor units is negative.', $minorUnits));
        }

        if ($minorUnits > self::MAXIMUM_MINOR_UNITS) {
            throw new InvariantViolated(sprintf('A price of %d minor units is not a menu price.', $minorUnits));
        }

        return new self($minorUnits);
    }

    /**
     * BetaPos writes net prices in minor units alongside a VAT code, so the gross
     * price the canonical model stores has to be computed rather than read.
     *
     * Resolving the code to a rate is the adapter's job; by the time a value
     * reaches here the rate is known, and the only remaining question is whether
     * the arithmetic is exact.
     *
     * @param int<0, 100> $vatPercent
     *
     * @throws InvariantViolated
     * @throws RoundingRequired
     */
    public static function fromNetMinorUnitsAndVatPercent(int $netMinorUnits, int $vatPercent): self
    {
        $net = self::fromMinorUnits($netMinorUnits);

        $grossInHundredths = $net->minorUnits * (100 + $vatPercent);

        // No rounding policy exists in this project, deliberately. Half-up,
        // half-even and truncation are three defensible answers, they disagree in
        // the last cent, and picking one here would silently invent money. A
        // fraction of a cent means an assumption of this codebase is wrong, so it
        // is raised rather than resolved (see RoundingRequired).
        if ($grossInHundredths % 100 !== 0) {
            throw RoundingRequired::forVat($netMinorUnits, $vatPercent);
        }

        return self::fromMinorUnits(intdiv($grossInHundredths, 100));
    }

    /**
     * AlphaPos states promotions as a percentage off rather than as a price, so
     * the promotional price has to be computed the same way.
     *
     * @param int<0, 100> $percent
     *
     * @throws InvariantViolated
     * @throws RoundingRequired
     */
    public function lessPercentage(int $percent): self
    {
        $remainingInHundredths = $this->minorUnits * (100 - $percent);

        if ($remainingInHundredths % 100 !== 0) {
            throw RoundingRequired::forPercentageDiscount($this->minorUnits, $percent);
        }

        return self::fromMinorUnits(intdiv($remainingInHundredths, 100));
    }
}
