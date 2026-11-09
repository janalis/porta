<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Store;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Store\Curated;
use Porta\Plugin\Store\PackagistMetadata;
use Porta\Plugin\Store\StoreCategory;
use Porta\Plugin\Store\StoreEntry;

/**
 * Packagist's answers read into store entries: the release shown is the
 * newest stable one that runs here, read through composer's minified format.
 */
final class PackagistMetadataTest extends TestCase
{
    private const string PACKAGE = 'acme/porta-rector';

    /** The first release in full, as Packagist sends it. */
    private const array FULL = [
        'name' => self::PACKAGE,
        'description' => ' Rector on the current file ',
        'version' => 'v1.2.0',
        'version_normalized' => '1.2.0.0',
        'license' => ['MIT', 7],
        'authors' => [['name' => 'Ada']],
        'source' => ['url' => 'https://github.com/acme/porta-rector.git', 'type' => 'git', 'reference' => 'abc123'],
        'type' => 'porta-plugin',
        'time' => '2026-11-03T10:00:00+00:00',
        'extra' => [
            'porta' => [
                'api' => PluginApi::API_VERSION,
                'store' => [
                    'title' => 'Rector',
                    'category' => 'linters',
                    'screenshots' => ['https://example.com/a.png', 'http://example.com/b.png', 'https://example.com/c d.png', 3],
                    'changelog' => 'docs/CHANGES.md',
                ],
            ],
        ],
        'require' => ['php' => '>=8.0'],
    ];

    /** @param list<array<string, mixed>> $releases */
    private static function p2(array $releases, string $package = self::PACKAGE): string
    {
        return (string) \json_encode(['minified' => 'composer/2.0', 'packages' => [$package => $releases]], \JSON_THROW_ON_ERROR);
    }

    public function testSearchListsEveryPackageNotAbandonedWithItsDownloads(): void
    {
        $json = (string) \json_encode(
            [
                'results' => [
                    ['name' => 'a/porta-one', 'downloads' => 12],
                    ['name' => 'a/porta-two'],
                    ['name' => 'a/porta-gone', 'abandoned' => true],
                    ['name' => 'a/porta-moved', 'abandoned' => 'b/porta-moved'],
                    ['downloads' => 3],
                    'junk',
                ],
            ],
            \JSON_THROW_ON_ERROR,
        );

        $this->assertSame(['a/porta-one' => 12, 'a/porta-two' => 0], PackagistMetadata::search($json));
        $this->assertSame([], PackagistMetadata::search('not json'));
        $this->assertSame([], PackagistMetadata::search('{"results": "nope"}'));
    }

    public function testAnEntryReadsTheReleaseAndWhatThePackageSaysOfItself(): void
    {
        $entry = PackagistMetadata::entry(self::PACKAGE, self::p2([self::FULL]), 40);

        $this->assertInstanceOf(StoreEntry::class, $entry);
        $this->assertSame('rector', $entry->name, 'the name it installs as, less porta-');
        $this->assertSame('Rector', $entry->title);
        $this->assertSame('Ada', $entry->author);
        $this->assertSame('Rector on the current file', $entry->description);
        $this->assertSame(StoreCategory::Linters, $entry->category);
        $this->assertSame('v1.2.0', $entry->version);
        $this->assertSame(\strtotime('2026-11-03T10:00:00+00:00'), $entry->time);
        $this->assertSame(40, $entry->downloads);
        $this->assertSame(['https://example.com/a.png'], $entry->screenshots, 'https, no whitespace, strings only');
        $this->assertSame('https://raw.githubusercontent.com/acme/porta-rector/abc123/docs/CHANGES.md', $entry->changelog);
        $this->assertSame('https://raw.githubusercontent.com/acme/porta-rector/abc123/README.md', $entry->readme, 'README.md unless it says otherwise');
        $this->assertSame('https://github.com/acme/porta-rector', $entry->source);
        $this->assertSame('MIT', $entry->license);
        $this->assertFalse($entry->verified);
        $this->assertTrue($entry->compatible);
        $this->assertSame([['v1.2.0', $entry->time]], $entry->history);
    }

    public function testCurationVerifiesAndOverridesTheCategory(): void
    {
        $entry = PackagistMetadata::entry(self::PACKAGE, self::p2([self::FULL]), curated: new Curated(StoreCategory::Testing, featured: true, replaces: 'rector'));

        $this->assertInstanceOf(StoreEntry::class, $entry);
        $this->assertTrue($entry->verified);
        $this->assertTrue($entry->featured);
        $this->assertSame('rector', $entry->replaces);
        $this->assertSame(StoreCategory::Testing, $entry->category);

        $uncategorised = PackagistMetadata::entry(self::PACKAGE, self::p2([self::FULL]), curated: new Curated());
        $this->assertSame(StoreCategory::Linters, $uncategorised?->category, 'no curated category keeps the package\'s');
    }

    public function testTheMinifiedListIsExpandedAndTheNewestStableReleaseThatRunsHereIsShown(): void
    {
        $releases = [
            // A pre-release, newest of all: never what Get installs.
            ['version' => 'v2.0.0-beta1', 'version_normalized' => '2.0.0.0-beta1'] + self::FULL,
            // A stable release for another plugin API: does not run here.
            ['version' => 'v1.3.0', 'version_normalized' => '1.3.0.0', 'extra' => ['porta' => ['api' => PluginApi::API_VERSION + 1]], 'time' => '2026-11-05T00:00:00+00:00'],
            // Inherits the API above, so its own `extra` drops it again.
            ['version' => 'v1.2.5', 'version_normalized' => '1.2.5.0', 'extra' => '__unset', 'time' => '2026-11-04T00:00:00+00:00'],
            'junk',
        ];
        $entry = PackagistMetadata::entry(self::PACKAGE, self::p2($releases));

        $this->assertInstanceOf(StoreEntry::class, $entry);
        $this->assertSame('v1.2.5', $entry->version, 'the stable one that runs here, past a pre-release and an API it does not speak');
        $this->assertTrue($entry->compatible);
        $this->assertSame('Rector', $entry->title, '__unset dropped extra: the default title comes back');
        $this->assertSame(['v2.0.0-beta1', 'v1.3.0', 'v1.2.5'], \array_column($entry->history, 0));
    }

    public function testWhenNoReleaseRunsHereTheNewestStableIsShownIncompatible(): void
    {
        $entry = PackagistMetadata::entry(self::PACKAGE, self::p2([['require' => ['php' => '<5']] + self::FULL]));

        $this->assertInstanceOf(StoreEntry::class, $entry);
        $this->assertFalse($entry->compatible);
        $this->assertSame('v1.2.0', $entry->version);

        $onlyBeta = PackagistMetadata::entry(self::PACKAGE, self::p2([['version' => 'v1.0.0-RC1', 'version_normalized' => '1.0.0.0-RC1'] + self::FULL]));
        $this->assertSame('v1.0.0-RC1', $onlyBeta?->version, 'with no stable release at all, the pre-releases stand in');
    }

    public function testNothingAPackageSaysOfItselfCarriesAControlCharacter(): void
    {
        $release = ['description' => "ok\x1b]52;c;Zm9v\x07", 'authors' => [['name' => "Ada\x1b[2J"]], 'license' => ["MIT\x07"], 'version' => "v1.2.0\x1b[31m"] + self::FULL;
        $release['extra']['porta']['store']['title'] = "Rector\u{202E}";
        $release['extra']['porta']['store']['screenshots'] = ["https://e.test/a\x1b.png", 'https://e.test/b.png'];
        $entry = PackagistMetadata::entry(self::PACKAGE, self::p2([$release]));

        $this->assertInstanceOf(StoreEntry::class, $entry);
        foreach ([$entry->title, $entry->author, $entry->description, $entry->license, $entry->version, $entry->history[0][0]] as $text) {
            $this->assertDoesNotMatchRegularExpression('/[\p{Cc}\x{202E}]/u', $text, $text);
        }
        $this->assertSame(['https://e.test/b.png'], $entry->screenshots, 'an address with a control character is no address');
        $this->assertSame('Rector', $entry->title);
    }

    public function testDefaultsWhenThePackageSaysLittle(): void
    {
        $bare = [
            'version' => '0.1.0',
            'version_normalized' => '0.1.0.0',
            'type' => 'porta-plugin',
            'source' => ['url' => 'https://gitlab.com/acme/porta-rector.git', 'reference' => 'abc'],
            'extra' => ['porta' => ['store' => ['title' => '  ', 'category' => 'cooking', 'changelog' => '../etc/passwd']]],
        ];
        $entry = PackagistMetadata::entry('acme/porta-graph-tools', self::p2([$bare], 'acme/porta-graph-tools'));

        $this->assertInstanceOf(StoreEntry::class, $entry);
        $this->assertSame('Graph Tools', $entry->title);
        $this->assertSame('acme', $entry->author, 'no author named: the vendor');
        $this->assertSame(StoreCategory::Other, $entry->category);
        $this->assertSame(0, $entry->time);
        $this->assertNull($entry->changelog, 'not GitHub: nothing to read it from');
        $this->assertNull($entry->readme);
        $this->assertSame('', $entry->source);
        $this->assertSame('', $entry->license);
    }

    public function testAChangelogPathOutsideTheRepositoryIsRefused(): void
    {
        foreach (['../x.md', '/abs.md', 'a b.md', ''] as $path) {
            $release = self::FULL;
            $release['extra']['porta']['store']['changelog'] = $path;
            $entry = PackagistMetadata::entry(self::PACKAGE, self::p2([$release]));
            $this->assertNull($entry?->changelog, $path);
        }
        $release = self::FULL;
        $release['source']['reference'] = '';
        $this->assertNull(PackagistMetadata::entry(self::PACKAGE, self::p2([$release]))?->changelog, 'no commit to read it at');
    }

    public function testWhatIsNoPluginIsNotListed(): void
    {
        $this->assertNull(PackagistMetadata::entry(self::PACKAGE, self::p2([['type' => 'library'] + self::FULL])));
        $this->assertNull(PackagistMetadata::entry(self::PACKAGE, 'not json'));
        $this->assertNull(PackagistMetadata::entry(self::PACKAGE, self::p2([self::FULL], 'other/package')));
        $this->assertNull(PackagistMetadata::entry('acme/porta-Bad.Name', self::p2([self::FULL], 'acme/porta-Bad.Name')), 'a name no plugin may take');
    }

    public function testNameOfDropsPortaAndRefusesWhatNoPluginMayBeCalled(): void
    {
        $this->assertSame('git', PackagistMetadata::nameOf('janalis/porta-git'));
        $this->assertSame('rector', PackagistMetadata::nameOf('acme/rector'));
        $this->assertNull(PackagistMetadata::nameOf('acme/porta-'));
    }

    public function testStringsKeepsOnlyStrings(): void
    {
        $this->assertSame(['a', 'b'], PackagistMetadata::strings(['a', 1, null, 'b']));
        $this->assertSame([], PackagistMetadata::strings('a'));
    }
}
