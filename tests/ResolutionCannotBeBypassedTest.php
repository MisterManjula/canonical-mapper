<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The claim this repository is built on, asserted rather than described.
 *
 * "An unresolved value cannot reach the canonical model" is a statement about
 * code that does not exist, which is not a thing a normal test can observe: the
 * program that would demonstrate the failure cannot be written, because writing
 * it is what the analyser refuses. So the analyser is the subject under test.
 * Two fixture files differ only in whether the Unresolved branch was handled, and
 * PHPStan is run over each.
 *
 * Asserting on PHPStan's exact wording is only reasonable because composer.lock
 * is committed. The two decisions hold each other up: the lock makes the message
 * stable, and this test is why the message needs to be.
 *
 * PHP has no sealed types and PHPStan has no exhaustiveness check for class
 * hierarchies, so the guarantee is not one rule. It is manufactured from ordinary
 * errors that the encoding makes unavoidable — which is also why the honest
 * limits are worth stating: a line-level ignore annotation, an inline type
 * assertion in a docblock, or an untyped boundary would each defeat it. The third
 * test below is what keeps those out of src/, and "no baseline" in phpstan.neon
 * is the rest.
 */
final class ResolutionCannotBeBypassedTest extends TestCase
{
    public function testAnAdapterThatForgetsTheUnresolvedBranchDoesNotPassAnalysis(): void
    {
        $result = self::analyse('ForgottenBranchAdapter.php');

        self::assertSame(1, $result['exitCode'], "PHPStan accepted an adapter with a missing branch:\n" . $result['output']);

        // The method promises Resolved|Unresolved and falls off the end when the
        // VAT code is unknown. The union in the return type is what turns that
        // into an error: PHP's own return-type check catches the same mistake at
        // runtime, and this catches it before the commit.
        self::assertStringContainsString(
            'but return statement is missing',
            $result['output'],
            'PHPStan rejected the fixture, but not for the missing branch',
        );

        // The opt-in checkTooWideReturnTypesInProtectedAndPublicMethods catches it
        // a second time and from the other direction: a method that declares it
        // may withhold, and never does, has a branch that was never wired up.
        self::assertStringContainsString(
            'never returns CanonicalMapper\Resolution\Unresolved',
            $result['output'],
            'The too-wide return type check did not notice the branch that is never taken',
        );
    }

    public function testTheSameAdapterWithTheBranchHandledPassesAnalysis(): void
    {
        $result = self::analyse('HandledBranchAdapter.php');

        // Without this half, the test above would keep passing if the mechanism
        // were removed entirely and the fixture were merely broken some other way.
        self::assertSame(0, $result['exitCode'], "PHPStan rejected the correctly handled adapter:\n" . $result['output']);
    }

    public function testNoStaticAnalysisEscapeHatchesExistInTheSource(): void
    {
        $hatches = [];

        foreach (self::globRecursively(dirname(__DIR__) . '/src') as $file) {
            $contents = file_get_contents($file);

            if ($contents === false) {
                self::fail(sprintf('Could not read %s', $file));
            }

            // An @var cast asserts a type instead of proving one, and the two
            // ignore annotations switch the analyser off a line at a time. Any of
            // them inside src/ would make the guarantee above a matter of
            // etiquette. Combined with the absence of a baseline, this lets the
            // repository claim there are no suppressions rather than assert it.
            if (preg_match('/@(phpstan-ignore|psalm-suppress|var\s)/', $contents) === 1) {
                $hatches[] = $file;
            }
        }

        self::assertSame([], $hatches, 'Static analysis is being suppressed somewhere in src/');
    }

    /**
     * @return list<string>
     */
    private static function globRecursively(string $directory): array
    {
        $entries = glob($directory . '/*');

        if ($entries === false) {
            return [];
        }

        $files = [];

        foreach ($entries as $entry) {
            if (is_dir($entry)) {
                $files = array_merge($files, self::globRecursively($entry));

                continue;
            }

            if (str_ends_with($entry, '.php')) {
                $files[] = $entry;
            }
        }

        return $files;
    }

    /**
     * @return array{exitCode: int, output: string}
     */
    private static function analyse(string $fixture): array
    {
        $root = dirname(__DIR__);

        $command = sprintf(
            '%s %s analyse --no-progress --error-format=raw --configuration=%s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root . '/vendor/bin/phpstan'),
            escapeshellarg($root . '/tests/Fixtures/PhpStan/phpstan.neon'),
            escapeshellarg($root . '/tests/Fixtures/PhpStan/' . $fixture),
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return ['exitCode' => $exitCode, 'output' => implode("\n", $output)];
    }
}
