<?php

declare(strict_types=1);

namespace Porta\Composer\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Composer\OutdatedPackage;
use Porta\Composer\OutdatedReport;

/** What `composer outdated --format=json` says, read into what is behind and what is abandoned. */
final class OutdatedReportTest extends TestCase
{
    public function testWhatIsBehindOrAbandonedIsKeptByName(): void
    {
        $packages = OutdatedReport::parse(\json_encode(
            [
                'installed' => [
                    ['name' => 'Z/Last', 'version' => '1.0.0', 'latest' => '1.1.0', 'latest-status' => 'semver-safe-update', 'abandoned' => false],
                    ['name' => 'a/major', 'version' => '1.2.0', 'latest' => '2.0.0', 'latest-status' => 'update-possible'],
                    ['name' => 'b/fine', 'version' => '3.0.0', 'latest' => '3.0.0', 'latest-status' => 'up-to-date'],
                    ['name' => 'c/gone', 'version' => '1.0.0', 'latest' => '1.0.0', 'latest-status' => 'up-to-date', 'abandoned' => 'd/new'],
                    ['name' => 'e/gone', 'version' => '1.0.0', 'latest' => '1.0.0', 'latest-status' => 'up-to-date', 'abandoned' => true],
                    ['name' => 'f/nostatus', 'version' => '1.0.0', 'latest' => '1.5.0'],
                    ['name' => 'g/broken', 'version' => 1],
                    'not an entry',
                ],
            ],
            \JSON_THROW_ON_ERROR,
        ));

        $this->assertEquals(
            [
                new OutdatedPackage('a/major', '1.2.0', '2.0.0', 'update-possible'),
                new OutdatedPackage('c/gone', '1.0.0', '1.0.0', 'up-to-date', 'd/new'),
                new OutdatedPackage('e/gone', '1.0.0', '1.0.0', 'up-to-date', ''),
                new OutdatedPackage('f/nostatus', '1.0.0', '1.5.0', 'update-possible'),
                new OutdatedPackage('z/last', '1.0.0', '1.1.0', 'semver-safe-update'),
            ],
            $packages,
        );
    }

    public function testHowFarBehindAPackageIs(): void
    {
        $safe = new OutdatedPackage('a/a', '1.0.0', '1.1.0', 'semver-safe-update');
        $this->assertTrue($safe->behind());
        $this->assertTrue($safe->withinConstraint());
        $this->assertSame('↑', $safe->glyph());

        $major = new OutdatedPackage('a/a', '1.0.0', '2.0.0', 'update-possible');
        $this->assertFalse($major->withinConstraint());
        $this->assertSame('⇑', $major->glyph());

        $gone = new OutdatedPackage('a/a', '1.0.0', '1.0.0', 'up-to-date', '');
        $this->assertFalse($gone->behind());
        $this->assertSame('⚠', $gone->glyph());
        $this->assertFalse(new OutdatedPackage('a/a', '1.0.0', '1.0.0', 'update-possible')->behind(), 'the same version is not behind, whatever the status');
        $this->assertFalse(new OutdatedPackage('a/a', '1.0.0', '1.1.0', 'up-to-date')->behind(), 'nor is one composer says is up to date');
    }

    /** @return iterable<string, array{string}> */
    public static function notReports(): iterable
    {
        yield 'not JSON' => ['Composer could not find a composer.json'];
        yield 'not an object' => ['"installed"'];
        yield 'no installed list' => ['{"installed": 3}'];
    }

    #[DataProvider('notReports')]
    public function testWhatIsNotAReportSaysSo(string $output): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('composer outdated printed no report');

        OutdatedReport::parse($output);
    }
}
