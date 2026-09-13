<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests\Domain;

use CanonicalMapper\Domain\Canonical\Money;
use CanonicalMapper\Domain\Canonical\Promotion;
use CanonicalMapper\Domain\InvariantViolated;
use PHPUnit\Framework\TestCase;

/**
 * The canonical model stores the resulting price and not the mechanism that
 * produced it, and this is where that claim is cheapest to check.
 *
 * One source states "20% off" and another states "1.76 from the first to the
 * tenth". They are the same offer to a customer, and if the canonical model kept
 * the mechanism they would serialise differently — a difference in bookkeeping
 * presented as a difference in the menu. The first test is the whole of ADR-002
 * in four lines; the adapters prove it again from real files, which is a
 * different and more expensive claim.
 *
 * The rest is about dates, because a promotion is the only part of this model
 * with any, and dates are where a permissive parser does its damage quietly.
 */
final class PromotionTest extends TestCase
{
    public function testAPercentageAndAPriceReachTheSameCanonicalPromotion(): void
    {
        $fromPercentage = Promotion::percentageOff(Money::fromMinorUnits(220), 20, '2026-03-01', '2026-03-10');
        $fromPrice = Promotion::atPrice(Money::fromMinorUnits(176), '2026-03-01', '2026-03-10');

        self::assertTrue(
            $fromPercentage->equals($fromPrice),
            'Two sources stating the same offer by different mechanisms produced different promotions',
        );
    }

    public function testBothEndsOfTheRangeAreIncluded(): void
    {
        // Inclusive at both ends, and the reason it is asserted rather than
        // assumed: "to" is the last day the offer runs, not the day it stops. A
        // half-open range would be defensible and is not what this model means,
        // and the two readings differ by exactly one day of discounted coffee.
        $promotion = self::over('2026-03-01', '2026-03-10');
        $lastDay = self::over('2026-03-10', '2026-03-31');

        self::assertTrue($promotion->overlaps($lastDay), 'The last day of a promotion was treated as outside it');
    }

    public function testTwoPromotionsOnAdjacentDaysDoNotOverlap(): void
    {
        self::assertFalse(
            self::over('2026-03-01', '2026-03-10')->overlaps(self::over('2026-03-11', '2026-03-20')),
            'A promotion that ends the day before another starts was read as overlapping it',
        );
    }

    public function testOverlappingIsAskedTheSameWayRoundEither(): void
    {
        $early = self::over('2026-03-01', '2026-03-10');
        $late = self::over('2026-03-05', '2026-03-20');

        // A source states promotions in whatever order it likes, so an answer
        // that depended on which one was asked would make the conflict rule
        // depend on the order of a JSON list.
        self::assertTrue($early->overlaps($late), 'Two overlapping promotions did not overlap');
        self::assertTrue($late->overlaps($early), 'Overlapping stopped being true when asked the other way round');
    }

    public function testTheSameOfferWrittenTwiceIsOnePromotion(): void
    {
        self::assertTrue(
            self::over('2026-03-01', '2026-03-10')->equals(self::over('2026-03-01', '2026-03-10')),
            'One offer stated twice was read as two different offers',
        );
    }

    public function testOnePriceOverTwoRangesIsTwoOffers(): void
    {
        // The distinction the conflict rule rests on. These two agree about the
        // price and disagree about when it applies, and merging them would
        // publish a range neither of them states.
        self::assertFalse(
            self::over('2026-03-01', '2026-03-10')->equals(self::over('2026-03-05', '2026-03-20')),
            'Two offers at one price over different ranges were treated as the same offer',
        );
    }

    public function testAPromotionThatEndsBeforeItStartsIsRefused(): void
    {
        $this->expectException(InvariantViolated::class);

        self::over('2026-03-10', '2026-03-01');
    }

    public function testADateThatIsNotARealDayIsRefusedRatherThanMoved(): void
    {
        // The 30th of February parses perfectly well and comes back as the 2nd of
        // March, so a typo in an export would silently move a promotion instead of
        // being reported. This is the round trip that catches it.
        $this->expectException(InvariantViolated::class);

        self::over('2026-02-30', '2026-03-10');
    }

    public function testADateThatIsNotIsoIsRefused(): void
    {
        // One spelling for a date, for the reason each adapter has one spelling
        // for a price: a second accepted format is a difference between sources
        // that the canonical model would then have to absorb silently.
        $this->expectException(InvariantViolated::class);

        self::over('01/03/2026', '2026-03-10');
    }

    /**
     * @throws InvariantViolated
     */
    private static function over(string $from, string $to): Promotion
    {
        return Promotion::atPrice(Money::fromMinorUnits(176), $from, $to);
    }
}
