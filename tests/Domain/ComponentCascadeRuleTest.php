<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests\Domain;

use CanonicalMapper\Domain\Canonical\ComponentRef;
use CanonicalMapper\Domain\Canonical\Item;
use CanonicalMapper\Domain\Canonical\Money;
use CanonicalMapper\Domain\Canonical\Sku;
use CanonicalMapper\Domain\Resolution\Flag;
use CanonicalMapper\Domain\Resolution\FlagReason;
use CanonicalMapper\Domain\Resolution\Resolved;
use CanonicalMapper\Domain\Resolution\SourceName;
use CanonicalMapper\Domain\Resolution\Unresolved;
use CanonicalMapper\Domain\Rule\ComponentCascadeRule;
use PHPUnit\Framework\TestCase;

/**
 * Cascade, tested where the decision is made and without a file in sight.
 *
 * The same argument as NormaliseMenuTest makes for containment: the guarantee is
 * not "GammaPos withholds a composite whose component is missing" but "the rule
 * does, for any source". A test that reached it through a CSV parser could not
 * say that, and would go red when the parser broke.
 *
 * Hand-built resolutions also make the two properties worth believing cheap to
 * state. Transitivity needs a composite of a composite, which no fixture here has
 * a reason to contain; termination needs a chain arranged in the order that
 * forces more than one pass, which is not something a file format lets you ask
 * for.
 */
final class ComponentCascadeRuleTest extends TestCase
{
    public function testAListWithNothingDanglingIsReturnedUntouched(): void
    {
        // The rule has to be invisible to a healthy export, which is what the
        // cross-source equivalence fixture depends on. Identity rather than
        // equality: a rule that rebuilt every resolution would pass an equality
        // check and would mean something different.
        $resolutions = [
            self::simple('99'),
            self::composite('1310', ['99', '1100']),
            self::simple('1100'),
        ];

        self::assertSame(
            $resolutions,
            ComponentCascadeRule::apply(self::source(), $resolutions),
            'The rule disturbed an export in which every component is present',
        );
    }

    public function testACompositeIsWithheldWhenAComponentWasNeverDescribed(): void
    {
        $resolutions = ComponentCascadeRule::apply(self::source(), [
            self::simple('99'),
            self::composite('1310', ['99', '1210']),
        ]);

        self::assertSame(['99'], self::published($resolutions), 'The composite was published, or it took the plain item with it');
        self::assertSame([FlagReason::ComponentMissing], self::reasons($resolutions), 'The composite was not withheld exactly once');
    }

    public function testACompositeIsWithheldWhenAComponentWasItselfWithheld(): void
    {
        // The case the rule exists for, and the one it cannot distinguish from
        // the case above. From the composite's side they are the same fact: the
        // recipe names something that is not in this export.
        $resolutions = ComponentCascadeRule::apply(self::source(), [
            self::simple('99'),
            Unresolved::because(self::taxBasis('1100')),
            self::composite('1310', ['99', '1100']),
        ]);

        self::assertSame(['99'], self::published($resolutions), 'A composite whose component was withheld was published anyway');

        // Two flags, and the second one is the point of the whole step. Inheriting
        // the croissant's flag would tell someone to check a VAT code and say
        // nothing about the set that quietly vanished.
        self::assertSame(
            [FlagReason::TaxBasisUnknown, FlagReason::ComponentMissing],
            self::reasons($resolutions),
            'The withheld composite did not raise a flag of its own',
        );
    }

    public function testTheFlagNamesTheCompositeAndNotTheComponent(): void
    {
        $resolutions = ComponentCascadeRule::apply(self::source(), [
            self::composite('1310', ['1210']),
        ]);

        $flag = self::flags($resolutions)[0] ?? null;

        if ($flag === null) {
            self::fail('Nothing was withheld, so there is no flag to read');
        }

        // Which product is missing from the menu, which system to open, and which
        // other product to go and look for once there.
        self::assertSame('1310', $flag->sku?->value, 'The flag is filed against something other than the composite');
        self::assertStringContainsString('1210', $flag->detail, 'The flag did not name the component to go and find');
        self::assertStringContainsString('TestPos', $flag->detail, 'The flag did not name the system to open');
    }

    public function testTheCascadeIsTransitive(): void
    {
        // A composite of a composite, with the chain listed in the order that
        // makes one pass insufficient: the brunch is examined before the set it
        // contains has been withheld, so it can only be caught by a second pass.
        $resolutions = ComponentCascadeRule::apply(self::source(), [
            self::composite('1420', ['1310']),
            self::composite('1310', ['1210']),
            self::simple('99'),
        ]);

        self::assertSame(['99'], self::published($resolutions), 'The chain above a missing component was published');
        self::assertSame(
            ['1420', '1310'],
            array_map(static fn (Flag $flag): ?string => $flag->sku?->value, self::flags($resolutions)),
            'Each composite in the chain did not get a flag naming itself',
        );
    }

    public function testAChainThatWithholdsEverythingStillTerminates(): void
    {
        // Termination is a property of the loop rather than of the data, so the
        // test that would prove it does not exist. What this pins is the case
        // that consumes the most passes — every item withheld, one per pass in
        // the worst ordering — and that the rule returns from it at all.
        $resolutions = ComponentCascadeRule::apply(self::source(), [
            self::composite('1530', ['1420']),
            self::composite('1420', ['1310']),
            self::composite('1310', ['1210']),
        ]);

        self::assertSame([], self::published($resolutions), 'Something survived a chain with no foundation under it');
        self::assertCount(3, self::flags($resolutions), 'Every composite in the chain did not get its own flag');
    }

    public function testAnEmptyExportIsNotASpecialCase(): void
    {
        // The do/while runs its first pass unconditionally, so the empty export
        // is the one input that could have been a special case and is not.
        self::assertSame(
            [],
            ComponentCascadeRule::apply(self::source(), []),
            'An export with nothing in it did not come back with nothing in it',
        );
    }

    /**
     * @param list<Resolved<Item>|Unresolved> $resolutions
     *
     * @return list<string>
     */
    private static function published(array $resolutions): array
    {
        $skus = [];

        foreach ($resolutions as $resolution) {
            if ($resolution instanceof Resolved) {
                $skus[] = $resolution->value->sku->value;
            }
        }

        return $skus;
    }

    /**
     * @param list<Resolved<Item>|Unresolved> $resolutions
     *
     * @return list<Flag>
     */
    private static function flags(array $resolutions): array
    {
        $flags = [];

        foreach ($resolutions as $resolution) {
            if ($resolution instanceof Unresolved) {
                $flags[] = $resolution->flag;
            }
        }

        return $flags;
    }

    /**
     * @param list<Resolved<Item>|Unresolved> $resolutions
     *
     * @return list<FlagReason>
     */
    private static function reasons(array $resolutions): array
    {
        return array_map(static fn (Flag $flag): FlagReason => $flag->reason, self::flags($resolutions));
    }

    /**
     * @return Resolved<Item>
     */
    private static function simple(string $digits): Resolved
    {
        return Resolved::of(Item::simple(Sku::ofDigits($digits), 'Product ' . $digits, Money::fromMinorUnits(110)));
    }

    /**
     * @param non-empty-list<string> $components
     *
     * @return Resolved<Item>
     */
    private static function composite(string $digits, array $components): Resolved
    {
        return Resolved::of(Item::composite(
            Sku::ofDigits($digits),
            'Product ' . $digits,
            Money::fromMinorUnits(242),
            array_map(
                static fn (string $component): ComponentRef => ComponentRef::of(Sku::ofDigits($component), 1),
                $components,
            ),
        ));
    }

    private static function taxBasis(string $digits): Flag
    {
        return Flag::taxBasisUnknown(self::source(), $digits, Sku::ofDigits($digits), 'V99');
    }

    private static function source(): SourceName
    {
        return SourceName::of('TestPos');
    }
}
