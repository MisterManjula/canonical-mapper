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

    public function testAMenuCannotBeBuiltAroundAComponentItDoesNotContain(): void
    {
        // The backstop. With the cascade rule in place nothing reaches this, and
        // that is what it is for: the canonical model cannot be *constructed* in
        // a state where a consumer reading the JSON would find a SKU that is not
        // in the menu, so the rule is not the only thing preventing it.
        //
        // The cost of the arrangement is that a bug in the rule arrives at the
        // boundary as MalformedSource — "the file is broken" — when the file is
        // fine. A message naming two products the export plainly contains is how
        // that reads from the outside, and this is the test to reread when it
        // does.
        $this->expectException(InvariantViolated::class);

        CanonicalMenu::of([
            self::item('99', 'Espresso'),
            Item::composite(
                Sku::ofDigits('1310'),
                'Breakfast set',
                Money::fromMinorUnits(242),
                [ComponentRef::of(Sku::ofDigits('1210'), 1)],
            ),
        ]);
    }

    public function testAMenuThatDoesContainItsComponentsIsAccepted(): void
    {
        // Without this the check above would keep passing if it refused every
        // composite ever written, and the equivalence fixtures would be the only
        // thing standing between that and a release.
        $menu = CanonicalMenu::of([
            Item::composite(
                Sku::ofDigits('1310'),
                'Breakfast set',
                Money::fromMinorUnits(242),
                [ComponentRef::of(Sku::ofDigits('99'), 1)],
            ),
            self::item('99', 'Espresso'),
        ]);

        // Listed after the composite that references it, deliberately: a source
        // is free to describe a set before the products in it, and the check runs
        // over the whole list rather than as each item arrives.
        self::assertSame(
            ['99', '1310'],
            array_map(static fn (Item $item): string => $item->sku->value, $menu->items),
            'A menu that contains its own components was refused, or lost one of them',
        );
    }

    private static function item(string $plu, string $name): Item
    {
        return Item::simple(Sku::ofDigits($plu), $name, Money::fromMinorUnits(110));
    }
}
