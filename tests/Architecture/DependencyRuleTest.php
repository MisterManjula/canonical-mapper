<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Dependencies point inward, asserted rather than drawn.
 *
 * Three layers and one rule: Domain depends on nothing in this project,
 * Application depends on Domain, Infrastructure depends on both. Every
 * architecture document in the world says something like that, and the ones
 * that are merely said drift within a release or two — the violation arrives as
 * one convenient import in a hurry, it is invisible in review because it looks
 * like every other import, and by the time anybody notices there are nine of
 * them and the layering is a diagram nobody believes.
 *
 * The refactor that produced these layers carried two deliberate violations for
 * a few commits each: the domain imported MalformedSource until it had an
 * exception of its own, and the SourceAdapter port returned an enum that was on
 * its way out of the domain. Both were acceptable while they were known about
 * and named. This is what stops the next one being neither.
 *
 * The check is on the text of the file rather than on parsed imports, which is
 * blunter than it needs to be and deliberately so. A fully-qualified name
 * written inline is the same dependency as an import and would slip past a
 * check that only read `use` lines; a docblock that names an outer class by its
 * full path is documentation the domain should not be in a position to write
 * either. The plain class names that appear in prose here — MalformedSource is
 * discussed in InvariantViolated, and has to be — are untouched by this, because
 * a name without a namespace is a reference to an idea rather than to a class.
 */
final class DependencyRuleTest extends TestCase
{
    private const DOMAIN = 'CanonicalMapper\\Domain';

    private const APPLICATION = 'CanonicalMapper\\Application';

    private const INFRASTRUCTURE = 'CanonicalMapper\\Infrastructure';

    public function testTheDomainDependsOnNothingElseInThisProject(): void
    {
        self::assertSame(
            [],
            self::offenders('Domain', [self::APPLICATION, self::INFRASTRUCTURE]),
            'The canonical model reached outward; it is supposed to be the thing the outer layers are written against',
        );
    }

    public function testTheApplicationDependsOnTheDomainAndNotOnInfrastructure(): void
    {
        self::assertSame(
            [],
            self::offenders('Application', [self::INFRASTRUCTURE]),
            'The use case or its ports named a concrete adapter, which is the dependency the ports exist to invert',
        );
    }

    /**
     * The check above passes trivially if it reads no files, and a rule that
     * cannot fail is worse than no rule because it looks like one. This is the
     * assertion that the scan is actually finding source to scan.
     */
    public function testTheScanReachesEveryLayerItClaimsToCover(): void
    {
        self::assertGreaterThan(
            5,
            count(self::sourcesIn('Domain')),
            'The Domain scan found almost no files, so the rule above proved nothing',
        );

        self::assertGreaterThan(
            2,
            count(self::sourcesIn('Application')),
            'The Application scan found almost no files, so the rule above proved nothing',
        );

        // The namespaces the rule is written in terms of have to be the ones the
        // code actually uses. A layer renamed without this test being updated
        // would otherwise turn both rules into assertions about nothing.
        self::assertStringContainsString(
            'namespace ' . self::DOMAIN,
            self::read(self::layer('Domain') . '/Canonical/Money.php'),
            'The Domain namespace is not what this test believes it is',
        );
    }

    /**
     * @param non-empty-list<string> $forbidden
     *
     * @return list<string>
     */
    private static function offenders(string $layer, array $forbidden): array
    {
        $offenders = [];

        foreach (self::sourcesIn($layer) as $file) {
            $contents = self::read($file);

            foreach ($forbidden as $namespace) {
                if (str_contains($contents, $namespace)) {
                    $offenders[] = sprintf('%s references %s', self::relative($file), $namespace);
                }
            }
        }

        sort($offenders);

        return $offenders;
    }

    /**
     * @return list<string>
     */
    private static function sourcesIn(string $layer): array
    {
        return self::globRecursively(self::layer($layer));
    }

    private static function layer(string $layer): string
    {
        return dirname(__DIR__, 2) . '/src/' . $layer;
    }

    private static function relative(string $file): string
    {
        return substr($file, strlen(dirname(__DIR__, 2)) + 1);
    }

    private static function read(string $file): string
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            self::fail(sprintf('Could not read %s', $file));
        }

        return $contents;
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
}
