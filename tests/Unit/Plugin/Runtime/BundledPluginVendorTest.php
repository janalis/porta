<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * Vendored code inside a directory mago walks must be excluded from mago, and
 * this is where that is enforced rather than remembered.
 *
 * `[source] paths` covers `src`, `tests` and `plugins` as whole trees, and a
 * plugin may now ship its composer dependencies beside its own code. Left in,
 * a third party's files are linted, analysed **and rewritten** — `composer
 * format` has no exclude list of its own, which is why the cure is a
 * source-level exclude and not three per-tool ones.
 *
 * Its own precondition is measured, not assumed: every glob form was tried
 * against a real vendored file and none matched (see mago.toml), so each such
 * directory needs its own literal line.
 */
final class BundledPluginVendorTest extends TestCase
{
    public function testEveryVendorDirectoryUnderMagosSourcePathsIsExcluded(): void
    {
        $root = \dirname(__DIR__, levels: 4);
        $config = (string) \file_get_contents($root.'/mago.toml');

        // The bundled plugins (none vendor today — this half is a tripwire) and
        // the third-party fixture, which does.
        $vendors = [
            ...self::directories($root.'/plugins/*/vendor'),
            ...self::directories($root.'/tests/Support/fixtures/*/*/vendor'),
        ];
        $this->assertNotSame([], $vendors, 'nothing to check means the test proves nothing');

        foreach ($vendors as $vendor) {
            $relative = \substr($vendor, \strlen($root) + 1);
            $this->assertStringContainsString('"'.$relative.'"', $config, \sprintf('%s must be named in mago.toml\'s [source] excludes', $relative));
        }
    }

    /** @return list<string> */
    private static function directories(string $pattern): array
    {
        $found = \glob($pattern, flags: \GLOB_ONLYDIR);

        return \is_array($found) ? $found : [];
    }
}
