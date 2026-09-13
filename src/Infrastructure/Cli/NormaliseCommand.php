<?php

declare(strict_types=1);

namespace CanonicalMapper\Infrastructure\Cli;

use CanonicalMapper\Application\NormaliseMenu;
use CanonicalMapper\Application\Port\MalformedSource;
use CanonicalMapper\Infrastructure\Output\CanonicalJsonWriter;
use CanonicalMapper\Infrastructure\Output\FlagReportWriter;
use CanonicalMapper\Infrastructure\Source\SourceSystem;
use JsonException;

/**
 * The whole of the command line: two arguments in, two streams and an exit code
 * out.
 *
 * Everything it does is wiring. It chooses an adapter from a name, reads a file
 * because opening files is not an adapter's job, hands both to the use case, and
 * turns what comes back into bytes and a number. There is no decision here about
 * what is ambiguous or what is broken — those were made in the domain and the
 * use case, and a CLI that made them again would be a second opinion.
 *
 * The one thing that is decided here is the exit code, because it is the only
 * statement in this project addressed to a program rather than to a person. Its
 * three values are in ExitCode, with the argument for the distance between them.
 *
 * The result is returned rather than printed. `bin/normalise` is the only file
 * that writes to a stream or ends a process, which keeps this testable as
 * ordinary values and keeps the untestable part down to four lines that do
 * nothing but hand them over.
 *
 * RoundingRequired is deliberately not caught. It is a LogicException and it
 * means an assumption of this codebase is wrong rather than that a bad file
 * arrived; a stack trace is the honest report for that, and an exit code would
 * be a way of filing it away.
 */
final class NormaliseCommand
{
    private const USAGE = 'usage: bin/normalise <alpha|beta|gamma> <file>';

    /**
     * @param list<string> $arguments the command line with the program name
     *                                already removed
     */
    public function run(array $arguments): CommandOutput
    {
        if (count($arguments) !== 2) {
            return CommandOutput::refused(self::USAGE);
        }

        $source = SourceSystem::tryFrom($arguments[0]);

        if ($source === null) {
            // The three names are listed rather than described, because the
            // person reading this has just typed a fourth and the useful reply is
            // the set they can choose from.
            return CommandOutput::refused(sprintf(
                'There is no source called "%s". This mapper reads %s.',
                $arguments[0],
                implode(', ', SourceSystem::names()),
            ));
        }

        $contents = self::read($arguments[1]);

        if ($contents === null) {
            return CommandOutput::refused(sprintf('There is no readable file at %s.', $arguments[1]));
        }

        try {
            $result = (new NormaliseMenu())->run($source->adapter(), $contents);

            // Both streams, and the exit code is the only thing that distinguishes
            // this run from a clean one. A withheld item does not stop the menu
            // being published: the rest of the assortment is exactly as correct as
            // it would have been, and holding it back would make one ambiguous
            // product cost an import.
            return CommandOutput::of(
                (new CanonicalJsonWriter())->write($result->menu),
                (new FlagReportWriter())->write($result->flags),
                $result->flags === [] ? ExitCode::Resolved : ExitCode::Withheld,
            );
        } catch (MalformedSource $malformed) {
            // The export could not be read at all, which is a different thing
            // from an export that was read and left a question. This is the
            // branch that becomes exit 1, and the one above becomes exit 3.
            return CommandOutput::refused(sprintf(
                '%s could not be read as a %s export: %s',
                $arguments[1],
                $source->displayName(),
                $malformed->detail,
            ));
        } catch (JsonException $notEncodable) {
            // Reachable, and only from the one source that hands bytes through
            // untouched: a JSON export that was not UTF-8 failed to decode long
            // before this, and an XML one failed to parse, but a CSV name is
            // whatever the file contained. It is a broken export rather than an
            // ambiguous one, and it is refused as one.
            return CommandOutput::refused(sprintf(
                '%s produced text that cannot be written as JSON, which usually means the '
                . 'export is not UTF-8: %s',
                $arguments[1],
                $notEncodable->getMessage(),
            ));
        }
    }

    /**
     * The file, or null when there is nothing at that path to read.
     *
     * Asked before it is opened rather than suppressed afterwards. file_get_contents
     * emits a warning on a missing path, and a silenced call would be this project
     * hiding a diagnostic it elsewhere insists on; the false branch is still
     * handled, because between the question and the answer a file can still go.
     */
    private static function read(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }
}
