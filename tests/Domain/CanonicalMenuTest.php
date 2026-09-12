<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests\Domain;

use CanonicalMapper\Domain\Canonical\CanonicalMenu;
use CanonicalMapper\Domain\Canonical\ComponentRef;
use CanonicalMapper\Domain\Canonical\Item;
use CanonicalMapper\Domain\Canonical\Money;
use CanonicalMapper\Domain\Canonical\Sku;
use CanonicalMapper\Domain\InvariantViolated;
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
            Sku::ofDigits('1204'),
            'Breakfast set',
            Money::fromMinorUnits(305),
            [
                ComponentRef::of(Sku::ofDigits('1100'), 1),
                ComponentRef::of(Sku::ofDigits('99'), 1),
            ],
        );

        self::assertSame(
            ['99', '1100'],
            array_map(static fn (ComponentRef $ref): string => $ref->sku->value, $composite->components),
            'A composite did not put its components in canonical SKU order',
        );
    }

    public function testOneProductListedTwiceIsRejectedRatherThanPricedTwice(): void
    {
        // Two spellings of one PLU reach this point as one SKU, because erasing
        // the difference is the adapter's job and it has already been done. What
        // is left for the model is the consequence: the export answered the same
        // question twice, and an item with two prices breaks the invariant the
        // whole model rests on.
        //
        // That the two spellings do converge is asserted where the converting
        // happens, in AlphaPosTest.
        $this->expectException(InvariantViolated::class);

        CanonicalMenu::of([
            self::item('1204', 'Breakfast set'),
            self::item('1204', 'Breakfast set'),
        ]);
    }

    public function testAComponentListedTwiceLeavesTheRecipeAmbiguousAndIsRejected(): void
    {
        $this->expectException(InvariantViolated::class);

        Item::composite(
            Sku::ofDigits('1204'),
            'Breakfast set',
            Money::fromMinorUnits(305),
            [
                ComponentRef::of(Sku::ofDigits('99'), 1),
                ComponentRef::of(Sku::ofDigits('99'), 2),
            ],
        );
    }

    private static function item(string $plu, string $name): Item
    {
        return Item::simple(Sku::ofDigits($plu), $name, Money::fromMinorUnits(110));
    }
}
