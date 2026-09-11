<?php

declare(strict_types=1);

namespace CanonicalMapper;

use CanonicalMapper\Canonical\CanonicalMenu;
use CanonicalMapper\Canonical\Item;
use CanonicalMapper\Resolution\Flag;
use CanonicalMapper\Resolution\Unresolved;
use CanonicalMapper\Source\SourceAdapter;

/**
 * Runs an adapter and decides what to do with what it reports.
 *
 * Containment lives here, and it is one `continue`: an item that could not be
 * resolved is set aside with its flag, and the loop carries on. That is the whole
 * of "a withheld item does not block unrelated items", and it is deliberately not
 * spread across the adapters — three copies of this decision would be three
 * chances to make it differently, and the guarantee is that it is made once.
 */
final class Mapper
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

        return MappingResult::of(CanonicalMenu::of($items), $flags);
    }
}
