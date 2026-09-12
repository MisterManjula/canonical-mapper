<?php

declare(strict_types=1);

namespace CanonicalMapper\Domain\Canonical;

use CanonicalMapper\Domain\InvariantViolated;

/**
 * One line of a composite: which product, and how many of it.
 *
 * A reference rather than a nested item. The three sources disagree about where a
 * component's details live — one nests the whole child object inside the parent,
 * one lists bare codes, one puts the relationship on the child row — and a
 * canonical model that nested them would have to pick one of those shapes and so
 * would still favour one source. A reference plus a flat list of items is the
 * shape none of them has, which is why every one of them can reach it.
 */
final class ComponentRef
{
    /**
     * @param positive-int $quantity
     */
    private function __construct(
        public readonly Sku $sku,
        public readonly int $quantity,
    ) {
    }

    /**
     * @throws InvariantViolated
     */
    public static function of(Sku $sku, int $quantity): self
    {
        // A component present zero times is not a component, and a negative
        // quantity has no meaning on a menu. Both are read as a broken export
        // rather than as an ambiguity worth asking a human about: there is no
        // question to put to anyone, the row is simply not a line of a recipe.
        if ($quantity < 1) {
            throw new InvariantViolated(sprintf(
                'Component %s appears with a quantity of %d.',
                $sku->value,
                $quantity,
            ));
        }

        return new self($sku, $quantity);
    }
}
