<?php

declare(strict_types=1);

namespace CanonicalMapper\Resolution;

use CanonicalMapper\Canonical\Sku;

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
 * There is one named constructor per reason, so that the wording of the sentence
 * lives beside the case it belongs to and the two cannot drift apart.
 */
final class Flag
{
    private function __construct(
        public readonly SourceSystem $source,
        public readonly string $sourceProductId,
        public readonly ?Sku $sku,
        public readonly FlagReason $reason,
        public readonly string $detail,
    ) {
    }

    public static function taxBasisUnknown(
        SourceSystem $source,
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
                $source->displayName(),
            ),
        );
    }

    public static function componentMissing(
        SourceSystem $source,
        string $sourceProductId,
        ?Sku $sku,
        string $missingComponentId,
    ): self {
        return new self(
            $source,
            $sourceProductId,
            $sku,
            FlagReason::ComponentMissing,
            sprintf(
                'Composite %s references component %s, which is not present in this export, '
                . 'so its price cannot be determined; check whether %s was deleted or simply '
                . 'not included in the %s extract.',
                $sourceProductId,
                $missingComponentId,
                $missingComponentId,
                $source->displayName(),
            ),
        );
    }

    public static function promotionConflict(
        SourceSystem $source,
        string $sourceProductId,
        ?Sku $sku,
        string $overlappingPeriod,
    ): self {
        return new self(
            $source,
            $sourceProductId,
            $sku,
            FlagReason::PromotionConflict,
            sprintf(
                'Product %s has two promotions with different results overlapping on %s, so '
                . 'there is no single price to publish for those days; decide in %s which '
                . 'promotion applies and shorten the other.',
                $sourceProductId,
                $overlappingPeriod,
                $source->displayName(),
            ),
        );
    }
}
