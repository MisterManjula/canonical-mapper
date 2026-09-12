<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests\Domain;

use CanonicalMapper\Domain\Canonical\Money;
use CanonicalMapper\Domain\InvariantViolated;
use CanonicalMapper\Domain\RoundingRequired;
use PHPUnit\Framework\TestCase;

/**
 * The equivalence guarantee at the smallest scale there is.
 *
 * Two sources spell one price two different ways and have to converge, and if
 * they do not converge here they cannot converge on a whole menu. The third
 * spelling used to be asserted here too; reading punctuation is an adapter's
 * job now, so the decimal cases live in AlphaPosTest and the comma ones arrive
 * with GammaPos.
 *
 * What is left is the arithmetic, which stayed in the domain because it is a
 * rule about what a price means rather than about how a file writes one.
 *
 * The rest of the file is about the rounding policy this project does not have.
 * Every one of those tests asserts that something is refused — the value of "no
 * rounding policy" is entirely in what it declines to do, so an assertion that
 * nothing was invented is the only way to test it.
 */
final class MoneyTest extends TestCase
{
    public function testNetMinorUnitsAndAKnownVatRateBecomeTheGrossPrice(): void
    {
        self::assertSame(
            110,
            Money::fromNetMinorUnitsAndVatPercent(100, 10)->minorUnits,
            'A BetaPos net price was not converted to the gross price the canonical model stores',
        );
    }

    public function testAPriceReadAsGrossAndAPriceComputedFromNetAgree(): void
    {
        // One source states 1.10 and the other states 100 net at 10%. The model
        // holds one number either way, which is the whole of the equivalence
        // guarantee expressed on a single value.
        $stated = Money::fromMinorUnits(110);
        $computed = Money::fromNetMinorUnitsAndVatPercent(100, 10);

        self::assertSame(
            $stated->minorUnits,
            $computed->minorUnits,
            'A stated gross price and a computed one disagree',
        );
    }

    public function testAVatConversionThatWouldNeedRoundingRaisesRatherThanRounds(): void
    {
        // 101 net at 10% is 111.1 cents. Half-up gives 111, truncation gives 110,
        // and there is nothing in this project entitled to choose between them.
        try {
            Money::fromNetMinorUnitsAndVatPercent(101, 10);
        } catch (RoundingRequired $raised) {
            self::assertStringContainsString(
                'rounding policy',
                $raised->operation,
                'The refusal did not say why the price could not be determined',
            );

            return;
        }

        self::fail('A net price that does not convert exactly produced a gross price anyway');
    }

    public function testAnExactPercentageDiscountProducesThePromotionalPrice(): void
    {
        self::assertSame(
            120,
            Money::fromMinorUnits(150)->lessPercentage(20)->minorUnits,
            '20% off 1.50 did not produce 1.20',
        );
    }

    public function testAPercentageDiscountThatWouldNeedRoundingRaisesRatherThanRounds(): void
    {
        $this->expectException(RoundingRequired::class);

        // 15% off 110 is 93.5 cents.
        Money::fromMinorUnits(110)->lessPercentage(15);
    }

    public function testANegativePriceIsRejected(): void
    {
        $this->expectException(InvariantViolated::class);

        Money::fromMinorUnits(-1);
    }
}
