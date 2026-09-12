<?php

declare(strict_types=1);

namespace CanonicalMapper\Domain;

use LogicException;

/**
 * An arithmetic step produced a fraction of a minor unit.
 *
 * This project has no rounding policy, and that is a stated limitation rather
 * than an oversight: half-up, half-even and truncation are three different
 * correct answers to a question nobody here has been asked, and choosing one
 * silently would put an invented cent into a customer-facing price.
 *
 * So it is raised, and — unlike InvariantViolated — it is a LogicException that
 * the CLI does not catch. Reaching it does not mean a bad file arrived; it means
 * the claim that these formats never require rounding is false, which is a
 * defect in this codebase's assumptions. A stack trace is the honest report for
 * that, and an exit code would be a way of filing it away.
 */
final class RoundingRequired extends LogicException
{
    private function __construct(public readonly string $operation)
    {
        parent::__construct($operation);
    }

    public static function forVat(int $netMinorUnits, int $vatPercent): self
    {
        return new self(sprintf(
            'Applying %d%% VAT to a net amount of %d would produce a fraction of a cent; '
            . 'the gross price cannot be determined without a rounding policy.',
            $vatPercent,
            $netMinorUnits,
        ));
    }

    public static function forPercentageDiscount(int $grossMinorUnits, int $percent): self
    {
        return new self(sprintf(
            'Taking %d%% off a gross amount of %d would produce a fraction of a cent; '
            . 'the promotional price cannot be determined without a rounding policy.',
            $percent,
            $grossMinorUnits,
        ));
    }
}
