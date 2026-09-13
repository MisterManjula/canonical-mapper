<?php

declare(strict_types=1);

namespace CanonicalMapper\Domain\Rule;

use CanonicalMapper\Domain\Canonical\Item;
use CanonicalMapper\Domain\Canonical\Sku;
use CanonicalMapper\Domain\Resolution\Flag;
use CanonicalMapper\Domain\Resolution\Resolved;
use CanonicalMapper\Domain\Resolution\SourceName;
use CanonicalMapper\Domain\Resolution\Unresolved;

/**
 * The other half of withholding: it propagates upward.
 *
 * Containment says a withheld item does not take its neighbours with it. A
 * composite is not a neighbour. If one of its components is not in this export,
 * then what the composite *is* has not been stated, and publishing it with a
 * shortened recipe would be a guess about whether the absent line mattered — so
 * the composite is withheld too, and gets a flag of its own rather than
 * inheriting the component's.
 *
 * This lives in the domain because a dangling component is source-neutral: any
 * format can express one, by omission if by nothing else. It used to live in one
 * adapter, which meant the other two either duplicated it or — as BetaPos in fact
 * did — published canonical JSON referencing a SKU that was not in the menu.
 * Three copies of one idea are three chances to write it differently; here it is
 * written once, and a fourth source gets it without knowing it exists.
 *
 * The rule cannot tell "this component was withheld" from "this component was
 * never described", and does not need to. Both are the same fact from the
 * composite's side — it references something that is not in this export — and one
 * wording covers them. Where the component failed for a reason of its own, the
 * person acting on this flag is holding that component's flag as well.
 *
 * Iterative rather than recursive, so that both of the properties worth believing
 * are visible in the loop instead of argued about. Transitivity: a composite of a
 * composite is handled because the withheld parent leaves the set of present
 * SKUs, and the next pass reads the smaller set. Termination: a pass either
 * withholds something, which shrinks that set, or changes nothing and ends — so
 * the number of passes is bounded by the number of items.
 */
final class ComponentCascadeRule
{
    /**
     * @param list<Resolved<Item>|Unresolved> $resolutions
     *
     * @return list<Resolved<Item>|Unresolved>
     */
    public static function apply(SourceName $source, array $resolutions): array
    {
        $present = [];

        foreach ($resolutions as $resolution) {
            if ($resolution instanceof Resolved) {
                $present[$resolution->value->sku->value] = true;
            }
        }

        do {
            $changed = false;
            $pass = [];

            foreach ($resolutions as $resolution) {
                if ($resolution instanceof Unresolved) {
                    $pass[] = $resolution;

                    continue;
                }

                $item = $resolution->value;
                $absent = self::firstAbsentComponent($item, $present);

                if ($absent === null) {
                    $pass[] = $resolution;

                    continue;
                }

                // Out of the set before the next pass reads it. This one line is
                // the cascade: whatever contains this composite will find it
                // absent and be withheld in its turn, without the rule ever
                // treating a composite of a composite as a case.
                unset($present[$item->sku->value]);

                $pass[] = Unresolved::because(Flag::componentMissing($source, $item->sku, $absent));
                $changed = true;
            }

            $resolutions = $pass;
        } while ($changed);

        return $resolutions;
    }

    /**
     * Only the first absent component is named. A flag is a work item, and the
     * person acting on it has the whole export open by the time they have found
     * the second.
     *
     * @param array<string, true> $present
     */
    private static function firstAbsentComponent(Item $item, array $present): ?Sku
    {
        foreach ($item->components as $component) {
            if (!isset($present[$component->sku->value])) {
                return $component->sku;
            }
        }

        return null;
    }
}
