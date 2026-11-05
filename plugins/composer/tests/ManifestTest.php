<?php

declare(strict_types=1);

namespace Porta\Composer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Composer\Manifest;

/** Which line of a composer.json requires which package — and which lines only look like they do. */
final class ManifestTest extends TestCase
{
    private const string JSON = <<<'JSON'
        {
            "name": "app/demo",
            "description": "has a { and a \" in it",
            "require": {
                "php": ">=8.5",
                "Vendor/One": "^1.0", "x/inline": "^1"
            },
            "require-dev": {"c/three": "^1.0"},
            "require-dev-not": {
                "d/four": "^1.0"
            },
            "config": {
                "allow-plugins": {
                    "vendor/one": true
                }
            },
            "extra": {
                "require": {
                    "e/five": "*"
                }
            },
            "conflict": [
                "f/six"
            ],
            "suggest": {
                "g/seven": "nice"
            }
        }
        JSON;

    public function testOnlyTheKeysOfRequireAndRequireDevArePackages(): void
    {
        $manifest = new Manifest(self::JSON);

        $this->assertSame(5, $manifest->lineOf('vendor/one'), 'by name, whatever its case');
        $this->assertSame(5, $manifest->lineOf('VENDOR/ONE'));
        $this->assertSame('vendor/one', $manifest->packageAt(5));
        $this->assertNull($manifest->lineOf('x/inline'), 'one key per line: the second on a line is not looked for');
        $this->assertNull($manifest->packageAt(4), 'php is a platform requirement, not a package');
        $this->assertNull($manifest->lineOf('c/three'), 'an object opened and closed on its line holds nothing below it');
        $this->assertNull($manifest->lineOf('d/four'), 'a section that only starts like one');
        $this->assertNull($manifest->packageAt(13), 'allow-plugins names a package, but requires none');
        $this->assertNull($manifest->lineOf('e/five'), 'a "require" deeper down is not the one');
        $this->assertNull($manifest->lineOf('g/seven'));
        $this->assertNull($manifest->packageAt(99));
    }

    public function testADevRequirementCounts(): void
    {
        $manifest = new Manifest("{\n  \"require-dev\": {\n    \"c/three\": \"^1.0\"\n  }\n}\n");

        $this->assertSame(2, $manifest->lineOf('c/three'));
        $this->assertSame('c/three', $manifest->packageAt(2));
    }

    public function testNoTextNoPackages(): void
    {
        $this->assertNull(new Manifest('')->lineOf('a/b'));
    }
}
