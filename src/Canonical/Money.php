<?php

declare(strict_types=1);

namespace CanonicalMapper\Canonical;

use CanonicalMapper\MalformedSource;
use CanonicalMapper\RoundingRequired;

/**
 * A gross, customer-facing amount in minor units of a single currency.
 *
 * Never a float. Money in floating point is wrong in a way that stays hidden
 * until a total is compared for equality, and the equivalence guarantee here is
 * exactly such a comparison.
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
     * @throws MalformedSource
     */
    public static function fromMinorUnits(int $minorUnits): self
    {
        if ($minorUnits < 0) {
            throw new MalformedSource(sprintf('A price of %d minor units is negative.', $minorUnits));
        }

        if ($minorUnits > self::MAXIMUM_MINOR_UNITS) {
            throw new MalformedSource(sprintf('A price of %d minor units is not a menu price.', $minorUnits));
        }

        return new self($minorUnits);
    }

    /**
     * AlphaPos writes gross prices as a decimal string with a dot: "1.50".
     *
     * @throws MalformedSource
     */
    public static function fromDecimalString(string $value): self
    {
        return self::fromSeparatedDecimal($value, '.', 'AlphaPos price');
    }

    /**
     * GammaPos writes gross prices as a decimal string with a comma: "1,50".
     *
     * @throws MalformedSource
     */
    public static function fromCommaDecimalString(string $value): self
    {
        return self::fromSeparatedDecimal($value, ',', 'GammaPos price');
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
     * @throws MalformedSource
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
     * @throws MalformedSource
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

    /**
     * Exactly two decimals, no more and no fewer, and the separator fixed per
     * source.
     *
     * "1.5" is rejected rather than read as 1.50, and "1.505" rejected rather
     * than rounded. Three decimals are the only way a decimal string could
     * require a rounding policy, so refusing them is what makes the absence of
     * one structural instead of a matter of luck. And a source has exactly one
     * spelling for a price: accepting a second one would weaken what the
     * byte-identical output proves, from "three formats were reconciled" to
     * "three formats were shrugged at".
     *
     * @throws MalformedSource
     */
    private static function fromSeparatedDecimal(string $value, string $separator, string $source): self
    {
        $pattern = '/^\d{1,10}' . preg_quote($separator, '/') . '\d{2}$/';

        if (preg_match($pattern, $value) !== 1) {
            throw new MalformedSource(sprintf(
                '%s "%s" is not an amount with exactly two decimals separated by "%s".',
                $source,
                $value,
                $separator,
            ));
        }

        // The pattern above fixes the shape, so the last two characters are the
        // cents and everything before the separator is the units. Taken by
        // position rather than by splitting, which keeps both halves plain
        // strings instead of offsets that would then have to be proved to exist.
        $units = substr($value, 0, -3);
        $cents = substr($value, -2);

        return self::fromMinorUnits((int) $units * 100 + (int) $cents);
    }
}
