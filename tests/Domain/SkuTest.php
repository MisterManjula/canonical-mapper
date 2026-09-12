<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests\Domain;

use CanonicalMapper\Domain\Canonical\Sku;
use CanonicalMapper\Domain\InvariantViolated;
use PHPUnit\Framework\TestCase;

/**
 * Identity is the other half of the equivalence guarantee: two sources that agree
 * about a price still describe different menus if they disagree about which
 * product it belongs to.
 *
 * What is left here after the formats moved out is the canonical spelling
 * itself. The three source spellings of one PLU are asserted against the
 * adapters that produce them, because that is where the difference between them
 * now lives; these tests are about what every one of those adapters has to hand
 * over.
 */
final class SkuTest extends TestCase
{
    public function testTheCanonicalSpellingIsDigitsWithNoPadding(): void
    {
        self::assertSame('1204', Sku::ofDigits('1204')->value, 'A canonical PLU did not survive unchanged');
        self::assertSame('99', Sku::ofDigits('99')->value, 'A short PLU did not survive unchanged');
    }

    public function testAPaddedSkuIsRejectedRatherThanQuietlyUnpadded(): void
    {
        // This is the rule that makes the spelling canonical rather than merely
        // tolerated. If the model unpadded "001204" itself, an adapter that
        // forgot to would still produce the right answer, and the day one of
        // them normalised differently the equivalence guarantee would fail as a
        // difference in output bytes instead of as an error someone can read.
        $this->expectException(InvariantViolated::class);

        Sku::ofDigits('001204');
    }

    public function testASkuOfZeroIsRejectedRatherThanTreatedAsAProduct(): void
    {
        // A padded field nobody filled in, arriving from an adapter that stripped
        // the padding and found nothing underneath.
        $this->expectException(InvariantViolated::class);

        Sku::ofDigits('0');
    }

    public function testSkusOrderNumericallyAndNotLexicographically(): void
    {
        $short = Sku::ofDigits('99');
        $long = Sku::ofDigits('1204');

        // strcmp would put "1204" first, so the order of the canonical output
        // would depend on how many digits a source happened to write — the exact
        // difference the canonical spelling exists to erase.
        self::assertSame(-1, Sku::compare($short, $long), 'SKU 99 did not sort before SKU 1204');
        self::assertSame(1, Sku::compare($long, $short), 'SKU ordering is not symmetric');
        self::assertSame(0, Sku::compare($short, $short), 'A SKU did not compare equal to itself');
    }

    public function testAPluThatIsNotDigitsIsRejected(): void
    {
        $this->expectException(InvariantViolated::class);

        Sku::ofDigits('ESPRESSO');
    }
}
