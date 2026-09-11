<?php

declare(strict_types=1);

namespace CanonicalMapper\Canonical;

use CanonicalMapper\MalformedSource;

/**
 * A product identifier, normalised to digits with no padding and no prefix.
 *
 * The three sources spell the same product three ways — "001204", 1204 and
 * "P-1204" — and the canonical model has to erase that difference, because two
 * menus that describe the same assortment must serialise to the same bytes.
 *
 * There are three named constructors rather than one tolerant parser, and each
 * is strict about its own source's spelling: a GammaPos value arriving without
 * its "P-" prefix is a malformed file, not an alternative spelling. Tolerance
 * here would be cheap to write and would quietly make the equivalence test prove
 * less than it claims — a parser that accepts everything cannot demonstrate that
 * three formats were reconciled, only that they were shrugged at.
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
     * AlphaPos writes the PLU as a zero-padded JSON string: "001204".
     *
     * @throws MalformedSource
     */
    public static function fromPaddedString(string $plu): self
    {
        return new self(self::normalise($plu, $plu, 'AlphaPos plu'));
    }

    /**
     * BetaPos writes the PLU as an XML attribute with no padding: code="1204".
     *
     * @throws MalformedSource
     */
    public static function fromAttribute(string $code): self
    {
        return new self(self::normalise($code, $code, 'BetaPos code attribute'));
    }

    /**
     * GammaPos writes the PLU in a CSV column with a literal prefix: "P-1204".
     *
     * @throws MalformedSource
     */
    public static function fromPrefixedColumn(string $column): self
    {
        if (!str_starts_with($column, 'P-')) {
            throw new MalformedSource(sprintf(
                'GammaPos PLU column "%s" does not carry the "P-" prefix the format requires.',
                $column,
            ));
        }

        return new self(self::normalise(substr($column, 2), $column, 'GammaPos PLU column'));
    }

    /**
     * Orders by length first and only then lexicographically, which is numeric
     * order without converting an identifier to an integer.
     *
     * Plain strcmp would sort "1204" before "99", so the order of items in the
     * canonical output would depend on how many digits a source happened to use —
     * exactly the difference the normalisation above exists to erase. Casting to
     * int would sort correctly and reintroduce an overflow that the string form
     * does not have.
     */
    public static function compare(self $a, self $b): int
    {
        return strlen($a->value) <=> strlen($b->value)
            ?: strcmp($a->value, $b->value);
    }

    /**
     * @param string $raw the value as the source wrote it, so the error names what a human would search for
     *
     * @return non-empty-string
     *
     * @throws MalformedSource
     */
    private static function normalise(string $digits, string $raw, string $source): string
    {
        if (preg_match('/^\d+$/', $digits) !== 1) {
            throw new MalformedSource(sprintf('%s "%s" is not a sequence of digits.', $source, $raw));
        }

        if (strlen($digits) > self::MAXIMUM_DIGITS) {
            throw new MalformedSource(sprintf(
                '%s "%s" has more than %d digits and is not a product identifier.',
                $source,
                $raw,
                self::MAXIMUM_DIGITS,
            ));
        }

        $normalised = ltrim($digits, '0');

        // ltrim leaves nothing behind when every digit was a zero. Reading that as
        // SKU 0 would invent a product; it is a padded field nobody filled in.
        if ($normalised === '') {
            throw new MalformedSource(sprintf('%s "%s" is entirely zeroes.', $source, $raw));
        }

        return $normalised;
    }
}
