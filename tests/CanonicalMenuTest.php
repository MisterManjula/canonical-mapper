<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests;

use CanonicalMapper\Canonical\CanonicalMenu;
use CanonicalMapper\Canonical\ComponentRef;
use CanonicalMapper\Canonical\Item;
use CanonicalMapper\Canonical\Money;
use CanonicalMapper\Canonical\Sku;
use CanonicalMapper\MalformedSource;
use PHPUnit\Framework\TestCase;

/**
 * Ordering is settled in the model, not in the writer, and these tests are what
 * makes that claim checkable.
 *
 * If it were settled in the writer, byte-identical output would depend on the
 * writer being asked politely; here it is a property of the value, so three
 * adapters that build the same assortment in three different orders cannot
 * produce three different menus however carelessly they iterate.
 */
final class CanonicalMenuTest extends TestCase
{
    public function testItemsAreSortedBySkuRegardlessOfTheOrderTheyWereBuiltIn(): void
    {
        $menu = CanonicalMenu::of([
            self::item('1204', 'Breakfast set'),
            self::item('99', 'Espresso'),
            self::item('1100', 'Orange juice'),
        ]);

        self::assertSame(
            ['99', '1100', '1204'],
            array_map(static fn (Item $item): string => $item->sku->value, $menu->items),
            'The menu was not in canonical SKU order',
        );
    }

    public function testComponentsAreSortedBySkuInsideTheItem(): void
    {
        $composite = Item::composite(
            Sku::fromAttribute('1204'),
            'Breakfast set',
            Money::fromDecimalString('3.05'),
            [
                ComponentRef::of(Sku::fromAttribute('1100'), 1),
                ComponentRef::of(Sku::fromAttribute('99'), 1),
            ],
        );

        self::assertSame(
            ['99', '1100'],
            array_map(static fn (ComponentRef $ref): string => $ref->sku->value, $composite->components),
            'A composite did not put its components in canonical SKU order',
        );
    }

    public function testTheSameProductSpelledTwoWaysIsStillOneProduct(): void
    {
        // The padded and unpadded spellings normalise to the same SKU, so this is
        // one product listed twice rather than two products. Reading it any other
        // way would put two prices on one item and break the invariant the whole
        // model rests on.
        $this->expectException(MalformedSource::class);

        CanonicalMenu::of([
            self::item('001204', 'Breakfast set'),
            self::item('1204', 'Breakfast set'),
        ]);
    }

    public function testAComponentListedTwiceLeavesTheRecipeAmbiguousAndIsRejected(): void
    {
        $this->expectException(MalformedSource::class);

        Item::composite(
            Sku::fromAttribute('1204'),
            'Breakfast set',
            Money::fromDecimalString('3.05'),
            [
                ComponentRef::of(Sku::fromAttribute('99'), 1),
                ComponentRef::of(Sku::fromAttribute('99'), 2),
            ],
        );
    }

    private static function item(string $plu, string $name): Item
    {
        return Item::simple(Sku::fromPaddedString($plu), $name, Money::fromDecimalString('1.10'));
    }
}
