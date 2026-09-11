<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests;

use CanonicalMapper\Canonical\Money;
use CanonicalMapper\MalformedSource;
use CanonicalMapper\RoundingRequired;
use PHPUnit\Framework\TestCase;

/**
 * The equivalence guarantee at the smallest scale there is.
 *
 * Three sources spell one price three ways, and if they do not converge here they
 * cannot converge on a whole menu. These tests are therefore the cross-source
 * equivalence test in miniature, which is why they exist even though the README
 * does not list them.
 *
 * The rest of the file is about the rounding policy this project does not have.
 * Every one of those tests asserts that something is refused — the value of "no
 * rounding policy" is entirely in what it declines to do, so an assertion that
 * nothing was invented is the only way to test it.
 */
final class MoneyTest extends TestCase
{
    public function testAGrossDecimalStringBecomesMinorUnits(): void
    {
        self::assertSame(
            110,
            Money::fromDecimalString('1.10')->minorUnits,
            'An AlphaPos price did not become the number of cents it names',
        );
    }

    public function testNetMinorUnitsAndAKnownVatRateBecomeTheGrossPrice(): void
    {
        self::assertSame(
            110,
            Money::fromNetMinorUnitsAndVatPercent(100, 10)->minorUnits,
            'A BetaPos net price was not converted to the gross price the canonical model stores',
        );
    }

    public function testTheThreeSourceSpellingsOfOnePriceProduceOneMoney(): void
    {
        $alpha = Money::fromDecimalString('1.10');
        $beta = Money::fromNetMinorUnitsAndVatPercent(100, 10);
        $gamma = Money::fromCommaDecimalString('1,10');

        self::assertSame($alpha->minorUnits, $beta->minorUnits, 'AlphaPos and BetaPos disagree about one price');
        self::assertSame($alpha->minorUnits, $gamma->minorUnits, 'AlphaPos and GammaPos disagree about one price');
    }

    public function testAPriceWithThreeDecimalsIsRejectedInsteadOfRounded(): void
    {
        // Three decimals are the only way a decimal string could require a
        // rounding policy. Refusing them is what makes the absence of one
        // structural rather than a matter of which fixtures were chosen.
        $this->expectException(MalformedSource::class);

        Money::fromDecimalString('1.505');
    }

    public function testAPriceWithOneDecimalIsRejectedInsteadOfPadded(): void
    {
        // "1.5" almost certainly means 1.50, and reading it that way would be a
        // guess. A source has one spelling for a price; a second one is a broken
        // export, and the point of this project is to say so.
        $this->expectException(MalformedSource::class);

        Money::fromDecimalString('1.5');
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
            Money::fromDecimalString('1.50')->lessPercentage(20)->minorUnits,
            '20% off 1.50 did not produce 1.20',
        );
    }

    public function testAPercentageDiscountThatWouldNeedRoundingRaisesRatherThanRounds(): void
    {
        $this->expectException(RoundingRequired::class);

        // 15% off 110 is 93.5 cents.
        Money::fromDecimalString('1.10')->lessPercentage(15);
    }

    public function testANegativePriceIsRejected(): void
    {
        $this->expectException(MalformedSource::class);

        Money::fromMinorUnits(-1);
    }
}
