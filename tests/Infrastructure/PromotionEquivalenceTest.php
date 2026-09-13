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
 * The equivalence claim again, over the part of the menu the main fixture cannot
 * contain.
 *
 * The cross-source fixture has no promotions in it, and for a good reason: one
 * of the three formats cannot express them, and a shared truth cannot include
 * something a participant is unable to say. That leaves the promotion mechanism
 * — the place where the two formats that *can* speak disagree most completely —
 * outside the test that matters most, which is why this one exists.
 *
 * They disagree about everything except the result. AlphaPos states a percentage
 * off its own gross price; BetaPos states an absolute promotional price, net, to
 * be read under the same tax code as the product. Not one number in the two
 * files is the same number. The canonical model stores neither the percentage
 * nor the net amount — it stores the gross price that applies — so both files
 * arrive at 176, and a consumer cannot tell which mechanism produced the menu it
 * is reading.
 *
 * That is also the cost, and it is stated in the Promotion class rather than
 * hidden: a consumer that wants to display "20% off" cannot recover it from the
 * canonical output. What it gets instead is a price it can trust from either
 * source.
 */
final class PromotionEquivalenceTest extends TestCase
{
    public function testTheTwoSourcesThatCanExpressAPromotionAgreeOnIt(): void
    {
        self::assertSame(
            self::normalise(new AlphaPosAdapter(), 'promotions/alpha.json'),
            self::normalise(new BetaPosAdapter(), 'promotions/beta.xml'),
            'A percentage off and an absolute promotional price did not produce the same menu',
        );
    }

    public function testThatMenuIsTheOneTheFixtureRecords(): void
    {
        // The same argument the cross-source test makes: without a fixture written
        // by hand, the two adapters could agree on something wrong and this would
        // go on passing.
        self::assertSame(
            self::fixture('promotions/expected.json'),
            self::normalise(new AlphaPosAdapter(), 'promotions/alpha.json'),
            'The two sources agree with each other and not with the canonical fixture',
        );
    }

    public function testNeitherSourceWithholdsAnythingFromThisFixture(): void
    {
        // Two promotions that both failed to resolve would leave two items with no
        // promotion, which is still identical bytes from both sources.
        foreach (['alpha.json' => new AlphaPosAdapter(), 'beta.xml' => new BetaPosAdapter()] as $file => $adapter) {
            $result = (new NormaliseMenu())->run($adapter, self::fixture('promotions/' . $file));

            self::assertSame([], $result->flags, sprintf('%s withheld something from the promotions fixture', $file));
        }
    }

    public function testThePromotionActuallyReachedTheOutput(): void
    {
        // The assertions above are byte comparisons, and two sources that both
        // dropped the promotion would satisfy every one of them. This is the one
        // that says the thing under test is in the output at all.
        self::assertStringContainsString(
            '"price": 176',
            self::normalise(new AlphaPosAdapter(), 'promotions/alpha.json'),
            'The promotional price is not in the canonical output',
        );
    }

    public function testTheThirdSourceCannotExpressThisFixtureAtAll(): void
    {
        // Not a limitation being worked around: it is why the main equivalence
        // fixture has no promotions. GammaPos has five columns and none of them is
        // a date, so a promotion can only reach this adapter as a column it has
        // not been taught to read — which it refuses rather than drops.
        self::assertStringNotContainsString(
            'from',
            self::fixture('equivalence/gamma.csv'),
            'The GammaPos fixture has grown a promotion column, and the format has no such thing',
        );

        self::assertSame(
            [],
            (new NormaliseMenu())->run(new GammaPosAdapter(), self::fixture('equivalence/gamma.csv'))->flags,
            'The source that cannot express promotions started withholding things over them',
        );
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
