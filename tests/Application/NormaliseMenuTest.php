<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests\Application;

use CanonicalMapper\Application\NormaliseMenu;
use CanonicalMapper\Application\Port\MalformedSource;
use CanonicalMapper\Domain\Canonical\Item;
use CanonicalMapper\Domain\Canonical\Money;
use CanonicalMapper\Domain\Canonical\Sku;
use CanonicalMapper\Domain\Resolution\Flag;
use CanonicalMapper\Domain\Resolution\FlagReason;
use CanonicalMapper\Domain\Resolution\Resolved;
use CanonicalMapper\Domain\Resolution\SourceName;
use CanonicalMapper\Domain\Resolution\Unresolved;
use PHPUnit\Framework\TestCase;

/**
 * Containment, tested where the decision is made and without a file in sight.
 *
 * Every one of these could be written against a real export, and two of them are
 * elsewhere. They are here as well because the guarantee is not "BetaPos contains
 * its failures" but "the use case does", and a test that reaches the use case
 * through an XML parser cannot say the second. It also fails for the wrong
 * reasons: a broken parser would turn every one of these red and none of them
 * would be about containment.
 *
 * This is what the port is for. The adapter is a hand-written double returning
 * resolutions decided in advance, so the arrangement of a test is the arrangement
 * of the case it describes.
 */
final class NormaliseMenuTest extends TestCase
{
    public function testEverythingResolvedProducesTheWholeMenuAndNoFlags(): void
    {
        $result = (new NormaliseMenu())->run(
            new FixedAdapter([Resolved::of(self::item('99', 'Espresso')), Resolved::of(self::item('1100', 'Croissant'))]),
            'ignored',
        );

        self::assertSame(['99', '1100'], self::skus($result->menu->items), 'A resolved item did not reach the menu');
        self::assertSame([], $result->flags, 'A run with nothing ambiguous in it raised a flag');
    }

    public function testAWithheldItemIsAbsentFromTheMenuAndPresentInTheFlags(): void
    {
        $result = (new NormaliseMenu())->run(
            new FixedAdapter([Unresolved::because(self::flag('1100'))]),
            'ignored',
        );

        // Both halves matter. An item that vanished without a flag would be data
        // loss dressed up as a guarantee, and a flag without the item being
        // withheld would be a warning next to a price nobody can stand behind.
        self::assertSame([], self::skus($result->menu->items), 'The unresolvable item was published anyway');
        self::assertSame(
            [FlagReason::TaxBasisUnknown],
            array_map(static fn (Flag $flag): FlagReason => $flag->reason, $result->flags),
            'The withheld item did not produce exactly one flag',
        );
    }

    public function testAWithheldItemDoesNotBlockTheItemsAroundIt(): void
    {
        // The whole of containment: one `continue` in the use case, and the reason
        // an ambiguous row in a thousand-line export costs one product rather than
        // the import.
        $result = (new NormaliseMenu())->run(
            new FixedAdapter([
                Resolved::of(self::item('99', 'Espresso')),
                Unresolved::because(self::flag('1100')),
                Resolved::of(self::item('1204', 'Orange juice')),
            ]),
            'ignored',
        );

        self::assertSame(['99', '1204'], self::skus($result->menu->items), 'A withheld item took its neighbours with it');
        self::assertCount(1, $result->flags, 'Containment cost more than the one item it was supposed to');
    }

    public function testAnExportWhereNothingResolvesIsAnEmptyMenuRatherThanAFailure(): void
    {
        // Not an exception. Nothing went wrong: every item was understood and every
        // one of them needs a person. The caller learns that from the flags, and
        // the CLI turns it into an exit code — refusing the run here would make the
        // difference between "unreadable" and "needs a decision" disappear at the
        // worst moment.
        $result = (new NormaliseMenu())->run(
            new FixedAdapter([Unresolved::because(self::flag('99')), Unresolved::because(self::flag('1100'))]),
            'ignored',
        );

        self::assertSame([], self::skus($result->menu->items), 'An empty run produced items from somewhere');
        self::assertCount(2, $result->flags, 'Two withheld items did not produce two flags');
    }

    public function testTheMenuIsInCanonicalOrderWhateverOrderTheAdapterReportedIn(): void
    {
        // The use case does not sort; the canonical model does. This is the
        // assertion that the use case does not undo it — and that a source is free
        // to report rows in whatever order its file happens to use.
        $result = (new NormaliseMenu())->run(
            new FixedAdapter([
                Resolved::of(self::item('1204', 'Orange juice')),
                Resolved::of(self::item('99', 'Espresso')),
                Resolved::of(self::item('1100', 'Croissant')),
            ]),
            'ignored',
        );

        self::assertSame(['99', '1100', '1204'], self::skus($result->menu->items), 'The menu was not in canonical order');
    }

    public function testTwoItemsWithTheSameSkuAreReportedAsABrokenExport(): void
    {
        // The one invariant an adapter cannot check for itself, because it is only
        // visible once the whole export has been read. The domain refuses the menu
        // in its own vocabulary and this layer translates, because MalformedSource
        // is a statement about a file and the domain does not know there was one.
        $this->expectException(MalformedSource::class);

        (new NormaliseMenu())->run(
            new FixedAdapter([
                Resolved::of(self::item('99', 'Espresso')),
                Resolved::of(self::item('99', 'Espresso again')),
            ]),
            'ignored',
        );
    }

    private static function item(string $digits, string $name): Item
    {
        return Item::simple(Sku::ofDigits($digits), $name, Money::fromMinorUnits(110));
    }

    private static function flag(string $sourceProductId): Flag
    {
        return Flag::taxBasisUnknown(SourceName::of('TestPos'), $sourceProductId, null, 'V99');
    }

    /**
     * @param list<Item> $items
     *
     * @return list<string>
     */
    private static function skus(array $items): array
    {
        return array_map(static fn (Item $item): string => $item->sku->value, $items);
    }
}
