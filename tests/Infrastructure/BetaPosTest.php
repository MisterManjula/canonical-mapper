<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests\Infrastructure;

use CanonicalMapper\Application\NormaliseMenu;
use CanonicalMapper\Application\Port\MalformedSource;
use CanonicalMapper\Domain\Canonical\Item;
use CanonicalMapper\Domain\Resolution\Flag;
use CanonicalMapper\Domain\Resolution\FlagReason;
use CanonicalMapper\Infrastructure\Output\CanonicalJsonWriter;
use CanonicalMapper\Infrastructure\Source\Beta\BetaPosAdapter;
use CanonicalMapper\Infrastructure\Source\Beta\VatCode;
use PHPUnit\Framework\TestCase;

/**
 * The second source against the same expected.json as the first.
 *
 * BetaPos is the one that does not store the number a customer sees: it keeps net
 * prices and a tax code, so every price in the fixture is arrived at by
 * arithmetic rather than by reading. That two sources converge on identical bytes
 * while one of them computed every amount is the first real evidence that the
 * canonical model is a model and not a reformatting.
 */
final class BetaPosTest extends TestCase
{
    public function testTheBetaPosExportProducesTheCanonicalMenu(): void
    {
        $result = (new NormaliseMenu())->run(new BetaPosAdapter(), self::fixture('equivalence/beta.xml'));

        self::assertSame(
            self::fixture('equivalence/expected.json'),
            (new CanonicalJsonWriter())->write($result->menu),
            'The BetaPos export did not produce the canonical menu byte for byte',
        );
    }

    public function testNothingIsWithheldFromTheEquivalenceFixture(): void
    {
        $result = (new NormaliseMenu())->run(new BetaPosAdapter(), self::fixture('equivalence/beta.xml'));

        self::assertSame([], $result->flags, 'The equivalence fixture withheld something');
    }

    public function testBothKnownRatesAreExercisedByTheEquivalenceFixture(): void
    {
        // A fixture that only ever used one rate would let a wrong rate for the
        // other pass unnoticed, and the byte comparison above would stay green
        // while it did.
        $contents = self::fixture('equivalence/beta.xml');

        self::assertStringContainsString('vat="V10"', $contents, 'The fixture stopped exercising the lower rate');
        self::assertStringContainsString('vat="V22"', $contents, 'The fixture stopped exercising the higher rate');
    }

    public function testAKnownCodeResolvesToARateAndAnUnknownOneDoesNot(): void
    {
        self::assertSame(10, VatCode::rateFor('V10'), 'V10 is no longer ten per cent');
        self::assertSame(22, VatCode::rateFor('V22'), 'V22 is no longer twenty-two per cent');

        // null rather than an exception, and null rather than a default. The
        // export is well formed and states a fact this mapper cannot interpret,
        // which is a different situation from a broken file and is answered
        // differently.
        self::assertNull(VatCode::rateFor('V99'), 'An unknown tax code was given a rate anyway');
    }

    public function testAnUnknownTaxCodeWithholdsThatItemAndLeavesTheRest(): void
    {
        $result = (new NormaliseMenu())->run(new BetaPosAdapter(), <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <catalogue>
                <product code="99" name="Espresso" net="100" vat="V10"/>
                <product code="1100" name="Croissant" net="150" vat="V99"/>
            </catalogue>
            XML);

        self::assertSame(
            ['99'],
            array_map(static fn (Item $item): string => $item->sku->value, $result->menu->items),
            'The unresolvable item was published, or it took the resolvable one with it',
        );

        // Mapped rather than indexed: a list has no guaranteed offset 0, and this
        // says "exactly one flag, and it is this one" in a single assertion.
        self::assertSame(
            [FlagReason::TaxBasisUnknown],
            array_map(static fn (Flag $flag): FlagReason => $flag->reason, $result->flags),
            'One withheld item did not produce exactly one tax-basis flag',
        );
    }

    public function testAComponentThisExportDoesNotDescribeWithholdsTheCompositeRatherThanPublishingIt(): void
    {
        // A regression, and it is worth being exact about what regressed. Against
        // this adapter as committed at step 6 the assertions below both fail: a
        // <component> naming a product with no <product> element of its own built
        // a reference like any other, CanonicalMenu::of only looked for duplicate
        // SKUs, and the run produced canonical JSON referencing 1210 — a SKU no
        // consumer could resolve — with no flag and no error.
        //
        // The check existed in GammaPosAdapter and only there. That is the shape
        // of bug a rule per adapter produces, and the reason this one is now a
        // rule in the domain: the fix arrived here without this file being
        // touched.
        $result = (new NormaliseMenu())->run(new BetaPosAdapter(), <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <catalogue>
                <product code="99" name="Espresso" net="100" vat="V10"/>
                <product code="1310" name="Breakfast set" net="220" vat="V10">
                    <component code="1210" qty="1"/>
                </product>
            </catalogue>
            XML);

        self::assertSame(
            ['99'],
            array_map(static fn (Item $item): string => $item->sku->value, $result->menu->items),
            'A composite referencing a product this export does not contain was published',
        );

        self::assertSame(
            [FlagReason::ComponentMissing],
            array_map(static fn (Flag $flag): FlagReason => $flag->reason, $result->flags),
            'The composite vanished or was published without anyone being told why',
        );
    }

    public function testAMalformedNetPriceIsRefusedEvenOnAnItemThatWouldBeWithheldAnyway(): void
    {
        // Structure is read before anything is resolved, so whether a broken file
        // is reported as broken does not depend on which of its problems the
        // parser happened to meet first. Those two answers go to different people.
        $this->expectException(MalformedSource::class);

        (new NormaliseMenu())->run(new BetaPosAdapter(), <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <catalogue>
                <product code="99" name="Espresso" net="1.00" vat="V99"/>
            </catalogue>
            XML);
    }

    public function testAnAttributeTheAdapterHasNotBeenTaughtToReadIsRefused(): void
    {
        $this->expectException(MalformedSource::class);

        (new NormaliseMenu())->run(new BetaPosAdapter(), <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <catalogue>
                <product code="99" name="Espresso" net="100" vat="V10" channel="kiosk"/>
            </catalogue>
            XML);
    }

    public function testAnElementTheAdapterHasNotBeenTaughtToReadIsRefused(): void
    {
        $this->expectException(MalformedSource::class);

        (new NormaliseMenu())->run(new BetaPosAdapter(), <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <catalogue>
                <product code="99" name="Espresso" net="100" vat="V10">
                    <allergen code="milk"/>
                </product>
            </catalogue>
            XML);
    }

    public function testAPaddedCodeIsRefusedRatherThanQuietlyUnpadded(): void
    {
        // BetaPos writes code="1204" and never code="0099": this format has no
        // padding, so a padded code is an export that is not what it claims to
        // be. It used to be unpadded in silence, back when Sku stripped zeroes
        // for every source whether or not that source padded.
        $this->expectException(MalformedSource::class);

        (new NormaliseMenu())->run(new BetaPosAdapter(), <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <catalogue>
                <product code="0099" name="Espresso" net="100" vat="V10"/>
            </catalogue>
            XML);
    }

    public function testTwoProductsWithOneCodeAreRefusedOnceTheWholeExportIsRead(): void
    {
        // The one invariant no adapter can check for itself, because a duplicate
        // is only visible once every product has been read. The domain refuses
        // the menu in a sentence that mentions no file, and NormaliseMenu
        // translates it; that translation is the only reason this arrives as a
        // MalformedSource rather than as a domain exception escaping the port.
        $this->expectException(MalformedSource::class);

        (new NormaliseMenu())->run(new BetaPosAdapter(), <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <catalogue>
                <product code="99" name="Espresso" net="100" vat="V10"/>
                <product code="99" name="Espresso" net="100" vat="V10"/>
            </catalogue>
            XML);
    }

    public function testAnExportThatIsNotXmlIsRefused(): void
    {
        $this->expectException(MalformedSource::class);

        (new NormaliseMenu())->run(new BetaPosAdapter(), '{"menu": {"products": []}}');
    }

    private static function fixture(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/fixtures/' . $path);

        if ($contents === false) {
            self::fail(sprintf('Could not read the fixture %s', $path));
        }

        self::assertStringNotContainsString(
            "\r\n",
            $contents,
            sprintf('The fixture %s has CRLF line endings; check .gitattributes', $path),
        );

        return $contents;
    }
}
