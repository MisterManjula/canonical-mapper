<?php

declare(strict_types=1);

namespace CanonicalMapper\Domain\Rule;

use CanonicalMapper\Domain\Canonical\Promotion;
use CanonicalMapper\Domain\Canonical\Sku;
use CanonicalMapper\Domain\Resolution\Flag;
use CanonicalMapper\Domain\Resolution\Resolved;
use CanonicalMapper\Domain\Resolution\SourceName;
use CanonicalMapper\Domain\Resolution\Unresolved;

/**
 * Reduces what a source said about a product's promotions to the one promotion
 * the canonical model can carry, or withholds the product for want of it.
 *
 * An Item holds at most one Promotion, and that is a decision rather than an
 * oversight: a list would be a price-list schedule, which this project states it
 * does not do. So a source that mentions two promotions for one product has said
 * something the canonical model cannot hold, and the question of which one the
 * menu means is a question for a person. Publishing the item at its usual price
 * would answer it with "neither", and publishing one of the two would answer it
 * by whichever the file happened to list first.
 *
 * The sharpest case is two promotions in force on days they share: there is no
 * single price for those days whatever the model can hold, which is the case
 * section 5 of the specification describes. It is not a separate rule, because
 * the answer is the same one and a second rule would be a second chance to give
 * a different answer. It is a different sentence, chosen on the flag.
 *
 * Identical promotions are not two promotions. A source assembling an export
 * from two queries can state one offer twice; both statements answer the same
 * question the same way, so they collapse. Anything less than identical does
 * not: two offers at one price over different ranges are two offers, and merging
 * them would publish a range neither of them states.
 *
 * Unlike the cascade rule this one runs per product, while the adapter is still
 * reading it, because a conflict is visible in one product's own entry and needs
 * nothing from the rest of the export. That is also why its flag can still quote
 * the identifier as the source spells it: the adapter has it in hand and passes
 * it in, where a rule running over the finished export no longer could.
 */
final class PromotionConflictRule
{
    /**
     * @param list<Promotion> $promotions as the source stated them, in the order
     *                                    it stated them
     *
     * @return Resolved<Promotion|null>|Unresolved
     */
    public static function apply(
        SourceName $source,
        string $sourceProductId,
        ?Sku $sku,
        array $promotions,
    ): Resolved|Unresolved {
        $distinct = self::distinct($promotions);

        // A product with no promotion at all is the ordinary case and the one
        // every item in the equivalence fixture takes, which is why the rule has
        // to be reachable without changing anything for it.
        if (count($distinct) < 2) {
            return Resolved::of($distinct[0] ?? null);
        }

        // The first two, in the order the file stated them. With three there is a
        // pair this does not name, and naming it would not help: the sentence has
        // to end in a person opening the source and looking at that product's
        // promotions, and they will see all three when they do.
        return Unresolved::because(
            Flag::promotionConflict($source, $sourceProductId, $sku, $distinct[0], $distinct[1]),
        );
    }

    /**
     * @param list<Promotion> $promotions
     *
     * @return list<Promotion>
     */
    private static function distinct(array $promotions): array
    {
        $distinct = [];

        foreach ($promotions as $promotion) {
            foreach ($distinct as $kept) {
                if ($kept->equals($promotion)) {
                    continue 2;
                }
            }

            $distinct[] = $promotion;
        }

        return $distinct;
    }
}
