<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests\Infrastructure;

use CanonicalMapper\Application\MappingResult;
use CanonicalMapper\Application\NormaliseMenu;
use CanonicalMapper\Domain\Canonical\Item;
use CanonicalMapper\Domain\Resolution\Flag;
use CanonicalMapper\Domain\Resolution\FlagReason;
use CanonicalMapper\Infrastructure\Source\Beta\BetaPosAdapter;
use PHPUnit\Framework\TestCase;

/**
 * The first ambiguity case, end to end, from a file on disk.
 *
 * A net price with a tax code nobody has a rate for. The export is well formed —
 * it parses, every field is the right shape, nothing is missing — and it states
 * one fact this mapper cannot interpret. That combination is the whole subject of
 * the project: the alternative to withholding is not "reject the file", which
 * would be wrong for the two products that are perfectly clear, but "publish a
 * price under a guessed rate", which is wrong in a way nobody notices until a
 * customer is charged it.
 *
 * The unit-level pieces are tested elsewhere: that the code resolves to no rate
 * in BetaPosTest, that a withheld item does not block its neighbours in
 * NormaliseMenuTest. This asserts that the pieces are joined up, which is the one
 * thing neither of them can.
 */
final class TaxBasisTest extends TestCase
{
    public function testTheItemIsAbsentFromTheMenuAndTheRestOfItIsPublished(): void
    {
        $result = self::normaliseTheFixture();

        self::assertSame(
            ['99', '1204'],
            array_map(static fn (Item $item): string => $item->sku->value, $result->menu->items),
            'The item with the unknown tax code was published, or it took the clear ones with it',
        );
    }

    public function testOneFlagNamesIt(): void
    {
        $result = self::normaliseTheFixture();

        self::assertSame(
            [FlagReason::TaxBasisUnknown],
            array_map(static fn (Flag $flag): FlagReason => $flag->reason, $result->flags),
            'The withheld item did not produce exactly one tax-basis flag',
        );
    }

    public function testTheFlagIsSomethingAPersonCanActOn(): void
    {
        $result = self::normaliseTheFixture();
        $flag = $result->flags[0] ?? null;

        if ($flag === null) {
            self::fail('Nothing was withheld, so there is no flag to read');
        }

        // Each of these is a step in the job the flag is asking for: which system
        // to open, which product to search for there, which value to look at, and
        // which SKU it would have had on the canonical side.
        self::assertStringContainsString('BetaPos', $flag->detail, 'The flag did not name the system to open');
        self::assertSame('1100', $flag->sourceProductId, 'The flag did not quote the id as the source wrote it');
        self::assertStringContainsString('V99', $flag->detail, 'The flag did not name the value to verify');
        self::assertSame('1100', $flag->sku?->value, 'The flag lost the canonical SKU');
    }

    public function testTheRemainingPricesAreStillTheOnesTheFileMeant(): void
    {
        // Withholding is supposed to remove one item and change nothing else. A
        // run that quietly recalculated the survivors would pass every assertion
        // above.
        $result = self::normaliseTheFixture();

        self::assertSame(
            [110, 305],
            array_map(static fn (Item $item): int => $item->price->minorUnits, $result->menu->items),
            'Withholding one item disturbed the prices of the others',
        );
    }

    private static function normaliseTheFixture(): MappingResult
    {
        $path = dirname(__DIR__, 2) . '/fixtures/ambiguous/tax-basis.xml';
        $contents = file_get_contents($path);

        if ($contents === false) {
            self::fail('Could not read the tax-basis fixture');
        }

        return (new NormaliseMenu())->run(new BetaPosAdapter(), $contents);
    }
}
