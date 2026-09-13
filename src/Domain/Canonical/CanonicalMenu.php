<?php

declare(strict_types=1);

namespace CanonicalMapper\Domain\Canonical;

use CanonicalMapper\Domain\InvariantViolated;

/**
 * The whole assortment, in canonical order.
 *
 * This is the type the equivalence guarantee is about: three files in three
 * formats produce three of these, and they must be indistinguishable. Ordering is
 * therefore part of the value and not a presentation choice — it is settled here,
 * once, so that the writer has no decision left to make and no opportunity to
 * make it differently for one source than another.
 *
 * The menu is flat. Composites reference their components by SKU and components
 * are ordinary items in the same list, so a product appears exactly once however
 * many recipes mention it.
 */
final class CanonicalMenu
{
    /**
     * @param list<Item> $items
     */
    private function __construct(public readonly array $items)
    {
    }

    /**
     * The two invariants that are only visible once every item is in hand: no SKU
     * appears twice, and no component reference points outside the list.
     *
     * The second is a backstop, and with the cascade rule in place it never fires.
     * That is the point of it rather than an argument against it — it is the same
     * move as a CHECK constraint on a table whose writes all go through one
     * well-tested function. A menu that references a product it does not contain
     * cannot be *constructed*, so the rule is not the only thing standing between
     * a dangling reference and a consumer reading the JSON.
     *
     * The cost is worth stating: a bug in the cascade rule surfaces here, and the
     * boundary reports this as MalformedSource — "the file is broken" — when the
     * truth would be that this codebase is. A message naming a composite and a
     * component that the file plainly contains is the tell.
     *
     * Cycles are not in scope and are not checked. A composite containing itself
     * has all of its components present, so neither this check nor the cascade
     * rule has anything to say about it.
     *
     * @param list<Item> $items
     *
     * @throws InvariantViolated
     */
    public static function of(array $items): self
    {
        $seen = [];

        foreach ($items as $item) {
            // One product, one price. A duplicate SKU means the export answered
            // the same question twice, and the invariant this model is built on
            // is that every item has exactly one determinable price.
            if (isset($seen[$item->sku->value])) {
                throw new InvariantViolated(sprintf('Product %s appears more than once.', $item->sku->value));
            }

            $seen[$item->sku->value] = true;
        }

        // Every item first, because a composite is allowed to name a component
        // that appears after it: the list arrives in whatever order a file used,
        // and it is sorted below rather than relied on above.
        foreach ($items as $item) {
            foreach ($item->components as $component) {
                if (!isset($seen[$component->sku->value])) {
                    throw new InvariantViolated(sprintf(
                        'Composite %s references component %s, which is not on this menu.',
                        $item->sku->value,
                        $component->sku->value,
                    ));
                }
            }
        }

        usort($items, static fn (Item $a, Item $b): int => Sku::compare($a->sku, $b->sku));

        return new self($items);
    }

    public static function empty(): self
    {
        return new self([]);
    }
}
