<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests\Fixtures\PhpStan;

use CanonicalMapper\Canonical\Money;
use CanonicalMapper\Resolution\Resolved;
use CanonicalMapper\Resolution\Unresolved;

/**
 * An adapter written by someone who did not read the ADR: it resolves the prices
 * it knows how to resolve and simply stops when it meets a VAT code it does not
 * recognise.
 *
 * This file does not compile past PHPStan, on purpose. It is the evidence behind
 * the central claim of this repository, and ResolutionCannotBeBypassedTest runs
 * the analyser over it and asserts the error. It is excluded from the main
 * analysis and from the autoloader's classmap, and is never executed.
 *
 * Its only difference from HandledBranchAdapter is the missing branch. Keep it
 * that way: the pair is the experiment, and a second difference would make the
 * result mean less.
 */
final class ForgottenBranchAdapter
{
    /**
     * @return Resolved<Money>|Unresolved
     */
    public function price(string $sourceProductId, int $netMinorUnits, string $vatCode): Resolved|Unresolved
    {
        $rate = self::rateFor($vatCode);

        if ($rate !== null) {
            return Resolved::of(Money::fromNetMinorUnitsAndVatPercent($netMinorUnits, $rate));
        }
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
