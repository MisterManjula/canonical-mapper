<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests\Infrastructure;

use CanonicalMapper\Application\NormaliseMenu;
use CanonicalMapper\Application\Port\MalformedSource;
use CanonicalMapper\Domain\Canonical\ComponentRef;
use CanonicalMapper\Domain\Canonical\Item;
use CanonicalMapper\Infrastructure\Output\CanonicalJsonWriter;
use CanonicalMapper\Infrastructure\Source\Alpha\AlphaPosAdapter;
use PHPUnit\Framework\TestCase;

/**
 * The first source against the equivalence fixture.
 *
 * The assertion is on bytes, not on an object graph. Comparing the model to
 * itself would pass while the output was still wrong, and the output is what a
 * consumer sees; a byte comparison also means the fixture doubles as
 * documentation of the canonical format that cannot go stale.
 *
 * Two more sources have to reach this same file. That is why expected.json is
 * read here rather than built in the test: three tests asserting against one file
 * is the whole claim, and three tests each constructing their own expectation
 * would be three claims that happen to agree.
 *
 * The zero padding and the decimal point are asserted here rather than in the
 * domain tests, because this adapter is where they are now known about. They are
 * the same assertions that used to sit on Sku and Money, addressed to the layer
 * that earns them.
 */
final class AlphaPosTest extends TestCase
{
    public function testTheAlphaPosExportProducesTheCanonicalMenu(): void
    {
        $result = (new NormaliseMenu())->run(new AlphaPosAdapter(), self::fixture('equivalence/alpha.json'));

        self::assertSame(
            self::fixture('equivalence/expected.json'),
            (new CanonicalJsonWriter())->write($result->menu),
            'The AlphaPos export did not produce the canonical menu byte for byte',
        );
    }

    public function testNothingIsWithheldFromTheEquivalenceFixture(): void
    {
        $result = (new NormaliseMenu())->run(new AlphaPosAdapter(), self::fixture('equivalence/alpha.json'));

        // The equivalence fixture is chosen to be unambiguous in all three
        // formats. A flag raised here would mean the fixture had stopped being the
        // thing the equivalence claim is about, and the byte comparison above
        // would still pass while it happened.
        self::assertSame([], $result->flags, 'The equivalence fixture withheld something');
    }

    public function testAProductNestedInsideASetIsPublishedOnceAndReferencedByIt(): void
    {
        $result = (new NormaliseMenu())->run(new AlphaPosAdapter(), self::fixture('equivalence/alpha.json'));

        // Espresso is written twice in the export: once on its own and once inside
        // the breakfast set. The canonical menu is flat, so it has to appear
        // exactly once, and the set has to point at it rather than contain it.
        $skus = array_map(static fn (Item $item): string => $item->sku->value, $result->menu->items);

        self::assertSame(['99', '1100', '1204', '1310'], $skus, 'A nested product was duplicated or lost');

        $set = null;

        // Found by SKU rather than by position. Indexing into the list would make
        // this test depend on the ordering the assertion above already pins down,
        // and PHPStan is right to point out that a list has no guaranteed offset 3.
        foreach ($result->menu->items as $item) {
            if ($item->sku->value === '1310') {
                $set = $item;
            }
        }

        if ($set === null) {
            self::fail('The breakfast set is not in the canonical menu');
        }

        self::assertSame(
            [['99', 1], ['1100', 1]],
            array_map(
                static fn (ComponentRef $component): array => [$component->sku->value, $component->quantity],
                $set->components,
            ),
            'The set did not reference the products that were nested inside it',
        );
    }

    public function testThePaddingIsStrippedFromThePlu(): void
    {
        $item = self::onlyItem(<<<'JSON'
            {
                "menu": {
                    "products": [
                        { "plu": "001204", "name": "Breakfast set", "price": "1.10" }
                    ]
                }
            }
            JSON);

        // The canonical spelling has no padding, and reaching it is this
        // adapter's job: the domain refuses "001204" outright. BetaPos writes the
        // same product as code="1204" and does nothing at all, which is what makes
        // the two converge.
        self::assertSame('1204', $item->sku->value, 'The AlphaPos padding survived into the canonical model');
    }

    public function testTwoSpellingsOfOnePluInOneExportAreOneProduct(): void
    {
        $result = (new NormaliseMenu())->run(new AlphaPosAdapter(), <<<'JSON'
            {
                "menu": {
                    "products": [
                        { "plu": "001204", "name": "Breakfast set", "price": "1.10" },
                        { "plu": "1204", "name": "Breakfast set", "price": "1.10" }
                    ]
                }
            }
            JSON);

        // Both rows normalise to one SKU, so this is one product described twice
        // and not two products. They agree, so there is nothing to refuse; a
        // disagreement is the case the test below covers.
        self::assertCount(1, $result->menu->items, 'Two spellings of one PLU produced two products');
    }

    public function testAPluOfNothingButZeroesIsRefusedRatherThanReadAsZero(): void
    {
        // Stripping the padding leaves nothing behind. Reading that as SKU 0 would
        // invent a product out of a field nobody filled in.
        $this->expectException(MalformedSource::class);

        (new NormaliseMenu())->run(new AlphaPosAdapter(), <<<'JSON'
            {
                "menu": {
                    "products": [
                        { "plu": "000000", "name": "Espresso", "price": "1.10" }
                    ]
                }
            }
            JSON);
    }

    public function testAGrossDecimalStringBecomesTheNumberOfCentsItNames(): void
    {
        $item = self::onlyItem(<<<'JSON'
            {
                "menu": {
                    "products": [
                        { "plu": "000099", "name": "Espresso", "price": "1.10" }
                    ]
                }
            }
            JSON);

        self::assertSame(110, $item->price->minorUnits, 'An AlphaPos price did not become the number of cents it names');
    }

    public function testAPriceWithThreeDecimalsIsRefusedInsteadOfRounded(): void
    {
        // Three decimals are the only way a decimal string could require a
        // rounding policy. Refusing them is what makes the absence of one
        // structural rather than a matter of which fixtures were chosen.
        $this->expectException(MalformedSource::class);

        (new NormaliseMenu())->run(new AlphaPosAdapter(), <<<'JSON'
            {
                "menu": {
                    "products": [
                        { "plu": "000099", "name": "Espresso", "price": "1.505" }
                    ]
                }
            }
            JSON);
    }

    public function testAPriceWithOneDecimalIsRefusedInsteadOfPadded(): void
    {
        // "1.5" almost certainly means 1.50, and reading it that way would be a
        // guess. A source has one spelling for a price; a second one is a broken
        // export, and the point of this project is to say so.
        $this->expectException(MalformedSource::class);

        (new NormaliseMenu())->run(new AlphaPosAdapter(), <<<'JSON'
            {
                "menu": {
                    "products": [
                        { "plu": "000099", "name": "Espresso", "price": "1.5" }
                    ]
                }
            }
            JSON);
    }

    public function testADefinitionThatContradictsAnEarlierOneIsRefused(): void
    {
        // AlphaPos repeats a child's full definition inside its parent, so the two
        // copies can disagree. Two prices for one product with nothing saying
        // which is current is not an ambiguity to ask a human about — it is a file
        // that contradicts itself.
        $this->expectException(MalformedSource::class);

        (new NormaliseMenu())->run(new AlphaPosAdapter(), <<<'JSON'
            {
                "menu": {
                    "products": [
                        { "plu": "000099", "name": "Espresso", "price": "1.10" },
                        {
                            "plu": "001310",
                            "name": "Breakfast set",
                            "price": "2.42",
                            "components": [
                                { "plu": "000099", "name": "Espresso", "price": "1.30", "quantity": 1 }
                            ]
                        }
                    ]
                }
            }
            JSON);
    }

    public function testAPercentageOffIsStoredAsThePriceItProduces(): void
    {
        // AlphaPos says "20% off 1.10". The canonical model says 88, and says
        // nothing about 20 — the mechanism is gone by the time anything is
        // published, which is what lets the source stating an absolute price
        // reach the same menu.
        $item = self::onlyItem(<<<'JSON'
            {
                "menu": {
                    "products": [
                        {
                            "plu": "000099",
                            "name": "Espresso",
                            "price": "1.10",
                            "promotions": [
                                { "percent": 20, "from": "2026-03-01", "to": "2026-03-10" }
                            ]
                        }
                    ]
                }
            }
            JSON);

        self::assertSame(110, $item->price->minorUnits, 'The usual price was replaced by the promotional one');
        // The first of these is asked nullsafe and the rest are not: matching 88
        // is already proof that there is a promotion there, and PHPStan knows it.
        self::assertSame(88, $item->promotion?->price->minorUnits, 'The percentage was not applied to the price');
        self::assertSame('2026-03-01', $item->promotion->from->format('Y-m-d'), 'The promotion did not start when the file says');
        self::assertSame('2026-03-10', $item->promotion->to->format('Y-m-d'), 'The promotion did not end when the file says');
    }

    public function testAPromotionsListWithNothingInItMeansNoPromotion(): void
    {
        // Unlike "components", where an empty list contradicts the key that
        // introduced it, a product with no promotions is the ordinary case and a
        // file is entitled to say so out loud.
        self::assertNull(
            self::onlyItem(<<<'JSON'
                {
                    "menu": {
                        "products": [
                            { "plu": "000099", "name": "Espresso", "price": "1.10", "promotions": [] }
                        ]
                    }
                }
                JSON)->promotion,
            'An empty promotions list produced a promotion',
        );
    }

    public function testANestedCopyThatDisagreesAboutPromotionsIsRefused(): void
    {
        // The reason a nested copy may carry promotions at all. If it could leave
        // them out, this export would be legal, and whichever copy the file
        // happened to state first would decide whether the espresso is on offer —
        // an offer disappearing from a menu with nobody told.
        $this->expectException(MalformedSource::class);

        (new NormaliseMenu())->run(new AlphaPosAdapter(), <<<'JSON'
            {
                "menu": {
                    "products": [
                        {
                            "plu": "001310",
                            "name": "Breakfast set",
                            "price": "2.42",
                            "components": [
                                { "plu": "000099", "name": "Espresso", "price": "1.10", "quantity": 1 }
                            ]
                        },
                        {
                            "plu": "000099",
                            "name": "Espresso",
                            "price": "1.10",
                            "promotions": [
                                { "percent": 20, "from": "2026-03-01", "to": "2026-03-10" }
                            ]
                        }
                    ]
                }
            }
            JSON);
    }

    public function testAPercentageThatIsNotAPercentageIsRefused(): void
    {
        // Above a hundred would produce a negative price and below zero would
        // raise one. Both are refused here rather than at the canonical boundary,
        // because "a percentage" is a fact about this format's field.
        $this->expectException(MalformedSource::class);

        (new NormaliseMenu())->run(new AlphaPosAdapter(), <<<'JSON'
            {
                "menu": {
                    "products": [
                        {
                            "plu": "000099",
                            "name": "Espresso",
                            "price": "1.10",
                            "promotions": [
                                { "percent": 120, "from": "2026-03-01", "to": "2026-03-10" }
                            ]
                        }
                    ]
                }
            }
            JSON);
    }

    public function testAPromotionCarryingAFieldTheAdapterDoesNotKnowIsRefused(): void
    {
        // An absolute price is how the *other* source states a promotion. Reading
        // it here would mean this adapter quietly accepting a second spelling and
        // choosing between two answers, which is the tolerance the whole
        // equivalence claim is weakened by.
        $this->expectException(MalformedSource::class);

        (new NormaliseMenu())->run(new AlphaPosAdapter(), <<<'JSON'
            {
                "menu": {
                    "products": [
                        {
                            "plu": "000099",
                            "name": "Espresso",
                            "price": "1.10",
                            "promotions": [
                                { "percent": 20, "price": "0.88", "from": "2026-03-01", "to": "2026-03-10" }
                            ]
                        }
                    ]
                }
            }
            JSON);
    }

    public function testAFieldTheAdapterHasNotBeenTaughtToReadIsRefusedRatherThanDropped(): void
    {
        // Silently ignoring a field is a guess about whether it affected the
        // price. This used to be written with "promotions", back when that was a
        // field this adapter had not been taught to read — and the refusal is
        // what made it safe for that to be true for three steps: an export
        // carrying one failed loudly instead of producing a quietly wrong menu.
        // The field had to be changed here when promotions arrived, which is the
        // check doing its job rather than the test going stale.
        $this->expectException(MalformedSource::class);

        (new NormaliseMenu())->run(new AlphaPosAdapter(), <<<'JSON'
            {
                "menu": {
                    "products": [
                        { "plu": "000099", "name": "Espresso", "price": "1.10", "channel": "kiosk" }
                    ]
                }
            }
            JSON);
    }

    public function testAnExportThatIsNotJsonIsRefused(): void
    {
        $this->expectException(MalformedSource::class);

        (new NormaliseMenu())->run(new AlphaPosAdapter(), 'PLU;NAME;PRICE');
    }

    /**
     * The single item of a one-product export.
     *
     * Written as a search rather than an index because a list has no guaranteed
     * offset 0, which PHPStan is right about and which a test asserting on
     * $items[0] would only discover once the export had more than one product.
     */
    private static function onlyItem(string $json): Item
    {
        $result = (new NormaliseMenu())->run(new AlphaPosAdapter(), $json);

        foreach ($result->menu->items as $item) {
            return $item;
        }

        self::fail('The export produced no items');
    }

    private static function fixture(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/fixtures/' . $path);

        if ($contents === false) {
            self::fail(sprintf('Could not read the fixture %s', $path));
        }

        // A CRLF checkout would make the byte comparison fail with an unreadable
        // diff. .gitattributes pins LF for these files; this is the check that
        // names the cause if that ever stops working.
        self::assertStringNotContainsString(
            "\r\n",
            $contents,
            sprintf('The fixture %s has CRLF line endings; check .gitattributes', $path),
        );

        return $contents;
    }
}
