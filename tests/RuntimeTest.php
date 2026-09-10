<?php

declare(strict_types=1);

namespace CanonicalMapper\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The suite is not allowed to be empty, and the honest way to satisfy that before
 * any domain code exists is not to switch the check off but to assert the things
 * every later step silently assumes about the container.
 *
 * Each of the three extensions below is one of the three source formats: without
 * json there is no AlphaPos, without dom and libxml there is no BetaPos. If the
 * base image ever changes, this fails here rather than as a confusing parse error
 * three steps later.
 *
 * The PHP version is deliberately not asserted. `"php": "^8.3"` in composer.json is
 * the stronger check — Composer refuses to install at all on the wrong runtime —
 * and PHPStan constant-folds PHP_VERSION_ID against its configured phpVersion, so
 * the assertion would be reported as always true rather than read as a check.
 */
final class RuntimeTest extends TestCase
{
    public function testTheContainerProvidesTheExtensionsTheAdaptersParseWith(): void
    {
        self::assertTrue(extension_loaded('json'), 'AlphaPos exports are JSON and ext-json is missing');
        self::assertTrue(extension_loaded('dom'), 'BetaPos exports are XML and ext-dom is missing');
        self::assertTrue(extension_loaded('libxml'), 'ext-dom without libxml cannot load a document');
    }
}
