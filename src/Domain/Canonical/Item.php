<?php

declare(strict_types=1);

namespace CanonicalMapper\Domain\Canonical;

use CanonicalMapper\Domain\InvariantViolated;

/**
 * One product on the menu, with exactly one determinable price.
 *
 * That invariant is the whole of guarantee two, and it is kept by what this class
 * refuses to offer rather than by what it checks. There is no nullable price and
 * no "price to be decided later" state, so an item whose price could not be
 * worked out has no half-built form to exist in — it is withheld before it gets
 * here, as an Unresolved, and the canonical model never learns it was considered.
 *
 * Components are sorted on the way in rather than on the way out. Two menus built
 * from the same assortment in different input orders are then the same value, and
 * byte-identical output becomes a property of the model instead of a favour done
 * by the serialiser.
 */
final class Item
{
    /**
     * @param list<ComponentRef> $components
     */
    private function __construct(
        public readonly Sku $sku,
        public readonly string $name,
        public readonly Money $price,
        public readonly array $components,
        public readonly ?Promotion $promotion,
    ) {
    }

    /**
     * @throws InvariantViolated
     */
    public static function simple(Sku $sku, string $name, Money $price, ?Promotion $promotion = null): self
    {
        return new self($sku, self::name($name, $sku), $price, [], $promotion);
    }

    /**
     * A composite's price is its own, not the sum of its parts. Every source
     * states it, and on a menu a breakfast set is routinely cheaper than the
     * items in it; deriving it here would overwrite a real price with an
     * arithmetic one.
     *
     * @param non-empty-list<ComponentRef> $components
     *
     * @throws InvariantViolated
     */
    public static function composite(
        Sku $sku,
        string $name,
        Money $price,
        array $components,
        ?Promotion $promotion = null,
    ): self {
        return new self($sku, self::name($name, $sku), $price, self::sorted($components, $sku), $promotion);
    }

    /**
     * @param non-empty-list<ComponentRef> $components
     *
     * @return non-empty-list<ComponentRef>
     *
     * @throws InvariantViolated
     */
    private static function sorted(array $components, Sku $sku): array
    {
        $seen = [];

        foreach ($components as $component) {
            // One component, one quantity. Two lines for the same child leave the
            // recipe ambiguous — three of it, or four? — and unlike the withheld
            // cases there is no question to put to a human that the export has
            // not already answered twice.
            if (isset($seen[$component->sku->value])) {
                throw new InvariantViolated(sprintf(
                    'Composite %s lists component %s more than once.',
                    $sku->value,
                    $component->sku->value,
                ));
            }

            $seen[$component->sku->value] = true;
        }

        usort($components, static fn (ComponentRef $a, ComponentRef $b): int => Sku::compare($a->sku, $b->sku));

        return $components;
    }

    /**
     * @throws InvariantViolated
     */
    private static function name(string $name, Sku $sku): string
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw new InvariantViolated(sprintf('Product %s has no name.', $sku->value));
        }

        return $trimmed;
    }
}
