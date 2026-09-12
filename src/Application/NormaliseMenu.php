<?php

declare(strict_types=1);

namespace CanonicalMapper\Application;

use CanonicalMapper\Application\Port\MalformedSource;
use CanonicalMapper\Application\Port\SourceAdapter;
use CanonicalMapper\Domain\Canonical\CanonicalMenu;
use CanonicalMapper\Domain\Canonical\Item;
use CanonicalMapper\Domain\InvariantViolated;
use CanonicalMapper\Domain\Resolution\Flag;
use CanonicalMapper\Domain\Resolution\Unresolved;

/**
 * Runs an adapter and decides what to do with what it reports.
 *
 * Containment lives here, and it is one `continue`: an item that could not be
 * resolved is set aside with its flag, and the loop carries on. That is the whole
 * of "a withheld item does not block unrelated items", and it is deliberately not
 * spread across the adapters — three copies of this decision would be three
 * chances to make it differently, and the guarantee is that it is made once.
 */
final class NormaliseMenu
{
    /**
     * @throws MalformedSource when the export cannot be read at all
     */
    public function run(SourceAdapter $adapter, string $contents): MappingResult
    {
        $items = [];
        $flags = [];

        foreach ($adapter->read($contents) as $resolution) {
            if ($resolution instanceof Unresolved) {
                $flags[] = $resolution->flag;

                continue;
            }

            $items[] = $resolution->value;
        }

        // The one invariant that cannot be checked one item at a time, and so the
        // one an adapter cannot check for itself: two products with the same SKU
        // are only visible once the whole export has been read. The domain refuses
        // the menu, and the refusal is translated here because this is the layer
        // that knows the items came out of a file — the domain does not, and
        // MalformedSource is a statement about a file.
        try {
            return MappingResult::of(CanonicalMenu::of($items), $flags);
        } catch (InvariantViolated $violation) {
            throw new MalformedSource($violation->detail);
        }
    }
}
