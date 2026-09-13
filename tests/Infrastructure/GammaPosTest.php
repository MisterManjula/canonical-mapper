<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests\Infrastructure;

use CanonicalMapper\Application\NormaliseMenu;
use CanonicalMapper\Application\Port\MalformedSource;
use CanonicalMapper\Domain\Canonical\ComponentRef;
use CanonicalMapper\Domain\Canonical\Item;
use CanonicalMapper\Domain\Resolution\Flag;
use CanonicalMapper\Domain\Resolution\FlagReason;
use CanonicalMapper\Infrastructure\Output\CanonicalJsonWriter;
use CanonicalMapper\Infrastructure\Source\Gamma\GammaPosAdapter;
use PHPUnit\Framework\TestCase;

/**
 * The third source, and the one whose format carries the least.
 *
 * A row cannot contain another row, so GammaPos spells composition as a value in
 * a column, and it points the other way from the two structured formats: the
 * child names its composite. Reaching the same expected.json from an inverted
 * relationship is what makes the equivalence claim about meaning rather than
 * about shape.
 *
 * The "P-" prefix and the comma decimal are asserted here rather than in the
 * domain tests, because this adapter is where they are known about.
 */
final class GammaPosTest extends TestCase
{
    public function testTheGammaPosExportProducesTheCanonicalMenu(): void
    {
        $result = (new NormaliseMenu())->run(new GammaPosAdapter(), self::fixture('equivalence/gamma.csv'));

        self::assertSame(
            self::fixture('equivalence/expected.json'),
            (new CanonicalJsonWriter())->write($result->menu),
            'The GammaPos export did not produce the canonical menu byte for byte',
        );
    }

    public function testNothingIsWithheldFromTheEquivalenceFixture(): void
    {
        $result = (new NormaliseMenu())->run(new GammaPosAdapter(), self::fixture('equivalence/gamma.csv'));

        self::assertSame([], $result->flags, 'The equivalence fixture withheld something');
    }

    public function testAProductIsSoldOnItsOwnAndIsAlsoALineOfARecipe(): void
    {
        $result = (new NormaliseMenu())->run(new GammaPosAdapter(), self::fixture('equivalence/gamma.csv'));

        // The whole reason a membership row is a separate row. A single row
        // carrying a PARENT_PLU could say that espresso is part of the breakfast
        // set, or that espresso is on the menu, but not both.
        $set = null;

        foreach ($result->menu->items as $item) {
            if ($item->sku->value === '1310') {
                $set = $item;
            }
        }

        if ($set === null) {
            self::fail('The breakfast set is not in the canonical menu');
        }

        self::assertSame(
            ['99', '1100', '1204', '1310'],
            array_map(static fn (Item $item): string => $item->sku->value, $result->menu->items),
            'A product that is also a component was duplicated or lost',
        );

        self::assertSame(
            [['99', 1], ['1100', 1]],
            array_map(
                static fn (ComponentRef $component): array => [$component->sku->value, $component->quantity],
                $set->components,
            ),
            'The set did not reference the products whose rows name it as their parent',
        );
    }

    public function testAComponentWithNoProductRowWithholdsTheCompositeAndLeavesTheRest(): void
    {
        // The ambiguity this source can express. The export says the set contains
        // something it never describes, so what the set *is* cannot be determined;
        // publishing it with a shortened recipe would be a guess about whether the
        // missing row mattered.
        $result = (new NormaliseMenu())->run(new GammaPosAdapter(), <<<'CSV'
            PLU;NAME;PRICE;PARENT_PLU;QTY
            P-99;Espresso;1,10;;
            P-1310;Breakfast set;2,42;;
            P-99;;;P-1310;1
            P-1210;;;P-1310;1
            CSV);

        self::assertSame(
            ['99'],
            array_map(static fn (Item $item): string => $item->sku->value, $result->menu->items),
            'The withheld composite was published, or it took the resolvable item with it',
        );

        self::assertSame(
            [FlagReason::ComponentMissing],
            array_map(static fn (Flag $flag): FlagReason => $flag->reason, $result->flags),
            'One withheld composite did not produce exactly one component-missing flag',
        );
    }

    public function testTheFlagNamesTheCompositeAndTheComponentInCanonicalSpelling(): void
    {
        $result = (new NormaliseMenu())->run(new GammaPosAdapter(), <<<'CSV'
            PLU;NAME;PRICE;PARENT_PLU;QTY
            P-1310;Breakfast set;2,42;;
            P-1210;;;P-1310;1
            CSV);

        $details = array_map(static fn (Flag $flag): string => $flag->detail, $result->flags);
        $ids = array_map(static fn (Flag $flag): string => $flag->sourceProductId, $result->flags);

        // This used to read ['P-1310'], and the test used to be named for it:
        // the prefix is how GammaPos spells an identifier, the flag is read over
        // there, and searching that system for "1310" may well find nothing.
        //
        // The spelling was lost when the check moved out of this adapter and into
        // the cascade rule, which runs for every source and therefore sees only
        // what normalisation left. That is a real downgrade to what someone holds
        // when they act on this flag, accepted because the check it replaces
        // existed in this adapter and in neither of the others — BetaPos was
        // publishing dangling references in silence. The tax-basis flag still
        // quotes the raw id, because the adapter still raises it.
        self::assertSame(['1310'], $ids, 'The flag did not quote the composite in its canonical spelling');
        self::assertStringContainsString('1310', $details[0] ?? '', 'The flag did not name the withheld composite');
        self::assertStringContainsString('1210', $details[0] ?? '', 'The flag did not name the missing component');
    }

    public function testAMembershipRowNamingAParentThatDoesNotExistIsRefused(): void
    {
        // Not withheld: there is no composite to withhold. The file says a product
        // is part of something it never mentions again, which is a contradiction
        // rather than a question anyone can answer.
        $this->expectException(MalformedSource::class);

        (new NormaliseMenu())->run(new GammaPosAdapter(), <<<'CSV'
            PLU;NAME;PRICE;PARENT_PLU;QTY
            P-99;Espresso;1,10;;
            P-99;;;P-1310;1
            CSV);
    }

    public function testAMembershipRowCarryingAPriceIsRefused(): void
    {
        // A second definition of the product, and two definitions can disagree.
        $this->expectException(MalformedSource::class);

        (new NormaliseMenu())->run(new GammaPosAdapter(), <<<'CSV'
            PLU;NAME;PRICE;PARENT_PLU;QTY
            P-99;Espresso;1,10;;
            P-1310;Breakfast set;2,42;;
            P-99;Espresso;1,30;P-1310;1
            CSV);
    }

    public function testAPluWithoutItsPrefixIsRefusedRatherThanGuessed(): void
    {
        $this->expectException(MalformedSource::class);

        (new NormaliseMenu())->run(new GammaPosAdapter(), <<<'CSV'
            PLU;NAME;PRICE;PARENT_PLU;QTY
            99;Espresso;1,10;;
            CSV);
    }

    public function testAPriceWrittenWithADotIsRefusedRatherThanGuessed(): void
    {
        // "1.10" is what a different source writes. Accepting both spellings here
        // is the tolerance that would make the cross-source comparison prove less
        // than it claims.
        $this->expectException(MalformedSource::class);

        (new NormaliseMenu())->run(new GammaPosAdapter(), <<<'CSV'
            PLU;NAME;PRICE;PARENT_PLU;QTY
            P-99;Espresso;1.10;;
            CSV);
    }

    public function testAColumnTheAdapterHasNotBeenTaughtToReadIsRefused(): void
    {
        $this->expectException(MalformedSource::class);

        (new NormaliseMenu())->run(new GammaPosAdapter(), <<<'CSV'
            PLU;NAME;PRICE;PARENT_PLU;QTY;CHANNEL
            P-99;Espresso;1,10;;;kiosk
            CSV);
    }

    public function testARowWithTheWrongNumberOfFieldsIsRefused(): void
    {
        $this->expectException(MalformedSource::class);

        (new NormaliseMenu())->run(new GammaPosAdapter(), <<<'CSV'
            PLU;NAME;PRICE;PARENT_PLU;QTY
            P-99;Espresso;1,10
            CSV);
    }

    private static function fixture(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/fixtures/' . $path);

        if ($contents === false) {
            self::fail(sprintf('Could not read the fixture %s', $path));
        }

        // A CRLF checkout would make the byte comparison fail with an unreadable
        // diff. .gitattributes pins LF for these files; this is the check that
        // names the cause if that ever stops working.
        self::assertStringNotContainsString(
            "\r\n",
            $contents,
            sprintf('The fixture %s has CRLF line endings; check .gitattributes', $path),
        );

        return $contents;
    }
}
