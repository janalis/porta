<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Store;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Store\HttpFetch;
use Porta\Plugin\Store\PackagistMetadata;
use Porta\Plugin\Store\StoreCatalog;
use Porta\Plugin\Store\StoreEntry;
use Porta\Plugin\Tool\RunningProcesses;
use Porta\Tests\Support\FakeHttp;
use Porta\Tests\Support\TempProject;

/** The shelves: filled from Packagist, curated, cached, and kept when the network is not there. */
final class StoreCatalogTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    private FakeHttp $http;

    private TempProject $home;

    private int $now = self::NOW;

    protected function setUp(): void
    {
        $this->http = new FakeHttp();
        $this->home = new TempProject(['shipped.json' => '{"acme/porta-mago": {"replaces": "mago"}}']);
    }

    private function catalog(?string $cacheDir = 'own'): StoreCatalog
    {
        return new StoreCatalog($this->http->fetch(), 'own' === $cacheDir ? $this->home->path('cache') : $cacheDir, $this->home->path('shipped.json'), clock: fn (): int => $this->now);
    }

    /** Packagist knows $downloads' packages, each with one stable release. @param array<string, int> $downloads */
    private function packagist(array $downloads): void
    {
        $results = [];
        foreach ($downloads as $package => $count) {
            $results[] = ['name' => $package, 'downloads' => $count];
            $this->http->bodies[\sprintf(PackagistMetadata::RELEASES, $package)] = (string) \json_encode(
                [
                    'packages' => [
                        $package => [[
                            'name' => $package,
                            'version' => 'v1.0.0',
                            'version_normalized' => '1.0.0.0',
                            'type' => 'porta-plugin',
                        ]],
                    ],
                ],
                \JSON_THROW_ON_ERROR,
            );
        }
        $this->http->bodies[PackagistMetadata::SEARCH] = (string) \json_encode(['results' => $results], \JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private static function packages(StoreCatalog $catalog): array
    {
        return \array_map(static fn (StoreEntry $entry): string => $entry->package, $catalog->entries());
    }

    private function refresh(StoreCatalog $catalog): bool
    {
        $filled = null;
        $catalog->refresh(static function (bool $ok) use (&$filled): void {
            $filled = $ok;
        });
        $this->assertNotNull($filled, 'the fake network answers at once');

        return $filled;
    }

    public function testARefreshFillsTheShelvesMostDownloadedFirstAndCachesThem(): void
    {
        $this->packagist(['a/porta-few' => 2, 'a/porta-many' => 90, 'b/porta-tie' => 2]);
        $this->http->bodies[StoreCatalog::CURATION] = '{"a/porta-few": {"category": "ai"}}';
        $catalog = $this->catalog();

        $this->assertSame([], $catalog->entries(), 'nothing seen yet');
        $this->assertTrue($catalog->stale());
        $this->assertTrue($this->refresh($catalog));

        $this->assertSame(['a/porta-many', 'a/porta-few', 'b/porta-tie'], self::packages($catalog), 'most downloaded first, then by name');
        $this->assertTrue($catalog->find('a/porta-few')?->verified, 'curation from main applies');
        $this->assertNull($catalog->find('a/porta-nope'));
        $this->assertSame(self::NOW, $catalog->fetchedAt());
        $this->assertFalse($catalog->stale());
        $this->assertSame([PackagistMetadata::SEARCH, StoreCatalog::CURATION], $this->http->batches[0], 'the list and the curation together');
        $this->assertCount(3, $this->http->batches[1], 'then every package\'s releases in one batch');

        $again = $this->catalog();
        $this->assertSame(self::packages($catalog), self::packages($again), 'a new session opens on the cache');
        $this->assertSame(self::NOW, $again->fetchedAt());

        $this->now += StoreCatalog::FRESH_FOR;
        $this->assertTrue($again->stale(), 'an hour on, it is asked again');
    }

    public function testAPackageWhoseReleasesCannotBeReadIsLeftOut(): void
    {
        $this->packagist(['a/porta-good' => 1, 'a/porta-gone' => 1]);
        unset($this->http->bodies[\sprintf(PackagistMetadata::RELEASES, 'a/porta-gone')]);
        $this->http->bodies[\sprintf(PackagistMetadata::RELEASES, 'a/porta-lib')] = '{"packages": {"a/porta-lib": [{"version": "1.0", "type": "library"}]}}';
        $this->http->bodies[PackagistMetadata::SEARCH] = '{"results": [{"name": "a/porta-good"}, {"name": "a/porta-gone"}, {"name": "a/porta-lib"}]}';
        $catalog = $this->catalog();
        $this->refresh($catalog);

        $this->assertSame(['a/porta-good'], self::packages($catalog));
    }

    public function testATakeoverRemovesTheBundledSplitItReplaces(): void
    {
        // main unreachable: the shipped curation says acme/porta-mago replaces mago.
        $this->packagist(['janalis/porta-mago' => 50, 'acme/porta-mago' => 1, 'janalis/porta-git' => 9]);
        $catalog = $this->catalog();
        $this->refresh($catalog);

        $this->assertSame(['janalis/porta-git', 'acme/porta-mago'], self::packages($catalog));
        $this->assertSame('acme/porta-mago', $catalog->replacing('mago')?->package);
        $this->assertNull($catalog->replacing('git'));
    }

    public function testNoShippedCurationAndNoMainIsNoCuration(): void
    {
        $this->packagist(['acme/porta-mago' => 1]);
        $catalog = new StoreCatalog($this->http->fetch(), null, $this->home->path('absent.json'));
        $this->refresh($catalog);

        $this->assertFalse($catalog->find('acme/porta-mago')?->verified);
    }

    public function testPackagistUnreachableKeepsWhatWasThere(): void
    {
        $this->packagist(['a/porta-one' => 1]);
        $catalog = $this->catalog();
        $this->refresh($catalog);
        $this->http->bodies = [];

        $this->now += 10;
        $this->assertFalse($this->refresh($catalog));
        $this->assertSame(['a/porta-one'], self::packages($catalog));
        $this->assertSame(self::NOW, $catalog->fetchedAt(), 'a failed look is no look');
    }

    public function testReleasesThatDoNotComeBackKeepWhatWasSeenOrFailTheLook(): void
    {
        $this->packagist(['a/porta-one' => 1, 'a/porta-two' => 1]);
        $catalog = $this->catalog();
        $this->refresh($catalog);

        // One package's releases fail: it keeps its entry from the last look.
        unset($this->http->bodies[\sprintf(PackagistMetadata::RELEASES, 'a/porta-two')]);
        $this->now += 10;
        $this->assertTrue($this->refresh($catalog));
        $this->assertSame(['a/porta-one', 'a/porta-two'], self::packages($catalog));

        // None come back: the metadata host is down — a failed look, the shelves kept, the stamp too.
        unset($this->http->bodies[\sprintf(PackagistMetadata::RELEASES, 'a/porta-one')]);
        $this->now += 10;
        $this->assertFalse($this->refresh($catalog));
        $this->assertSame(['a/porta-one', 'a/porta-two'], self::packages($catalog));
        $this->assertSame(self::NOW + 10, $catalog->fetchedAt());
    }

    public function testASecondAskerWaitsForTheRefreshInFlight(): void
    {
        $this->packagist(['a/porta-one' => 1]);
        $this->http->hold = true;
        $catalog = $this->catalog();
        $answers = [];
        $catalog->refresh(static function (bool $ok) use (&$answers): void {
            $answers[] = 'first';
        });
        $catalog->refresh(static function (bool $ok) use (&$answers): void {
            $answers[] = 'second';
        });
        $this->assertTrue($catalog->refreshing());
        $this->assertCount(1, $this->http->batches, 'one refresh, not two');

        $this->http->release(); // the list
        $this->http->release(); // the releases
        $this->assertSame(['first', 'second'], $answers);
        $this->assertFalse($catalog->refreshing());
    }

    public function testACacheThatIsNotOursIsReadAsFarAsItGoes(): void
    {
        $this->home->write('cache/catalog.json', '{"fetchedAt": "yesterday", "entries": [{"package": "a/b", "name": "b", "version": "1"}, "junk", {"package": "x"}]}');

        $catalog = $this->catalog();
        $this->assertSame(['a/b'], self::packages($catalog));
        $this->assertSame(0, $catalog->fetchedAt());
    }

    public function testProductionWiringKeepsTheCacheUnderTheHome(): void
    {
        $previous = \getenv('PORTA_HOME');
        \putenv('PORTA_HOME='.$this->home->dir);
        try {
            $catalog = StoreCatalog::create(HttpFetch::create(new RunningProcesses()));
            $this->home->write('.porta/cache/store/catalog.json', '{"fetchedAt": 7, "entries": []}');
            $this->assertSame(7, $catalog->fetchedAt());
        } finally {
            \putenv(false === $previous ? 'PORTA_HOME' : 'PORTA_HOME='.$previous);
        }
    }
}
