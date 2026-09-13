<?php

declare(strict_types=1);

namespace CanonicalMapper\Infrastructure\Cli;

/**
 * Everything a run of the command produced, as values rather than as effects.
 *
 * The command decides and this carries the decision out to `bin/normalise`,
 * which is the only place in the project that writes to a stream or ends a
 * process. That split is what makes the CLI testable without spawning anything:
 * a test asserts on two strings and a code, in the same style as every other
 * test here, instead of capturing output buffers and reading exit statuses.
 *
 * It is also the same move the JSON writer makes. Producing the bytes and
 * emitting them are different jobs, and only one of them has a decision in it.
 *
 * Both streams are held at once because a run can legitimately write to both: an
 * export with one withheld item publishes the rest of the menu on stdout and
 * asks its question on stderr, and neither half is the exception.
 */
final class CommandOutput
{
    private function __construct(
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly ExitCode $exitCode,
    ) {
    }

    public static function of(string $stdout, string $stderr, ExitCode $exitCode): self
    {
        return new self($stdout, $stderr, $exitCode);
    }

    /**
     * Nothing on stdout, deliberately: a consumer redirecting stdout to a file
     * gets an empty file rather than half a menu, and the reason is on the other
     * stream where a person can read it.
     */
    public static function refused(string $message): self
    {
        return new self('', $message . "\n", ExitCode::Refused);
    }
}
