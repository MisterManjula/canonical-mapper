<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;

/**
 * The entry point, actually run.
 *
 * NormaliseCommandTest asserts on what a run decides, which is almost all of it
 * and is not the part a user meets. What is left over is exactly the part that
 * cannot be asserted on a returned value: that an exit code reaches the shell as
 * an exit status, that the two strings reach two different streams, that the
 * autoloader is found from bin/, and that the shebang line does not end up in
 * the output. Every one of those is a way for the command to be perfectly
 * correct and the program to be useless, so this spawns the real thing.
 *
 * The redirections are the whole apparatus. `2>/dev/null` leaves stdout, and
 * `2>&1 >/dev/null` leaves stderr — in that order, because the first sends
 * stderr to where stdout currently goes and the second then moves stdout away.
 * Written the other way round it would capture nothing and pass for the wrong
 * reason.
 */
final class BinNormaliseTest extends TestCase
{
    public function testAnExportWhereEverythingResolvesIsTheMenuAndExitZero(): void
    {
        $run = self::normalise('beta fixtures/equivalence/beta.xml');

        self::assertSame(0, $run['exitCode'], 'A clean run did not exit zero: ' . $run['stderr']);

        // Byte for byte against the fixture, which is also what rules out the
        // shebang line, a stray warning or a var_dump left behind: anything at
        // all on stdout besides the menu fails this.
        self::assertSame(self::fixture('equivalence/expected.json'), $run['stdout'], 'stdout is not exactly the canonical menu');
        self::assertSame('', $run['stderr'], 'A clean run wrote something to stderr');
    }

    public function testAWithheldItemReachesTheShellAsThree(): void
    {
        $run = self::normalise('beta fixtures/ambiguous/tax-basis.xml');

        // Three rather than one, at the only boundary where the difference can be
        // acted on by something other than a person. This is the claim of the
        // project as a shell script can see it.
        self::assertSame(3, $run['exitCode'], 'A run that withheld an item did not exit 3');
        self::assertStringContainsString('"sku": "99"', $run['stdout'], 'The menu was not published alongside the question');
        self::assertStringContainsString('TAX_BASIS_UNKNOWN', $run['stderr'], 'The report did not reach stderr');
    }

    public function testAnExportThatCannotBeReadReachesTheShellAsOne(): void
    {
        $run = self::normalise('beta fixtures/equivalence/alpha.json');

        self::assertSame(1, $run['exitCode'], 'A broken export did not exit 1');
        self::assertSame('', $run['stdout'], 'A refused run put something on stdout');
        self::assertStringContainsString('could not be read', $run['stderr'], 'The message did not say what went wrong');
    }

    public function testTheMenuAndTheReportDoNotShareAStream(): void
    {
        // The reason the report is on stderr at all: `bin/normalise beta x.xml >
        // menu.json` has to produce a menu.json a consumer can parse, with the
        // questions still on the terminal rather than interleaved into the file.
        $run = self::normalise('alpha fixtures/ambiguous/promotion-conflict.json');

        self::assertStringNotContainsString('PROMOTION_CONFLICT', $run['stdout'], 'A flag leaked into the canonical output');
        self::assertStringNotContainsString('"currency"', $run['stderr'], 'The menu leaked into the flag report');
    }

    public function testTheCommandCanBeCalledWrongWithoutSayingNothing(): void
    {
        $run = self::normalise('');

        self::assertSame(1, $run['exitCode'], 'A call with no arguments did not exit 1');
        self::assertStringContainsString('usage: bin/normalise', $run['stderr'], 'Nothing told the caller how to call it');
    }

    /**
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private static function normalise(string $arguments): array
    {
        $root = dirname(__DIR__, 2);

        $invocation = sprintf(
            '%s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root . '/bin/normalise'),
            $arguments,
        );

        // Two runs rather than one, because separating the streams of a single
        // run needs proc_open and three pipes to prove something these two
        // redirections prove in a line each. The command is a pure function of
        // its arguments, so running it twice says the same thing as running it
        // once and reading both.
        $out = self::capture(sprintf('cd %s && %s 2>/dev/null', escapeshellarg($root), $invocation));
        $err = self::capture(sprintf('cd %s && %s 2>&1 >/dev/null', escapeshellarg($root), $invocation));

        return [
            'stdout' => $out['output'],
            'stderr' => $err['output'],
            'exitCode' => $out['exitCode'],
        ];
    }

    /**
     * @return array{output: string, exitCode: int}
     */
    private static function capture(string $command): array
    {
        $lines = [];
        $exitCode = 0;

        exec($command, $lines, $exitCode);

        // exec() drops the trailing newline of the last line and splits the rest,
        // so the output is reassembled with the newline every line had. A file
        // this writes is a well-formed text file, and the byte comparison above
        // is only meaningful if that survives the round trip.
        return [
            'output' => $lines === [] ? '' : implode("\n", $lines) . "\n",
            'exitCode' => $exitCode,
        ];
    }

    private static function fixture(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/fixtures/' . $path);

        if ($contents === false) {
            self::fail(sprintf('Could not read the fixture %s', $path));
        }

        return $contents;
    }
}
