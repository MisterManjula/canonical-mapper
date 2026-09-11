<?php

declare(strict_types=1);

namespace CanonicalMapper\Canonical;

use CanonicalMapper\MalformedSource;

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
     * @param list<Item> $items
     *
     * @throws MalformedSource
     */
    public static function of(array $items): self
    {
        $seen = [];

        foreach ($items as $item) {
            // One product, one price. A duplicate SKU means the export answered
            // the same question twice, and the invariant this model is built on
            // is that every item has exactly one determinable price.
            if (isset($seen[$item->sku->value])) {
                throw new MalformedSource(sprintf('Product %s appears more than once.', $item->sku->value));
            }

            $seen[$item->sku->value] = true;
        }

        usort($items, static fn (Item $a, Item $b): int => Sku::compare($a->sku, $b->sku));

        return new self($items);
    }

    public static function empty(): self
    {
        return new self([]);
    }
}
