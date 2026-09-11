<?php

declare(strict_types=1);

namespace CanonicalMapper\Source;

use CanonicalMapper\Canonical\Item;
use CanonicalMapper\MalformedSource;
use CanonicalMapper\Resolution\Resolved;
use CanonicalMapper\Resolution\SourceSystem;
use CanonicalMapper\Resolution\Unresolved;

/**
 * Turns one export into a list of items that either resolved or did not.
 *
 * The return type is the union rather than the Resolution interface, and that is
 * the load-bearing detail: PHPStan narrows by subtracting a class from a union,
 * so an implementation that forgets to handle a case it declared it might meet
 * cannot pass analysis. Writing Resolution<Item> here would switch that off for
 * every adapter at once.
 *
 * An adapter reads a string rather than a path. Opening files is the CLI's job,
 * and an adapter that did it too would make every test need a file on disk for
 * cases that are three lines of XML.
 *
 * Adapters do not assemble a menu, sort anything, or decide what to do about a
 * value they could not resolve. They report, one item at a time; the Mapper
 * decides. That split is what keeps containment and cascade in one place instead
 * of in three.
 */
interface SourceAdapter
{
    public function system(): SourceSystem;

    /**
     * @return list<Resolved<Item>|Unresolved>
     *
     * @throws MalformedSource when the export cannot be read at all
     */
    public function read(string $contents): array;
}
