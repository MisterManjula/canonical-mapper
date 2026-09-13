<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests\Infrastructure;

use CanonicalMapper\Infrastructure\Cli\CommandOutput;
use CanonicalMapper\Infrastructure\Cli\ExitCode;
use CanonicalMapper\Infrastructure\Cli\NormaliseCommand;
use PHPUnit\Framework\TestCase;

/**
 * What the command decides, asserted as values.
 *
 * The command returns two strings and a code rather than printing them, so
 * everything here is an ordinary assertion — no output buffer, no process, no
 * temporary file. What that leaves untested is the four lines of bin/normalise
 * that hand those values to the streams, and BinNormaliseTest runs the real
 * entry point precisely because those four lines are the ones this file cannot
 * reach.
 *
 * The subject is the same distinction the whole repository is about, arriving
 * for the first time somewhere a program can see it. Exit 1 says nothing was
 * produced; exit 3 says a menu was produced and a person has a question waiting.
 * A caller that conflated them would either stop the pipeline on every ambiguity
 * or publish under every one of them.
 */
final class NormaliseCommandTest extends TestCase
{
    public function testACleanExportIsTheMenuOnStdoutAndSilenceOnStderr(): void
    {
        $output = self::normalise('beta', 'equivalence/beta.xml');

        self::assertSame(ExitCode::Resolved, $output->exitCode, 'A run with nothing ambiguous in it did not exit zero');
        self::assertSame(self::fixture('equivalence/expected.json'), $output->stdout, 'stdout is not the canonical menu');

        // Not an empty line and not a cheerful sentence. stderr is where somebody
        // looks to find out whether there is work, and silence is the answer that
        // costs them nothing to read.
        self::assertSame('', $output->stderr, 'A run that withheld nothing still wrote to stderr');
    }

    public function testAWithheldItemIsAskedAboutOnStderrWhileTheMenuIsStillPublished(): void
    {
        $output = self::normalise('beta', 'ambiguous/tax-basis.xml');

        self::assertSame(ExitCode::Withheld, $output->exitCode, 'A run that withheld an item did not exit 3');

        // Both halves, which is the point. The two clear products are published
        // exactly as they would have been, and the third is a question rather
        // than a reason to have produced nothing.
        self::assertStringContainsString('"sku": "1204"', $output->stdout, 'The withheld item took its neighbours with it');
        self::assertStringNotContainsString('"sku": "1100"', $output->stdout, 'The item in question was published anyway');
        self::assertStringContainsString('TAX_BASIS_UNKNOWN', $output->stderr, 'Nothing on stderr says what was withheld');
    }

    public function testTheReportIsOneJsonObjectPerLine(): void
    {
        $output = self::normalise('alpha', 'ambiguous/promotion-conflict.json');
        $lines = explode("\n", rtrim($output->stderr, "\n"));

        self::assertCount(1, $lines, 'One withheld item did not produce exactly one line');

        $decoded = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            self::fail('A line of the flag report is not a JSON object');
        }

        // Every field a person acts on, and the shape a consumer can count on:
        // which system to open, what to search for there, and one sentence saying
        // what to look at.
        self::assertSame('AlphaPos', $decoded['source'] ?? null, 'The report did not name the system to open');
        self::assertSame('000099', $decoded['sourceProductId'] ?? null, 'The report did not quote the id as the source wrote it');
        self::assertSame('99', $decoded['sku'] ?? null, 'The report lost the canonical SKU');
        self::assertSame('PROMOTION_CONFLICT', $decoded['reason'] ?? null, 'The report did not carry a filterable reason');
        self::assertIsString($decoded['detail'] ?? null, 'The report did not carry a sentence for a person');
    }

    public function testTheReportKeepsTheSkuKeyEvenWhenThereIsNoSku(): void
    {
        // A consumer should not have to distinguish "no SKU" from "this writer
        // omits the key sometimes", so the key is always there and sometimes
        // null. There is no fixture whose flag lacks one, which is why this is
        // asserted on the writer's shape rather than on a file.
        $output = self::normalise('beta', 'ambiguous/tax-basis.xml');

        self::assertStringContainsString('"sku":', $output->stderr, 'The report dropped a key rather than writing it null');
    }

    public function testAnExportThatCannotBeReadProducesNothingOnStdout(): void
    {
        // A consumer redirecting stdout to a file gets an empty file rather than
        // half a menu, and the reason is on the other stream where a person is.
        $output = self::normalise('beta', 'equivalence/alpha.json');

        self::assertSame(ExitCode::Refused, $output->exitCode, 'An unreadable export did not exit 1');
        self::assertSame('', $output->stdout, 'A refused run still wrote something to stdout');
        self::assertStringContainsString('BetaPos', $output->stderr, 'The message did not say which format it failed to read');
    }

    public function testARefusedRunAndAWithheldRunAreDifferentAnswers(): void
    {
        // The assertion the exit codes exist for. One of these files is broken
        // and the other is fine and asks a question, and a caller can tell them
        // apart without parsing anything.
        $broken = self::normalise('beta', 'equivalence/alpha.json');
        $ambiguous = self::normalise('beta', 'ambiguous/tax-basis.xml');

        self::assertNotSame(
            $broken->exitCode,
            $ambiguous->exitCode,
            'A broken export and a withheld item report the same thing to the shell',
        );
        self::assertSame('', $broken->stdout, 'The broken export produced a menu');
        self::assertNotSame('', $ambiguous->stdout, 'The export with one question produced no menu at all');
    }

    public function testASourceNameThisMapperDoesNotHaveListsTheOnesItDoes(): void
    {
        $output = (new NormaliseCommand())->run(['deltapos', self::path('equivalence/beta.xml')]);

        self::assertSame(ExitCode::Refused, $output->exitCode, 'An unknown source name did not exit 1');
        self::assertStringContainsString('alpha, beta, gamma', $output->stderr, 'The message did not list the sources that exist');
    }

    public function testAPathWithNoFileAtItIsRefusedRatherThanReadAsAnEmptyExport(): void
    {
        $output = (new NormaliseCommand())->run(['beta', self::path('equivalence/nothing-here.xml')]);

        self::assertSame(ExitCode::Refused, $output->exitCode, 'A missing file did not exit 1');
        self::assertStringContainsString('nothing-here.xml', $output->stderr, 'The message did not name the path it tried');
    }

    public function testTheWrongNumberOfArgumentsIsAnsweredWithTheUsage(): void
    {
        foreach ([[], ['beta'], ['beta', 'one.xml', 'two.xml']] as $arguments) {
            $output = (new NormaliseCommand())->run($arguments);

            self::assertSame(ExitCode::Refused, $output->exitCode, 'A malformed command line did not exit 1');
            self::assertStringContainsString('usage: bin/normalise', $output->stderr, 'Nothing told the caller how to call it');
        }
    }

    private static function normalise(string $source, string $fixture): CommandOutput
    {
        return (new NormaliseCommand())->run([$source, self::path($fixture)]);
    }

    private static function path(string $fixture): string
    {
        return dirname(__DIR__, 2) . '/fixtures/' . $fixture;
    }

    private static function fixture(string $path): string
    {
        $contents = file_get_contents(self::path($path));

        if ($contents === false) {
            self::fail(sprintf('Could not read the fixture %s', $path));
        }

        self::assertStringNotContainsString(
            "\r\n",
            $contents,
            sprintf('The fixture %s has CRLF line endings; check .gitattributes', $path),
        );

        return $contents;
    }
}
