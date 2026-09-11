<?php

declare(strict_types=1);

namespace CanonicalMapper\Resolution;

/**
 * Why an item was withheld.
 *
 * Three cases, and the list is meant to stay short. A reason code exists so that
 * a report can be filtered and counted; the sentence explaining what to verify
 * lives on the Flag, where it can name the actual product and value.
 */
enum FlagReason: string
{
    /** A net price arrived with a VAT code this mapper does not know a rate for. */
    case TaxBasisUnknown = 'TAX_BASIS_UNKNOWN';

    /** A composite referenced a component that is not present in the file. */
    case ComponentMissing = 'COMPONENT_MISSING';

    /** Two promotions cover the same item on the same day with different results. */
    case PromotionConflict = 'PROMOTION_CONFLICT';
}
