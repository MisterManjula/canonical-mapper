<?php

declare(strict_types=1);

namespace CanonicalMapper\Infrastructure\Source;

use CanonicalMapper\Domain\Resolution\SourceName;

/**
 * Which export a value came from.
 *
 * An enum rather than a string, because the same three names are needed in three
 * places — the CLI argument, the flag a human reads, and the report writer — and
 * three copies of a string literal is three chances for them to drift. It also
 * buys exhaustiveness: a fourth source added later makes every match over this
 * enum fail analysis, which is where the reminder belongs.
 *
 * "Where the reminder belongs" is Infrastructure, and that is why this file is
 * here rather than in the canonical model. The list of sources that exist is the
 * one fact in this project guaranteed to change, and a fourth case should oblige
 * someone to revisit the adapters, the CLI and the wiring — not the types that
 * describe a menu. The domain is told a name and nothing more; see SourceName.
 */
enum SourceSystem: string
{
    case Alpha = 'alpha';
    case Beta = 'beta';
    case Gamma = 'gamma';

    /**
     * The name as it appears in a flag a human has to act on. "BetaPos", not
     * "beta": the person reading the report opens that system, not this CLI.
     */
    public function displayName(): string
    {
        return match ($this) {
            self::Alpha => 'AlphaPos',
            self::Beta => 'BetaPos',
            self::Gamma => 'GammaPos',
        };
    }

    /**
     * The same name, in the form the domain accepts.
     *
     * This is the whole of the conversion between "which of our three sources"
     * and "what a flag should say", and it happens here because this is the last
     * place that knows there are three.
     */
    public function sourceName(): SourceName
    {
        return SourceName::of($this->displayName());
    }
}
