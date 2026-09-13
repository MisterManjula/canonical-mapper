<?php

declare(strict_types=1);

namespace CanonicalMapper\Infrastructure\Source\Gamma;

use CanonicalMapper\Application\Port\MalformedSource;
use CanonicalMapper\Application\Port\SourceAdapter;
use CanonicalMapper\Domain\Canonical\ComponentRef;
use CanonicalMapper\Domain\Canonical\Item;
use CanonicalMapper\Domain\Canonical\Money;
use CanonicalMapper\Domain\Canonical\Sku;
use CanonicalMapper\Domain\InvariantViolated;
use CanonicalMapper\Domain\Resolution\Resolved;
use CanonicalMapper\Domain\Resolution\SourceName;
use CanonicalMapper\Domain\Resolution\Unresolved;
use CanonicalMapper\Infrastructure\Source\SourceSystem;

/**
 * Reads the GammaPos CSV export.
 *
 * The flattest of the three formats, and the one whose shape carries the least.
 * A JSON object can nest and an XML element can contain; a row can only sit next
 * to another row, so a relationship has to be spelled as a value in a column. In
 * GammaPos that column is PARENT_PLU, and it points upward — the child names its
 * composite, where the other two sources have the composite name its children.
 * Reconciling that inversion is most of what this adapter does.
 *
 * There are two kinds of row and one header:
 *
 *   PLU;NAME;PRICE;PARENT_PLU;QTY
 *   P-99;Espresso;1,10;;            <- a product
 *   P-99;;;P-1310;1                 <- that product, as a line of a recipe
 *
 * A product row defines a product. A membership row says that a product is a
 * component of a composite, and carries nothing else: no name and no price,
 * because the product row already gave them and a second copy could disagree.
 * Keeping them apart is what lets a product be sold on its own and be part of a
 * set at the same time, which a single row with a PARENT_PLU could not express.
 *
 * The semicolon is not decoration. GammaPos writes decimals with a comma, so a
 * comma-separated file would need every price quoted; a semicolon-separated one
 * is what a European till actually emits, and the collision it avoids is the
 * reason this format's separator differs from its name.
 *
 * A membership row naming a component with no product row of its own is the
 * ambiguity this source can express, and this adapter no longer answers it. It
 * reports the reference it was given; the composite is withheld by the cascade
 * rule in the domain, which does the same for the two formats that can express
 * the same gap and for the one that cannot yet. What this adapter lost by that
 * is the "P-" spelling on the resulting flag, which is a real loss and a small
 * one beside a check that existed in one adapter out of three.
 *
 * A membership row naming a *parent* with no product row stays here, and stays a
 * MalformedSource: it is not ambiguous but contradictory. The file says a product
 * is part of something it never mentions again, so there is no composite to
 * withhold and nothing for a rule about composites to act on.
 */
final class GammaPosAdapter implements SourceAdapter
{
    private const DELIMITER = ';';

    private const COLUMNS = ['PLU', 'NAME', 'PRICE', 'PARENT_PLU', 'QTY'];

    public function sourceName(): SourceName
    {
        return SourceSystem::Gamma->sourceName();
    }

    /**
     * @return list<Resolved<Item>|Unresolved>
     *
     * @throws MalformedSource
     */
    public function read(string $contents): array
    {
        $rows = self::rows($contents);

        $products = [];
        $memberships = [];

        foreach ($rows as $line => $row) {
            if (self::column($row, 'PARENT_PLU', $line) === '') {
                $products = self::product($products, $row, $line);

                continue;
            }

            $memberships[] = self::membership($row, $line);
        }

        return self::assemble($products, $memberships);
    }

    /**
     * @param array<string, array{sku: Sku, plu: string, name: string, price: Money}> $products
     * @param list<array{parent: Sku, parentPlu: string, child: Sku, childPlu: string, quantity: int, line: int}> $memberships
     *
     * @return list<Resolved<Item>|Unresolved>
     *
     * @throws MalformedSource
     */
    private static function assemble(array $products, array $memberships): array
    {
        $recipes = [];

        foreach ($memberships as $membership) {
            if (!array_key_exists($membership['parent']->value, $products)) {
                throw new MalformedSource(sprintf(
                    'Line %d makes %s part of %s, which has no product row in this export.',
                    $membership['line'],
                    $membership['childPlu'],
                    $membership['parentPlu'],
                ));
            }

            // A membership row naming a child with no product row of its own is
            // not checked here, and the reference is built regardless. That the
            // composite must then be withheld is true of every format, so it is
            // ruled on once in the domain rather than a third time in the one
            // adapter that happened to notice it first.
            $recipes[$membership['parent']->value][] = ComponentRef::of(
                $membership['child'],
                $membership['quantity'],
            );
        }

        $resolutions = [];

        foreach ($products as $key => $product) {
            $components = $recipes[$key] ?? [];

            try {
                $resolutions[] = Resolved::of($components === []
                    ? Item::simple($product['sku'], $product['name'], $product['price'])
                    : Item::composite($product['sku'], $product['name'], $product['price'], $components));
            } catch (InvariantViolated $violation) {
                throw new MalformedSource(sprintf('Product %s: %s', $product['plu'], $violation->detail));
            }
        }

        return $resolutions;
    }

    /**
     * @param array<string, array{sku: Sku, plu: string, name: string, price: Money}> $products
     * @param array<string, string> $row
     *
     * @return array<string, array{sku: Sku, plu: string, name: string, price: Money}>
     *
     * @throws MalformedSource
     */
    private static function product(array $products, array $row, int $line): array
    {
        if (self::column($row, 'QTY', $line) !== '') {
            throw new MalformedSource(sprintf(
                'Line %d is a product row and carries a QTY, which belongs to a membership row.',
                $line,
            ));
        }

        $plu = self::column($row, 'PLU', $line);
        $sku = self::sku($plu, $line);

        if (array_key_exists($sku->value, $products)) {
            throw new MalformedSource(sprintf('Line %d gives product %s a second product row.', $line, $plu));
        }

        $products[$sku->value] = [
            'sku' => $sku,
            'plu' => $plu,
            'name' => self::column($row, 'NAME', $line),
            'price' => self::money(self::column($row, 'PRICE', $line), $line),
        ];

        return $products;
    }

    /**
     * @param array<string, string> $row
     *
     * @return array{parent: Sku, parentPlu: string, child: Sku, childPlu: string, quantity: int, line: int}
     *
     * @throws MalformedSource
     */
    private static function membership(array $row, int $line): array
    {
        // A membership row that also carried a name or a price would be a second
        // definition of the product, and two definitions can disagree. The format
        // has one place to say what a product is, and this is not it.
        foreach (['NAME', 'PRICE'] as $column) {
            if (self::column($row, $column, $line) !== '') {
                throw new MalformedSource(sprintf(
                    'Line %d is a membership row and carries a %s, which belongs to the product row.',
                    $line,
                    $column,
                ));
            }
        }

        $childPlu = self::column($row, 'PLU', $line);
        $parentPlu = self::column($row, 'PARENT_PLU', $line);

        return [
            'parent' => self::sku($parentPlu, $line),
            'parentPlu' => $parentPlu,
            'child' => self::sku($childPlu, $line),
            'childPlu' => $childPlu,
            'quantity' => self::quantity(self::column($row, 'QTY', $line), $line),
            'line' => $line,
        ];
    }

    /**
     * GammaPos writes the PLU with a literal "P-" prefix: "P-1204".
     *
     * Stripping the prefix is this adapter's contribution to the identity of a
     * product, and it is strict about the spelling: a value arriving without the
     * prefix is a broken export, not an alternative spelling. Tolerance would be
     * one line and would quietly weaken what the cross-source comparison proves,
     * from "three formats were reconciled" to "three formats were shrugged at".
     *
     * Unlike AlphaPos there is no padding to strip, so a leading zero reaches the
     * domain and is refused there. That is the right place for it: "P-0099" is
     * not a spelling this format has, and the canonical model is what says so.
     *
     * @throws MalformedSource
     */
    private static function sku(string $plu, int $line): Sku
    {
        if (!str_starts_with($plu, 'P-')) {
            throw new MalformedSource(sprintf(
                'Line %d has the PLU "%s", which does not carry the "P-" prefix the format requires.',
                $line,
                $plu,
            ));
        }

        try {
            return Sku::ofDigits(substr($plu, 2));
        } catch (InvariantViolated $violation) {
            throw new MalformedSource(sprintf('Line %d, PLU "%s": %s', $line, $plu, $violation->detail));
        }
    }

    /**
     * GammaPos writes gross prices as a decimal string with a comma: "1,50".
     *
     * Exactly two decimals, for the reason the other adapters give: three would be
     * the only way a decimal string could ask for a rounding policy, and this
     * project does not have one.
     *
     * @throws MalformedSource
     */
    private static function money(string $value, int $line): Money
    {
        if (preg_match('/^\d{1,10},\d{2}$/', $value) !== 1) {
            throw new MalformedSource(sprintf(
                'Line %d has the price "%s", which is not an amount with exactly two decimals separated by ",".',
                $line,
                $value,
            ));
        }

        $units = substr($value, 0, -3);
        $cents = substr($value, -2);

        try {
            return Money::fromMinorUnits((int) $units * 100 + (int) $cents);
        } catch (InvariantViolated $violation) {
            throw new MalformedSource(sprintf('Line %d, price "%s": %s', $line, $value, $violation->detail));
        }
    }

    /**
     * @throws MalformedSource
     */
    private static function quantity(string $value, int $line): int
    {
        if (preg_match('/^\d{1,6}$/', $value) !== 1) {
            throw new MalformedSource(sprintf(
                'Line %d has the quantity "%s", which is not a whole number.',
                $line,
                $value,
            ));
        }

        return (int) $value;
    }

    /**
     * The data rows, keyed by the line of the file they came from.
     *
     * Keyed rather than numbered from zero, because every message this adapter
     * produces names a line and a person reading it has the file open.
     *
     * @return array<int, array<string, string>>
     *
     * @throws MalformedSource
     */
    private static function rows(string $contents): array
    {
        $header = null;
        $rows = [];

        foreach (explode("\n", $contents) as $index => $line) {
            $line = rtrim($line, "\r");

            // A trailing newline makes a last empty line, and an export is allowed
            // to be a well-formed text file.
            if (trim($line) === '') {
                continue;
            }

            $values = self::fields($line);

            if ($header === null) {
                $header = self::header($values);

                continue;
            }

            if (count($values) !== count($header)) {
                throw new MalformedSource(sprintf(
                    'Line %d has %d fields where the header declares %d.',
                    $index + 1,
                    count($values),
                    count($header),
                ));
            }

            $rows[$index + 1] = array_combine($header, $values);
        }

        if ($header === null) {
            throw new MalformedSource('The GammaPos export is empty; it does not even have a header row.');
        }

        return $rows;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     *
     * @throws MalformedSource
     */
    private static function header(array $values): array
    {
        $unknown = array_diff($values, self::COLUMNS);

        if ($unknown !== []) {
            throw new MalformedSource(sprintf(
                'The header carries %s, which this adapter has not been taught to read; '
                . 'a column that might affect the price cannot be ignored.',
                implode(', ', $unknown),
            ));
        }

        $absent = array_diff(self::COLUMNS, $values);

        if ($absent !== []) {
            throw new MalformedSource(sprintf('The header has no %s column.', implode(', no ', $absent)));
        }

        // array_combine below would silently collapse a repeated column onto one
        // key, and the row would then be read with a value from whichever copy
        // came last.
        if (count(array_unique($values)) !== count($values)) {
            throw new MalformedSource('The header names the same column twice.');
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private static function fields(string $line): array
    {
        $fields = [];

        // str_getcsv reports an empty field as null, and an empty field is exactly
        // what distinguishes the two kinds of row here, so the distinction between
        // "absent" and "empty" is one this format does not make.
        foreach (str_getcsv($line, self::DELIMITER, '"', '\\') as $field) {
            $fields[] = $field ?? '';
        }

        return $fields;
    }

    /**
     * @param array<string, string> $row
     *
     * @throws MalformedSource
     */
    private static function column(array $row, string $name, int $line): string
    {
        return $row[$name] ?? throw new MalformedSource(sprintf('Line %d has no %s.', $line, $name));
    }
}
