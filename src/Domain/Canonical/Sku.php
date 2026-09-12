<?php

declare(strict_types=1);

namespace CanonicalMapper\Domain\Canonical;

use CanonicalMapper\Domain\InvariantViolated;

/**
 * A product identifier, in one canonical spelling: digits, no padding, no
 * prefix.
 *
 * The three sources spell the same product three ways — "001204", 1204 and
 * "P-1204" — and the canonical model has to erase that difference, because two
 * menus that describe the same assortment must serialise to the same bytes.
 *
 * Erasing it is the adapters' work, not this class's. There used to be a named
 * constructor per source here, which meant the domain knew that one format pads
 * with zeroes and another writes a "P-" prefix; a fourth source would have added
 * a fourth constructor to a file that has nothing to do with files. What is left
 * is the rule that survives every format: a SKU is a run of digits, short enough
 * to be an identifier, with no leading zero.
 *
 * The leading-zero rule is what makes this a canonical spelling rather than a
 * tolerated one. Accepting "001204" here would let two adapters disagree about a
 * product while both believing they had normalised it, and the equivalence
 * guarantee would fail as a difference in output bytes rather than as an error
 * anyone could read. Refusing it turns a normalisation an adapter forgot into a
 * loud failure at the boundary.
 */
final class Sku
{
    /**
     * A PLU long enough to exceed this is not a product identifier, and allowing
     * it would make the length-first ordering in compare() meaningless.
     */
    private const MAXIMUM_DIGITS = 12;

    /**
     * @param non-empty-string $value
     */
    private function __construct(public readonly string $value)
    {
    }

    /**
     * @throws InvariantViolated
     */
    public static function ofDigits(string $digits): self
    {
        if (preg_match('/^\d+$/', $digits) !== 1) {
            throw new InvariantViolated(sprintf(
                'A product identifier must be a sequence of digits, and "%s" is not.',
                $digits,
            ));
        }

        if (strlen($digits) > self::MAXIMUM_DIGITS) {
            throw new InvariantViolated(sprintf(
                'A product identifier of %d digits is longer than the %d a menu uses.',
                strlen($digits),
                self::MAXIMUM_DIGITS,
            ));
        }

        // "0" is caught by the same rule as "007", and deliberately: a SKU of
        // zero is a padded field nobody filled in, not a product. An adapter that
        // strips padding turns "000000" into the empty string, which fails the
        // digits rule above and is reported against the text the file actually
        // contained.
        if (str_starts_with($digits, '0')) {
            throw new InvariantViolated(sprintf(
                'A product identifier is written without padding, and "%s" has a leading zero.',
                $digits,
            ));
        }

        return new self($digits);
    }

    /**
     * Orders by length first and only then lexicographically, which is numeric
     * order without converting an identifier to an integer.
     *
     * Plain strcmp would sort "1204" before "99", so the order of items in the
     * canonical output would depend on how many digits a source happened to use —
     * exactly the difference the canonical spelling above exists to erase. Casting
     * to int would sort correctly and reintroduce an overflow that the string form
     * does not have.
     */
    public static function compare(self $a, self $b): int
    {
        return strlen($a->value) <=> strlen($b->value)
            ?: strcmp($a->value, $b->value);
    }
}
