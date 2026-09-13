<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests\Infrastructure;

use CanonicalMapper\Application\NormaliseMenu;
use CanonicalMapper\Application\Port\SourceAdapter;
use CanonicalMapper\Infrastructure\Output\CanonicalJsonWriter;
use CanonicalMapper\Infrastructure\Source\Alpha\AlphaPosAdapter;
use CanonicalMapper\Infrastructure\Source\Beta\BetaPosAdapter;
use CanonicalMapper\Infrastructure\Source\Gamma\GammaPosAdapter;
use PHPUnit\Framework\TestCase;

/**
 * The test the repository exists for.
 *
 * Three files that have almost nothing in common — an object graph, an element
 * tree and a flat table; a padded identifier, an attribute and a prefixed one;
 * gross decimals with a dot, net minor units with a tax code, gross decimals with
 * a comma; composition expressed by nesting, by a list on the parent, and by a
 * column on the child pointing the other way — produce the same bytes.
 *
 * The per-source tests each assert against expected.json, which already implies
 * this. It is asserted again here, directly and between the three outputs, for
 * two reasons. One is that "all three equal the fixture" and "all three equal
 * each other" fail differently: if expected.json were ever regenerated from one
 * adapter's output, the first would go on passing while the claim quietly became
 * circular, and this one would not. The other is that the claim deserves to exist
 * as a test somebody can point at, rather than as a property of three tests read
 * together.
 *
 * What makes it a fair test rather than a lucky one is the fixture. The
 * assortment is chosen so that the source holding net prices can express every
 * gross amount exactly — net × (100 + rate) has to be a whole number of minor
 * units — and so that both of that source's tax rates are used. It contains no
 * promotions, because one of the three formats cannot express them, and a shared
 * truth cannot include something one participant is unable to say.
 */
final class CrossSourceEquivalenceTest extends TestCase
{
    public function testTheThreeSourcesProduceTheSameCanonicalMenu(): void
    {
        $alpha = self::normalise(new AlphaPosAdapter(), 'equivalence/alpha.json');
        $beta = self::normalise(new BetaPosAdapter(), 'equivalence/beta.xml');
        $gamma = self::normalise(new GammaPosAdapter(), 'equivalence/gamma.csv');

        self::assertSame($alpha, $beta, 'AlphaPos and BetaPos do not describe the same menu');
        self::assertSame($alpha, $gamma, 'AlphaPos and GammaPos do not describe the same menu');
    }

    public function testThatMenuIsTheOneTheFixtureRecords(): void
    {
        // Without this, the three could agree on something wrong. expected.json is
        // written by hand, so it is a fourth statement of the same menu made by
        // somebody rather than by an adapter.
        self::assertSame(
            self::fixture('equivalence/expected.json'),
            self::normalise(new AlphaPosAdapter(), 'equivalence/alpha.json'),
            'The three sources agree with each other and not with the canonical fixture',
        );
    }

    public function testNoSourceWithholdsAnythingFromTheEquivalenceFixture(): void
    {
        // A withheld item is absent from the output, so three sources that all
        // withheld the same item would still produce identical bytes and the test
        // above would pass while the menu quietly emptied.
        foreach ([
            'alpha.json' => new AlphaPosAdapter(),
            'beta.xml' => new BetaPosAdapter(),
            'gamma.csv' => new GammaPosAdapter(),
        ] as $file => $adapter) {
            $result = (new NormaliseMenu())->run($adapter, self::fixture('equivalence/' . $file));

            self::assertSame([], $result->flags, sprintf('%s withheld something from the equivalence fixture', $file));
            self::assertCount(4, $result->menu->items, sprintf('%s did not produce the whole assortment', $file));
        }
    }

    private static function normalise(SourceAdapter $adapter, string $path): string
    {
        $result = (new NormaliseMenu())->run($adapter, self::fixture($path));

        return (new CanonicalJsonWriter())->write($result->menu);
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
