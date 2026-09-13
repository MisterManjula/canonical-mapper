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
 * Both halves of withholding in one run, on one file.
 *
 * Containment and cascade pull in opposite directions and the guarantee is that
 * both hold at once: an ambiguous item costs exactly itself and whatever cannot
 * be described without it, and nothing else. A file is the only place that can
 * be seen, because containment needs bystanders and cascade needs a component
 * that was genuinely withheld rather than merely absent.
 *
 * Which is why the fixture is a BetaPos export. Cascade from a *withheld*
 * component needs an ambiguity to withhold it with, and the unknown tax code is
 * the ambiguity this project has; the format that keeps net prices is therefore
 * the only one that can state this case in one file. The never-described variant
 * is the GammaPos fixture next door, and the rule does not distinguish them.
 *
 * The count is the assertion to read first: two flags, not one. A composite that
 * vanished under its component's flag would satisfy every other assertion here.
 */
final class ContainmentAndCascadeTest extends TestCase
{
    public function testTheUnrelatedItemsArePublishedAndTheTwoOthersAreNot(): void
    {
        $result = self::normaliseTheFixture();

        self::assertSame(
            ['99', '1204'],
            array_map(static fn (Item $item): string => $item->sku->value, $result->menu->items),
            'The withheld pair took the clear items with it, or one of the pair was published',
        );
    }

    public function testTheCompositeIsWithheldWithAFlagOfItsOwn(): void
    {
        $result = self::normaliseTheFixture();

        // Two flags. The croissant's says a tax code needs a rate; the set's says
        // the set cannot be described. They go to the same person and are not the
        // same job — resolving the first is what makes the second go away, and a
        // report that only carried the first would leave the set missing from the
        // menu with nothing to explain why.
        self::assertSame(
            [FlagReason::TaxBasisUnknown, FlagReason::ComponentMissing],
            array_map(static fn (Flag $flag): FlagReason => $flag->reason, $result->flags),
            'Withholding a component did not raise a second flag naming the composite',
        );
    }

    public function testTheSecondFlagNamesTheCompositeAndTheComponentItLost(): void
    {
        $result = self::normaliseTheFixture();
        $flag = $result->flags[1] ?? null;

        if ($flag === null) {
            self::fail('The composite was not withheld, so there is no second flag to read');
        }

        self::assertSame('1310', $flag->sku?->value, 'The cascade flag is filed against something other than the set');
        self::assertStringContainsString('1100', $flag->detail, 'The cascade flag did not name the component the set lost');
        self::assertStringContainsString('BetaPos', $flag->detail, 'The cascade flag did not name the system to open');
    }

    public function testTheSurvivingPricesAreStillTheOnesTheFileMeant(): void
    {
        // Withholding two items is supposed to change nothing about the other
        // two. A run that quietly recalculated them would pass everything above.
        $result = self::normaliseTheFixture();

        self::assertSame(
            [110, 305],
            array_map(static fn (Item $item): int => $item->price->minorUnits, $result->menu->items),
            'Withholding a component and its composite disturbed the prices of the rest',
        );
    }

    private static function normaliseTheFixture(): MappingResult
    {
        $path = dirname(__DIR__, 2) . '/fixtures/ambiguous/containment-and-cascade.xml';
        $contents = file_get_contents($path);

        if ($contents === false) {
            self::fail('Could not read the containment-and-cascade fixture');
        }

        return (new NormaliseMenu())->run(new BetaPosAdapter(), $contents);
    }
}
