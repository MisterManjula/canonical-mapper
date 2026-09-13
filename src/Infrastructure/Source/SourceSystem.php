<?php

declare(strict_types=1);

namespace CanonicalMapper\Infrastructure\Source;

use CanonicalMapper\Application\Port\SourceAdapter;
use CanonicalMapper\Domain\Resolution\SourceName;
use CanonicalMapper\Infrastructure\Source\Alpha\AlphaPosAdapter;
use CanonicalMapper\Infrastructure\Source\Beta\BetaPosAdapter;
use CanonicalMapper\Infrastructure\Source\Gamma\GammaPosAdapter;

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

    /**
     * The adapter that reads this source's exports.
     *
     * The whole of the CLI's wiring, and it is one match rather than a container
     * or a factory interface: there are three sources, they are known at compile
     * time, and the indirection would buy nothing that this does not already
     * give. What it does give is the exhaustiveness the class docblock promises —
     * a fourth case added above and forgotten here fails analysis, in the file
     * that already knows how many sources there are.
     */
    public function adapter(): SourceAdapter
    {
        return match ($this) {
            self::Alpha => new AlphaPosAdapter(),
            self::Beta => new BetaPosAdapter(),
            self::Gamma => new GammaPosAdapter(),
        };
    }

    /**
     * The three names this enum answers to, for a message that has to list them.
     *
     * @return non-empty-list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $source): string => $source->value, self::cases());
    }
}
