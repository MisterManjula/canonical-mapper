<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests\Domain;

use CanonicalMapper\Domain\Canonical\Money;
use CanonicalMapper\Domain\Canonical\Promotion;
use CanonicalMapper\Domain\Canonical\Sku;
use CanonicalMapper\Domain\Resolution\FlagReason;
use CanonicalMapper\Domain\Resolution\Resolved;
use CanonicalMapper\Domain\Resolution\SourceName;
use CanonicalMapper\Domain\Resolution\Unresolved;
use CanonicalMapper\Domain\Rule\PromotionConflictRule;
use PHPUnit\Framework\TestCase;

/**
 * The third ambiguity, at the point where it is decided.
 *
 * The rule answers one question — which single promotion, if any, does the
 * canonical model carry for this product — and it has only four answers: none,
 * this one, this one because the other is a copy of it, and none of them because
 * a person has to choose. The tests are that list.
 *
 * Both branches of the flag's wording are checked here rather than left to the
 * end-to-end fixture, because a fixture can only exhibit one of them and the
 * other would go unread until somebody met it in production.
 */
final class PromotionConflictRuleTest extends TestCase
{
    public function testAProductWithNoPromotionResolvesToNoPromotion(): void
    {
        // The case every item in the equivalence fixture takes. The rule has to be
        // reachable without changing anything for it, or it would be visible in a
        // byte comparison that is supposed to be about something else.
        self::assertNull(self::resolve([]), 'A product with no promotion acquired one');
    }

    public function testAProductWithOnePromotionResolvesToIt(): void
    {
        $promotion = self::at(176, '2026-03-01', '2026-03-10');

        self::assertSame($promotion, self::resolve([$promotion]), 'The one promotion stated did not survive the rule');
    }

    public function testTheSameOfferStatedTwiceIsNotAConflict(): void
    {
        // An export assembled from two queries can say one thing twice. Two
        // identical statements answer the same question the same way, so there is
        // nothing to ask anybody.
        $first = self::at(176, '2026-03-01', '2026-03-10');
        $second = self::at(176, '2026-03-01', '2026-03-10');

        self::assertSame($first, self::resolve([$first, $second]), 'One offer written twice was read as a conflict');
    }

    public function testTwoPromotionsInForceOnTheSameDaysAreAConflict(): void
    {
        $resolution = PromotionConflictRule::apply(self::source(), 'P-1204', Sku::ofDigits('1204'), [
            self::at(176, '2026-03-01', '2026-03-10'),
            self::at(180, '2026-03-05', '2026-03-20'),
        ]);

        if (!$resolution instanceof Unresolved) {
            self::fail('Two promotions in force on the same days produced a price anyway');
        }

        self::assertSame(FlagReason::PromotionConflict, $resolution->flag->reason, 'The flag carried the wrong reason');

        // The sharper of the two sentences: on the days they share there is no
        // price at all, which is a different thing from two offers not fitting in
        // one slot, and the person is told to shorten one rather than to choose.
        self::assertStringContainsString(
            'days they share',
            $resolution->flag->detail,
            'The flag did not say that the two promotions are in force at once',
        );
        self::assertStringContainsString('shorten the other', $resolution->flag->detail, 'The flag did not say what to do');
    }

    public function testTwoPromotionsOnSeparateDaysAreAlsoAConflict(): void
    {
        // Nothing contradicts anything here: both offers could be true. The
        // canonical model carries one promotion per product, deliberately, so
        // there is still no single one to publish — and picking the first would
        // be answering a question by the order of a list.
        $resolution = PromotionConflictRule::apply(self::source(), 'P-1204', Sku::ofDigits('1204'), [
            self::at(176, '2026-03-01', '2026-03-10'),
            self::at(180, '2026-03-20', '2026-03-31'),
        ]);

        if (!$resolution instanceof Unresolved) {
            self::fail('Two promotions the model cannot both carry produced a price anyway');
        }

        self::assertStringContainsString(
            'one promotion per product',
            $resolution->flag->detail,
            'The flag did not say why two separate offers cannot both be published',
        );
        self::assertStringNotContainsString(
            'days they share',
            $resolution->flag->detail,
            'The flag claimed an overlap that the two ranges do not have',
        );
    }

    public function testOnePriceOverTwoRangesIsAConflictRatherThanAMerge(): void
    {
        $resolution = PromotionConflictRule::apply(self::source(), 'P-1204', Sku::ofDigits('1204'), [
            self::at(176, '2026-03-01', '2026-03-10'),
            self::at(176, '2026-03-05', '2026-03-20'),
        ]);

        // Agreeing about the price is not agreeing. Publishing the union of the
        // two ranges would state an offer neither of them states, which is the
        // guess this project exists not to make.
        if (!$resolution instanceof Unresolved) {
            self::fail('Two offers at one price over different ranges were quietly merged into one');
        }

        self::assertSame(FlagReason::PromotionConflict, $resolution->flag->reason, 'The flag carried the wrong reason');
    }

    public function testTheFlagQuotesTheIdentifierAsTheSourceWroteIt(): void
    {
        // Unlike the cascade rule, this one runs while the adapter is still
        // reading the product, so the raw spelling is in hand and is not lost.
        $resolution = PromotionConflictRule::apply(self::source(), 'P-1204', Sku::ofDigits('1204'), [
            self::at(176, '2026-03-01', '2026-03-10'),
            self::at(180, '2026-03-05', '2026-03-20'),
        ]);

        if (!$resolution instanceof Unresolved) {
            self::fail('Nothing was withheld, so there is no flag to read');
        }

        self::assertSame('P-1204', $resolution->flag->sourceProductId, 'The flag normalised an id that has to stay searchable');
        self::assertSame('1204', $resolution->flag->sku?->value, 'The flag lost the canonical SKU');
        self::assertStringContainsString('TestPos', $resolution->flag->detail, 'The flag did not name the system to open');

        // Both amounts, in the unformatted minor units the canonical output would
        // have carried, so the flag and the menu can be read side by side without
        // a decimal separator being invented in one of them.
        self::assertStringContainsString('176 minor units', $resolution->flag->detail, 'The flag did not name the first price');
        self::assertStringContainsString('180 minor units', $resolution->flag->detail, 'The flag did not name the second price');
    }

    public function testThreePromotionsStillProduceOneFlag(): void
    {
        // A flag is a work item, not an inventory. The sentence ends in a person
        // opening the source and looking at that product, and they will see the
        // third one when they do.
        $resolution = PromotionConflictRule::apply(self::source(), 'P-1204', Sku::ofDigits('1204'), [
            self::at(176, '2026-03-01', '2026-03-10'),
            self::at(180, '2026-03-05', '2026-03-20'),
            self::at(190, '2026-03-07', '2026-03-25'),
        ]);

        if (!$resolution instanceof Unresolved) {
            self::fail('Three promotions produced a price anyway');
        }

        self::assertStringContainsString(
            '176 minor units',
            $resolution->flag->detail,
            'The flag did not name the first two of the three promotions',
        );
        self::assertStringNotContainsString(
            '190 minor units',
            $resolution->flag->detail,
            'The flag turned into an inventory of every promotion on the product',
        );
    }

    /**
     * @param list<Promotion> $promotions
     */
    private static function resolve(array $promotions): ?Promotion
    {
        $resolution = PromotionConflictRule::apply(self::source(), 'P-1204', Sku::ofDigits('1204'), $promotions);

        // Narrowed with instanceof and self::fail() rather than assertInstanceOf,
        // which would leave the union in place and make ->value an undefined
        // property under PHPStan. The tests handle the branch the way the
        // adapters must.
        if (!$resolution instanceof Resolved) {
            self::fail('The promotions were withheld when they should have resolved');
        }

        return $resolution->value;
    }

    private static function at(int $minorUnits, string $from, string $to): Promotion
    {
        return Promotion::atPrice(Money::fromMinorUnits($minorUnits), $from, $to);
    }

    private static function source(): SourceName
    {
        return SourceName::of('TestPos');
    }
}
