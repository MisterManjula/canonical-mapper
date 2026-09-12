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

    public function testAFieldTheAdapterHasNotBeenTaughtToReadIsRefusedRatherThanDropped(): void
    {
        // Silently ignoring a field is a guess about whether it affected the
        // price. Refusing it is how a promotion reaching an adapter that does not
        // yet read promotions becomes a visible failure rather than a quietly
        // wrong menu.
        $this->expectException(MalformedSource::class);

        (new NormaliseMenu())->run(new AlphaPosAdapter(), <<<'JSON'
            {
                "menu": {
                    "products": [
                        { "plu": "000099", "name": "Espresso", "price": "1.10", "promotions": [] }
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
