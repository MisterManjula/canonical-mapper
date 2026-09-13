<?php

declare(strict_types=1);

namespace CanonicalMapper\Domain\Resolution;

use CanonicalMapper\Domain\Canonical\Promotion;
use CanonicalMapper\Domain\Canonical\Sku;

/**
 * What was withheld, and what somebody has to do about it.
 *
 * A flag is a work item, not a log line. Everything on it is here because a
 * person acting on it needs it: the system to open, the identifier to search for
 * *in that system*, and one sentence naming the value to verify. A report of
 * "COMPONENT_MISSING x3" that does not say which three is not actionable, and an
 * unactionable report makes withholding look like data loss rather than a
 * question being asked.
 *
 * $sourceProductId is the raw string exactly as the source wrote it — "001204",
 * not 1204 — precisely because it is what someone will paste into a search box.
 * The normalised $sku is an addition for whoever is reading the canonical side,
 * and it is nullable because some failures happen before an identifier can be
 * normalised at all.
 *
 * With one exception, named where it happens: a flag raised by a domain rule
 * rather than by an adapter carries the canonical spelling in both fields,
 * because a rule that runs after normalisation has no access to the file's own.
 * That is a genuine loss to the person reading it, and it is the price of the
 * rule being one rule instead of one per source.
 *
 * $source is a name, not a choice from a list. The adapter supplies it, because
 * the adapter is the only thing in this project that knows which system it has
 * been reading; the sentences below name a system to open without this class
 * ever having been told which systems exist, or how many.
 *
 * There is one named constructor per reason, so that the wording of the sentence
 * lives beside the case it belongs to and the two cannot drift apart.
 */
final class Flag
{
    private function __construct(
        public readonly SourceName $source,
        public readonly string $sourceProductId,
        public readonly ?Sku $sku,
        public readonly FlagReason $reason,
        public readonly string $detail,
    ) {
    }

    public static function taxBasisUnknown(
        SourceName $source,
        string $sourceProductId,
        ?Sku $sku,
        string $vatCode,
    ): self {
        return new self(
            $source,
            $sourceProductId,
            $sku,
            FlagReason::TaxBasisUnknown,
            sprintf(
                'Product %s carries VAT code %s, which has no known rate, so its net price '
                . 'cannot be converted to the gross price the canonical model publishes; '
                . 'confirm the rate for %s in %s.',
                $sourceProductId,
                $vatCode,
                $vatCode,
                $source->value,
            ),
        );
    }

    /**
     * Raised by the cascade rule and by nothing else, which is why this is the
     * one constructor here that takes no raw source id: a domain rule has never
     * seen the file, and the SKUs are all it holds.
     *
     * That costs something real, and the cost is paid deliberately. A GammaPos
     * composite is named here as "1310" where that system spells it "P-1310",
     * so the identifier on this flag is one a person may have to re-prefix
     * before searching. The alternative was to keep the check in each adapter,
     * where the raw spellings live — and one adapter did not have it at all.
     * A rule that runs for every source, in the vocabulary every source has been
     * normalised into, is worth more than a prefix.
     */
    public static function componentMissing(SourceName $source, Sku $composite, Sku $component): self
    {
        return new self(
            $source,
            $composite->value,
            $composite,
            FlagReason::ComponentMissing,
            sprintf(
                'Composite %s is withheld because component %s is not present in this export, '
                . 'so what the composite contains cannot be determined; check in %s whether %s '
                . 'was deleted, or is withheld under a flag of its own, or is simply not in '
                . 'this extract.',
                $composite->value,
                $component->value,
                $source->value,
                $component->value,
            ),
        );
    }

    /**
     * Raised by the conflict rule, which — unlike the cascade rule — runs while
     * the adapter is still reading the product, so the raw spelling is in hand
     * and is passed in rather than lost.
     *
     * Two sentences for one reason, which is a departure from the one-constructor
     * rule above and stays inside it: the constructor is still one, and the
     * choice between the wordings is made here, beside them, rather than by a
     * caller assembling a phrase. Two promotions in force on days they share
     * leave no price for those days, and that is a sharper thing to be told than
     * that two offers cannot both fit in one slot.
     */
    public static function promotionConflict(
        SourceName $source,
        string $sourceProductId,
        ?Sku $sku,
        Promotion $first,
        Promotion $second,
    ): self {
        $detail = $first->overlaps($second)
            ? sprintf(
                'Product %s has two promotions in force on days they share — %s, and %s — so '
                . 'there is no single price to publish for those days; decide in %s which one '
                . 'applies and shorten the other.',
                $sourceProductId,
                self::describe($first),
                self::describe($second),
                $source->value,
            )
            : sprintf(
                'Product %s has two promotions — %s, and %s — and the canonical menu carries '
                . 'one promotion per product, so there is no single one to publish; decide in '
                . '%s which of them this menu should state.',
                $sourceProductId,
                self::describe($first),
                self::describe($second),
                $source->value,
            );

        return new self($source, $sourceProductId, $sku, FlagReason::PromotionConflict, $detail);
    }

    /**
     * Minor units, unformatted, for the reason the writer gives: a formatted
     * price needs a decimal separator, and which separator to use is the thing
     * the three sources disagree about. The number here is the number the
     * canonical output would have carried, so the two can be read side by side.
     */
    private static function describe(Promotion $promotion): string
    {
        return sprintf(
            '%d minor units from %s to %s',
            $promotion->price->minorUnits,
            $promotion->from->format('Y-m-d'),
            $promotion->to->format('Y-m-d'),
        );
    }
}
