<?php

declare(strict_types=1);

namespace CanonicalMapper\Domain\Resolution;

/**
 * A value that was determined with confidence, and the only door into the
 * canonical model: $value exists here and nowhere else.
 *
 * There is no map() and no andThen(). A functor API would be safe — an unresolved
 * value still could not reach a canonical constructor through one — but it would
 * hide the branch, and the branch is the subject of this repository rather than
 * an inconvenience on the way to the result. It would also produce the wrong
 * flag on cascade: a composite withheld because one of its components could not
 * be resolved is a different fact from the component's own failure, and deserves
 * a flag naming the composite.
 *
 * @template-covariant T
 *
 * @implements Resolution<T>
 */
final class Resolved implements Resolution
{
    /**
     * @param T $value
     */
    private function __construct(public readonly mixed $value)
    {
    }

    /**
     * @template V
     *
     * @param V $value
     *
     * @return self<V>
     */
    public static function of(mixed $value): self
    {
        return new self($value);
    }
}
