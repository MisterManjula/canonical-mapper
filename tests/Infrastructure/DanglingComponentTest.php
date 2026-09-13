<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests\Infrastructure;

use CanonicalMapper\Application\MappingResult;
use CanonicalMapper\Application\NormaliseMenu;
use CanonicalMapper\Domain\Canonical\Item;
use CanonicalMapper\Domain\Resolution\Flag;
use CanonicalMapper\Domain\Resolution\FlagReason;
use CanonicalMapper\Infrastructure\Source\Gamma\GammaPosAdapter;
use PHPUnit\Framework\TestCase;

/**
 * The second ambiguity case, end to end, from a file on disk: a composite whose
 * component the export never describes.
 *
 * The fixture is a GammaPos file because a membership row naming a PLU with no
 * product row is the plainest way any of the three formats can express the gap —
 * one line, and nothing else about the file is unusual. It is a CSV, so it
 * carries no comment explaining itself; what it contains is a breakfast set made
 * of an espresso that is in the export and a P-1210 that is not, beside a juice
 * that has nothing to do with either.
 *
 * What this pins that ComponentCascadeRuleTest cannot is that the pieces are
 * joined up: the adapter builds the reference rather than refusing the row, the
 * use case applies the rule, and the flag survives to the caller. Until this
 * step the adapter answered this case by itself, in a check the other two
 * adapters did not have.
 */
final class DanglingComponentTest extends TestCase
{
    public function testTheCompositeIsAbsentAndItsResolvableComponentIsNot(): void
    {
        $result = self::normaliseTheFixture();

        // The espresso is both a product on the menu and a line of the withheld
        // recipe. Withholding the set is not supposed to cost it either role.
        self::assertSame(
            ['99', '1204'],
            array_map(static fn (Item $item): string => $item->sku->value, $result->menu->items),
            'The composite was published, or it took the items around it with it',
        );
    }

    public function testOneFlagNamesIt(): void
    {
        $result = self::normaliseTheFixture();

        self::assertSame(
            [FlagReason::ComponentMissing],
            array_map(static fn (Flag $flag): FlagReason => $flag->reason, $result->flags),
            'One withheld composite did not produce exactly one component-missing flag',
        );
    }

    public function testTheFlagIsSomethingAPersonCanActOn(): void
    {
        $result = self::normaliseTheFixture();
        $flag = $result->flags[0] ?? null;

        if ($flag === null) {
            self::fail('Nothing was withheld, so there is no flag to read');
        }

        self::assertStringContainsString('GammaPos', $flag->detail, 'The flag did not name the system to open');
        self::assertSame('1310', $flag->sku?->value, 'The flag is filed against something other than the composite');
        self::assertStringContainsString('1210', $flag->detail, 'The flag did not name the component to go and find');
    }

    private static function normaliseTheFixture(): MappingResult
    {
        $path = dirname(__DIR__, 2) . '/fixtures/ambiguous/dangling-component.csv';
        $contents = file_get_contents($path);

        if ($contents === false) {
            self::fail('Could not read the dangling-component fixture');
        }

        return (new NormaliseMenu())->run(new GammaPosAdapter(), $contents);
    }
}
