<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests\Domain;

use CanonicalMapper\Domain\Canonical\Money;
use CanonicalMapper\Domain\Canonical\Sku;
use CanonicalMapper\Domain\Resolution\Flag;
use CanonicalMapper\Domain\Resolution\FlagReason;
use CanonicalMapper\Domain\Resolution\Resolved;
use CanonicalMapper\Domain\Resolution\SourceSystem;
use CanonicalMapper\Domain\Resolution\Unresolved;
use PHPUnit\Framework\TestCase;

/**
 * What a Resolution carries, and how it has to be opened.
 *
 * That an Unresolved value cannot be unwrapped is a separate claim, asserted by
 * ResolutionCannotBeBypassedTest, because it is not observable at runtime — the
 * code that would demonstrate it cannot be written.
 *
 * Everything here goes through resolvePrice(), which is shaped like a real
 * adapter method, rather than constructing a Resolved or an Unresolved
 * directly. That is not ceremony. Building the concrete type makes the
 * static type concrete too, so the instanceof that follows is always true and the
 * test demonstrates nothing; PHPStan says so in as many words. Only a value whose
 * declared type is the union has a branch to handle, so only that shape is worth
 * testing.
 */
final class ResolutionTest extends TestCase
{
    public function testAKnownTaxBasisResolvesToAGrossPrice(): void
    {
        $resolution = self::resolvePrice('1204', 100, 'V10');

        if (!$resolution instanceof Resolved) {
            self::fail('A known VAT code did not resolve');
        }

        self::assertSame(110, $resolution->value->minorUnits, 'The resolved value was not the gross price');
    }

    public function testAnUnknownTaxBasisResolvesToAFlagSayingWhatToVerify(): void
    {
        $resolution = self::resolvePrice('1204', 100, 'V99');

        // Narrowed with instanceof and self::fail() rather than assertInstanceOf,
        // which would leave $resolution typed as the union and make ->flag an
        // undefined property under PHPStan. phpstan/phpstan-phpunit would paper
        // over that, and is left out for exactly this reason: the tests handle the
        // branch the way the adapters must.
        if (!$resolution instanceof Unresolved) {
            self::fail('An unknown VAT code produced a price anyway');
        }

        self::assertSame(
            FlagReason::TaxBasisUnknown,
            $resolution->flag->reason,
            'The flag did not carry the reason it was raised for',
        );
    }

    public function testAFlagNamesTheSystemTheProductAndTheValueToCheck(): void
    {
        $flag = Flag::taxBasisUnknown(SourceSystem::Beta, '001204', Sku::ofDigits('1204'), 'V99');

        // A flag is a work item. Each of these is something the person acting on
        // it needs: the system to open, the identifier to paste into its search
        // box, and the value to look at once they are there.
        self::assertSame('001204', $flag->sourceProductId, 'The flag did not quote the id as the source wrote it');
        self::assertStringContainsString('BetaPos', $flag->detail, 'The flag did not name the system to open');
        self::assertStringContainsString('V99', $flag->detail, 'The flag did not name the value to verify');
    }

    public function testTheSourceProductIdIsTheRawSpellingAndNotTheNormalisedOne(): void
    {
        $flag = Flag::componentMissing(SourceSystem::Gamma, 'P-1204', Sku::ofDigits('1204'), 'P-1210');

        // Searching GammaPos for "1204" may well find nothing: the prefix is how
        // that system spells the identifier, and the flag is read over there.
        self::assertSame('P-1204', $flag->sourceProductId, 'The flag normalised an id that has to stay searchable');
        self::assertSame('1204', $flag->sku?->value, 'The flag lost the canonical SKU');
    }

    /**
     * The shape every adapter method takes, in miniature: declared as the union,
     * resolving where it can and flagging where it cannot.
     *
     * @return Resolved<Money>|Unresolved
     */
    private static function resolvePrice(string $sourceProductId, int $netMinorUnits, string $vatCode): Resolved|Unresolved
    {
        $rate = match ($vatCode) {
            'V10' => 10,
            'V22' => 22,
            default => null,
        };

        if ($rate === null) {
            return Unresolved::because(
                Flag::taxBasisUnknown(SourceSystem::Beta, $sourceProductId, null, $vatCode),
            );
        }

        return Resolved::of(Money::fromNetMinorUnitsAndVatPercent($netMinorUnits, $rate));
    }
}
