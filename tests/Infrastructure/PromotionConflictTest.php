<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests\Infrastructure;

use CanonicalMapper\Application\MappingResult;
use CanonicalMapper\Application\NormaliseMenu;
use CanonicalMapper\Domain\Canonical\Item;
use CanonicalMapper\Domain\Resolution\Flag;
use CanonicalMapper\Domain\Resolution\FlagReason;
use CanonicalMapper\Infrastructure\Source\Alpha\AlphaPosAdapter;
use PHPUnit\Framework\TestCase;

/**
 * The third ambiguity case, end to end, from a file on disk.
 *
 * The espresso carries two promotions in force on days they share, at different
 * prices. On the fifth of March the file states that a coffee costs 88 and that
 * it costs 99, and nothing in it says which; that is a question for whoever
 * entered the second promotion, and there is no answer this mapper could compute.
 *
 * The croissant carries one promotion of its own, over days in April that have
 * nothing to do with either of the espresso's. It is in the fixture to be
 * undisturbed — this is the assertion section 6 of the specification asks for by
 * name, and it is the difference between withholding an item and losing
 * confidence in the file.
 *
 * A JSON export cannot carry a comment explaining itself, so this docblock is
 * where the fixture is described.
 */
final class PromotionConflictTest extends TestCase
{
    public function testTheItemIsAbsentFromTheMenuAndTheRestOfItIsPublished(): void
    {
        $result = self::normaliseTheFixture();

        self::assertSame(
            ['1100', '1204'],
            array_map(static fn (Item $item): string => $item->sku->value, $result->menu->items),
            'The item with two promotions was published, or it took the clear ones with it',
        );
    }

    public function testOneFlagNamesIt(): void
    {
        $result = self::normaliseTheFixture();

        self::assertSame(
            [FlagReason::PromotionConflict],
            array_map(static fn (Flag $flag): FlagReason => $flag->reason, $result->flags),
            'The withheld item did not produce exactly one promotion-conflict flag',
        );
    }

    public function testTheFlagIsSomethingAPersonCanActOn(): void
    {
        $result = self::normaliseTheFixture();
        $flag = $result->flags[0] ?? null;

        if ($flag === null) {
            self::fail('Nothing was withheld, so there is no flag to read');
        }

        // Which system to open, which product to search for there, and which two
        // offers to choose between once they have found it.
        self::assertStringContainsString('AlphaPos', $flag->detail, 'The flag did not name the system to open');
        self::assertSame('000099', $flag->sourceProductId, 'The flag did not quote the id as the source wrote it');
        self::assertSame('99', $flag->sku?->value, 'The flag lost the canonical SKU');
        self::assertStringContainsString('88 minor units', $flag->detail, 'The flag did not name the first offer');
        self::assertStringContainsString('99 minor units', $flag->detail, 'The flag did not name the second offer');
        self::assertStringContainsString('days they share', $flag->detail, 'The flag did not say why the two cannot both stand');
    }

    public function testThePromotionOnTheUntouchedItemStillApplies(): void
    {
        // The half of this case that is easy to lose. An item withheld over a
        // promotion must not make the mapper cautious about promotions in
        // general, and a run that answered a conflict by dropping every promotion
        // would pass both of the first two tests.
        $result = self::normaliseTheFixture();
        $croissant = $result->menu->items[0] ?? null;

        if ($croissant === null) {
            self::fail('The menu is empty, so there is no promotion to look at');
        }

        self::assertSame(132, $croissant->promotion?->price->minorUnits, 'The unrelated promotion was lost or recomputed');
        self::assertSame('2026-04-01', $croissant->promotion->from->format('Y-m-d'), 'The unrelated promotion moved');
    }

    public function testACompositeMadeOfTheWithheldItemIsWithheldWithIt(): void
    {
        // Where this step meets the last one. Nothing here knows about promotions:
        // the espresso is withheld for a reason of its own, and the cascade rule
        // finds the breakfast set referencing a product that is no longer in the
        // export. Two flags, and the second one is not about promotions at all.
        //
        // The nested copy repeats the two promotions because a nested copy is a
        // full definition and has to agree with the one it copies.
        $result = (new NormaliseMenu())->run(new AlphaPosAdapter(), <<<'JSON'
            {
                "menu": {
                    "products": [
                        {
                            "plu": "000099",
                            "name": "Espresso",
                            "price": "1.10",
                            "promotions": [
                                { "percent": 20, "from": "2026-03-01", "to": "2026-03-10" },
                                { "percent": 10, "from": "2026-03-05", "to": "2026-03-20" }
                            ]
                        },
                        {
                            "plu": "001310",
                            "name": "Breakfast set",
                            "price": "2.42",
                            "components": [
                                {
                                    "plu": "000099",
                                    "name": "Espresso",
                                    "price": "1.10",
                                    "quantity": 1,
                                    "promotions": [
                                        { "percent": 20, "from": "2026-03-01", "to": "2026-03-10" },
                                        { "percent": 10, "from": "2026-03-05", "to": "2026-03-20" }
                                    ]
                                }
                            ]
                        }
                    ]
                }
            }
            JSON);

        self::assertSame([], $result->menu->items, 'Something was published out of an export whose every item is in question');

        self::assertSame(
            [FlagReason::PromotionConflict, FlagReason::ComponentMissing],
            array_map(static fn (Flag $flag): FlagReason => $flag->reason, $result->flags),
            'The composite did not follow its withheld component out of the menu',
        );
    }

    private static function normaliseTheFixture(): MappingResult
    {
        $path = dirname(__DIR__, 2) . '/fixtures/ambiguous/promotion-conflict.json';
        $contents = file_get_contents($path);

        if ($contents === false) {
            self::fail('Could not read the promotion-conflict fixture');
        }

        return (new NormaliseMenu())->run(new AlphaPosAdapter(), $contents);
    }
}
