<?php

declare(strict_types=1);

namespace CanonicalMapper\Infrastructure\Source\Beta;

/**
 * The tax codes BetaPos writes, and the rates this mapper knows them to mean.
 *
 * The table is small and it is the only place a code becomes a number, which is
 * what makes an unknown code a single, answerable question rather than a
 * scattering of assumptions.
 *
 * rateFor() returns null instead of throwing, because an unrecognised code is not
 * a broken file: the export is perfectly well formed and states a fact this
 * mapper cannot interpret. That distinction is the difference between refusing
 * the file and withholding one item, and it is decided here.
 */
final class VatCode
{
    /**
     * @return int<0, 100>|null null when this mapper has no rate for the code
     */
    public static function rateFor(string $code): ?int
    {
        return match ($code) {
            'V10' => 10,
            'V22' => 22,
            default => null,
        };
    }
}
