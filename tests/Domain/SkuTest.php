<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests\Domain;

use CanonicalMapper\Application\Port\MalformedSource;
use CanonicalMapper\Domain\Canonical\Sku;
use PHPUnit\Framework\TestCase;

/**
 * Identity is the other half of the equivalence guarantee: two sources that agree
 * about a price still describe different menus if they disagree about which
 * product it belongs to.
 */
final class SkuTest extends TestCase
{
    public function testTheThreeSourceSpellingsOfOnePluProduceOneSku(): void
    {
        self::assertSame('1204', Sku::fromPaddedString('001204')->value, 'AlphaPos padding survived normalisation');
        self::assertSame('1204', Sku::fromAttribute('1204')->value, 'A BetaPos code did not normalise to itself');
        self::assertSame('1204', Sku::fromPrefixedColumn('P-1204')->value, 'The GammaPos prefix survived normalisation');
    }

    public function testAGammaColumnWithoutItsPrefixIsRejectedRatherThanGuessed(): void
    {
        // Accepting this would cost one line and would quietly weaken what the
        // equivalence test proves: a parser that takes any spelling has not
        // reconciled three formats, it has stopped distinguishing them.
        $this->expectException(MalformedSource::class);

        Sku::fromPrefixedColumn('1204');
    }

    public function testSkusOrderNumericallyAndNotLexicographically(): void
    {
        $short = Sku::fromAttribute('99');
        $long = Sku::fromAttribute('1204');

        // strcmp would put "1204" first, so the order of the canonical output
        // would depend on how many digits a source happened to write — the exact
        // difference normalising the SKU exists to erase.
        self::assertSame(-1, Sku::compare($short, $long), 'SKU 99 did not sort before SKU 1204');
        self::assertSame(1, Sku::compare($long, $short), 'SKU ordering is not symmetric');
        self::assertSame(0, Sku::compare($short, $short), 'A SKU did not compare equal to itself');
    }

    public function testAPaddedFieldNobodyFilledInIsRejectedRatherThanReadAsZero(): void
    {
        $this->expectException(MalformedSource::class);

        Sku::fromPaddedString('000000');
    }

    public function testAPluThatIsNotDigitsIsRejected(): void
    {
        $this->expectException(MalformedSource::class);

        Sku::fromAttribute('ESPRESSO');
    }
}
