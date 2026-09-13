<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests\Application;

use CanonicalMapper\Application\Port\SourceAdapter;
use CanonicalMapper\Domain\Canonical\Item;
use CanonicalMapper\Domain\Resolution\Resolved;
use CanonicalMapper\Domain\Resolution\SourceName;
use CanonicalMapper\Domain\Resolution\Unresolved;

/**
 * An adapter whose answers are decided in advance.
 *
 * The port exists so that the use case can be driven without a file, and this is
 * what collects on that. A test written against a real adapter has to express
 * "an item that could not be resolved" as a tax code in an XML document, which
 * means the test of containment fails when the XML parser breaks — and reads as
 * though containment were a property of BetaPos rather than of the use case.
 *
 * Not a mock. There is nothing to verify about how this was called, only what it
 * returns, and a hand-written double with one constructor is easier to read a
 * year later than an expectation script. The suite has no mocking library for
 * this reason and does not need one.
 */
final class FixedAdapter implements SourceAdapter
{
    /**
     * @param list<Resolved<Item>|Unresolved> $resolutions
     */
    public function __construct(private readonly array $resolutions)
    {
    }

    public function sourceName(): SourceName
    {
        return SourceName::of('TestPos');
    }

    /**
     * The contents are ignored, deliberately. Whatever this adapter was going to
     * read, it has already read.
     *
     * @return list<Resolved<Item>|Unresolved>
     */
    public function read(string $contents): array
    {
        return $this->resolutions;
    }
}
