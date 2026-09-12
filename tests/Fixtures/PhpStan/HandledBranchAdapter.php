<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests\Fixtures\PhpStan;

use CanonicalMapper\Domain\Canonical\Money;
use CanonicalMapper\Domain\Resolution\Flag;
use CanonicalMapper\Domain\Resolution\Resolved;
use CanonicalMapper\Domain\Resolution\SourceName;
use CanonicalMapper\Domain\Resolution\Unresolved;

/**
 * The same adapter method, with the branch handled.
 *
 * This half of the pair matters as much as the broken half. A test that only
 * shows the analyser rejecting bad code has not shown that it accepts the good
 * code — it could be failing for an unrelated reason, and would keep passing if
 * the whole mechanism were removed.
 */
final class HandledBranchAdapter
{
    /**
     * @return Resolved<Money>|Unresolved
     */
    public function price(string $sourceProductId, int $netMinorUnits, string $vatCode): Resolved|Unresolved
    {
        $rate = self::rateFor($vatCode);

        if ($rate === null) {
            return Unresolved::because(
                Flag::taxBasisUnknown(SourceName::of('BetaPos'), $sourceProductId, null, $vatCode),
            );
        }

        return Resolved::of(Money::fromNetMinorUnitsAndVatPercent($netMinorUnits, $rate));
    }

    /**
     * @return int<0, 100>|null
     */
    private static function rateFor(string $vatCode): ?int
    {
        return match ($vatCode) {
            'V10' => 10,
            'V22' => 22,
            default => null,
        };
    }
}
