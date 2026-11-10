<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Store;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Plugin\Runtime\PluginCapabilities;
use Porta\Plugin\Store\Art;
use Porta\Plugin\Store\HttpFetch;
use Porta\Plugin\Store\PackagistMetadata;
use Porta\Plugin\Store\StoreArt;
use Porta\Plugin\Store\StoreAssets;
use Porta\Plugin\Store\StoreCategory;
use Porta\Plugin\Store\StoreEntry;
use Porta\Plugin\Tool\RunningProcesses;
use Porta\Tests\Support\FakeHttp;
use Porta\Tests\Support\PngFixture;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Graphics\PngDimensions;
use Porta\Ui\Graphics\RasterImage;

/** Each plugin's icon and pictures: what it lists, else what its README shows, else its tools'. */
final class StoreArtTest extends TestCase
{
    private const string README = 'https://raw.githubusercontent.com/acme/porta-rector/abc/README.md';

    private FakeHttp $http;

    private TempProject $home;

    private int $now = 0;

    protected function setUp(): void
    {
        $this->http = new FakeHttp();
        $this->home = new TempProject();
        $this->now = \time();
    }

    private function art(): StoreArt
    {
        $fetch = $this->http->fetch();

        return new StoreArt($fetch, new StoreAssets($fetch, $this->home->path('assets'), clock: fn (): int => $this->now), $this->home->path('art'), clock: fn (): int => $this->now);
    }

    /**
     * @param list<string> $screenshots
     * @param list<string> $tools
     */
    private static function entry(string $name = 'rector', ?string $icon = null, array $screenshots = [], array $tools = [], ?string $readme = self::README, string $version = '1.0'): StoreEntry
    {
        return new StoreEntry('acme/porta-'.$name, $name, \ucfirst($name), 'Ada', '', StoreCategory::Linters, $version, screenshots: $screenshots, readme: $readme, icon: $icon, tools: $tools);
    }

    /**
     * @param list<StoreEntry> $entries
     *
     * @return array<string, Art>
     */
    private static function resolve(StoreArt $art, array $entries): array
    {
        $found = [];
        $art->resolve($entries, static function (string $package, Art $art) use (&$found): void {
            $found[$package] = $art;
        });

        return $found;
    }

    /** @return array{int, int}|null */
    private static function dimensions(?string $png): ?array
    {
        return null === $png ? null : PngDimensions::read($png);
    }

    public function testWhatThePackageListsWinsAndNoReadmeIsRead(): void
    {
        $this->http->bodies = ['https://img.test/icon.png' => PngFixture::bytes(40, 40), 'https://img.test/shot.png' => PngFixture::bytes(320, 180)];
        $art = self::resolve($this->art(), [self::entry(icon: 'https://img.test/icon.png', screenshots: ['https://img.test/shot.png', 'https://img.test/two.png'])])['acme/porta-rector'];

        $this->assertSame([40, 40], self::dimensions($art->icon));
        $this->assertSame([320, 180], self::dimensions($art->hero), 'the first screenshot stands over a featured tile');
        $this->assertSame(['https://img.test/shot.png', 'https://img.test/two.png'], $art->shots);
        $this->assertNotContains(self::README, \array_merge(...$this->http->batches));
    }

    public function testTheReadmesFirstPictureIsTheIconWhenSquareAndItsWideOnesTheScreenshots(): void
    {
        $this->http->bodies = [
            self::README => "# Rector\n\n![logo](logo.png)\n![tiny](https://img.test/tiny.png)\n![wide](https://img.test/wide.png)\n![tall](https://img.test/tall.png)\n![later](https://img.test/later.png)\n",
            'https://raw.githubusercontent.com/acme/porta-rector/abc/logo.png' => PngFixture::bytes(64, 60),
            'https://img.test/tiny.png' => PngFixture::bytes(300, 20),
            'https://img.test/wide.png' => PngFixture::bytes(400, 225),
            'https://img.test/tall.png' => PngFixture::bytes(100, 400),
            'https://img.test/later.png' => PngFixture::bytes(400, 225),
        ];
        $art = self::resolve($this->art(), [self::entry()])['acme/porta-rector'];

        $this->assertSame([64, 60], self::dimensions($art->icon));
        $this->assertSame(['https://img.test/wide.png'], $art->shots, 'a strip and a tower are no screenshots, and the fifth is not looked at');
        $this->assertSame([400, 225], self::dimensions($art->hero));
        $this->assertNotContains('https://img.test/later.png', \array_merge(...$this->http->batches));
    }

    public function testOnlyTheFirstPictureMayBeTheIcon(): void
    {
        $this->http->bodies = [
            self::README => "![gone](https://img.test/gone.png)\n![wide](https://img.test/wide.png)\n![square](https://img.test/square.png)\n",
            'https://img.test/wide.png' => PngFixture::bytes(400, 225),
            'https://img.test/square.png' => PngFixture::bytes(64, 64),
        ];
        $art = self::resolve($this->art(), [self::entry(screenshots: ['https://img.test/listed.png'])])['acme/porta-rector'];

        $this->assertNull($art->icon, 'a first picture that never came, and a square one further down, are no icon');
        $this->assertSame(['https://img.test/listed.png'], $art->shots, 'listed screenshots are not added to');
        $this->assertNull($art->hero, 'one that cannot be fetched stands over nothing');
    }

    /** @return iterable<string, array{int, int, bool, bool}> width, height, an icon, a screenshot */
    public static function shapes(): iterable
    {
        yield 'square' => [64, 64, true, false];
        yield 'smallest icon' => [32, 32, true, false];
        yield 'too small an icon' => [31, 31, false, false];
        yield 'widest icon, 4:3' => [64, 48, true, false];
        yield 'past 4:3' => [65, 48, false, false];
        yield 'tallest icon, 3:4' => [48, 64, true, false];
        yield 'past 3:4' => [48, 65, false, false];
        yield 'narrowest screenshot, 13:10, square enough to be an icon too' => [260, 200, true, true];
        yield 'short of 13:10' => [259, 200, true, false];
        yield 'too narrow a screenshot' => [199, 100, false, false];
        yield 'widest screenshot, 5:1' => [1000, 200, false, true];
        yield 'past 5:1' => [1001, 200, false, false];
    }

    // @mago-expect lint:no-boolean-flag-parameter -- the two verdicts a shape gets are the data.
    #[DataProvider('shapes')]
    public function testAPicturesShapeSaysWhatItIs(int $width, int $height, bool $icon, bool $screenshot): void
    {
        $url = 'https://img.test/'.$width.'x'.$height.'.png';
        $this->http->bodies = [self::README => '![p]('.$url.')', $url => PngFixture::bytes($width, $height)];
        $art = self::resolve($this->art(), [self::entry()])['acme/porta-rector'];

        $this->assertSame([$icon ? [$width, $height] : null, $screenshot ? [$url] : []], [self::dimensions($art->icon), $art->shots]);
    }

    public function testWhatCouldNotBeReadIsNotKept(): void
    {
        $tool = 'https://raw.githubusercontent.com/vendor/tool/HEAD/README.md';
        $this->http->bodies = ['https://img.test/logo.png' => PngFixture::bytes(40, 40)];
        $this->assertNull(self::resolve($this->art(), [self::entry(), self::entry('tooled', tools: ['github:vendor/tool'], readme: null)])['acme/porta-rector']->icon, 'offline: nothing');

        $this->http->bodies += [self::README => "![logo](https://img.test/logo.png)\n", $tool => "![logo](https://img.test/logo.png)\n"];
        $found = self::resolve($this->art(), [self::entry(), self::entry('tooled', tools: ['github:vendor/tool'], readme: null)]);
        $this->assertSame(
            [[40, 40], [40, 40]],
            [self::dimensions($found['acme/porta-rector']->icon), self::dimensions($found['acme/porta-tooled']->icon)],
            'and the next session looks again, its own README and its tool\'s',
        );

        unset($this->http->bodies[self::README], $this->http->bodies[$tool]);
        $kept = self::resolve($this->art(), [self::entry(), self::entry('tooled', tools: ['github:vendor/tool'], readme: null)]);
        $this->assertSame([[40, 40], [40, 40]], [self::dimensions($kept['acme/porta-rector']->icon), self::dimensions($kept['acme/porta-tooled']->icon)], 'what was read is kept');
    }

    /** A picture not drawn this time — no SVG converter yet, or the network — is looked for again next session. */
    public function testAPictureThatCouldNotBeDrawnIsNotKept(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"/>';
        $this->http->bodies = [self::README => "![logo](https://img.test/logo.svg)\n", 'https://img.test/logo.svg' => $svg];
        $converter = null;
        $art = function () use (&$converter): StoreArt {
            $fetch = $this->http->fetch();
            $raster = new RasterImage(static function (string $svg, \Closure $onPng): void {
                $onPng(null);
            }, lent: static function () use (&$converter): ?\Closure {
                return $converter;
            });
            $assets = new StoreAssets($fetch, $this->home->path('assets'), clock: fn (): int => $this->now, raster: $raster);

            return new StoreArt($fetch, $assets, $this->home->path('art'), clock: fn (): int => $this->now);
        };
        $this->assertNull(self::resolve($art(), [self::entry()])['acme/porta-rector']->icon, 'no converter: no icon');

        $converter = static function (string $svg, int $width, \Closure $onPng): void {
            $onPng(PngFixture::bytes(40, 40));
        };
        $this->assertSame([40, 40], self::dimensions(self::resolve($art(), [self::entry()])['acme/porta-rector']->icon), 'the image viewer switched on: the next session draws it');
    }

    /** The image viewer switched on while Porta runs: the store looks again at once, not next session. */
    public function testAConverterLentMidSessionDrawsWhatCouldNotBeDrawn(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"/>';
        $this->http->bodies = [
            self::README => "![logo](https://img.test/logo.svg)\n",
            'https://img.test/logo.svg' => $svg,
            'https://raw.githubusercontent.com/acme/porta-plain/abc/README.md' => "![logo](https://img.test/plain.png)\n",
            'https://img.test/plain.png' => PngFixture::bytes(40, 40),
        ];
        $converter = null;
        $changes = 0;
        $fetch = $this->http->fetch();
        $raster = new RasterImage(static function (string $svg, \Closure $onPng): void {
            $onPng(null);
        }, lent: static function () use (&$converter): ?\Closure {
            return $converter;
        });
        $assets = new StoreAssets($fetch, $this->home->path('assets'), clock: fn (): int => $this->now, raster: $raster, converterChanges: static function () use (&$changes): int {
            return $changes;
        });
        $art = new StoreArt($fetch, $assets, $this->home->path('art'), clock: fn (): int => $this->now);
        $entries = [self::entry(), self::entry('plain', readme: 'https://raw.githubusercontent.com/acme/porta-plain/abc/README.md')];
        $this->assertNull(self::resolve($art, $entries)['acme/porta-rector']->icon);

        $converter = static function (string $svg, int $width, \Closure $onPng): void {
            $onPng(PngFixture::bytes(40, 40));
        };
        $this->assertNull(self::resolve($art, $entries)['acme/porta-rector']->icon, 'nothing changed: what this session found stands');

        ++$changes;
        $fetched = \count($this->http->batches);
        $found = self::resolve($art, $entries);
        $this->assertSame([40, 40], self::dimensions($found['acme/porta-rector']->icon), 'a converter lent: drawn now');
        $this->assertSame([40, 40], self::dimensions($found['acme/porta-plain']->icon));
        $this->assertNotContains('https://img.test/plain.png', \array_merge([], ...\array_slice($this->http->batches, $fetched)), 'what was drawn is not fetched again');
    }

    public function testAPictureThatDidNotComeIsLookedForAgainWhenTheConvertersChange(): void
    {
        $this->http->bodies = [
            self::README => "![logo](https://img.test/logo.png)\n![wide](https://img.test/wide.png)\n",
            'https://img.test/logo.png' => PngFixture::bytes(40, 40),
            'https://img.test/wide.png' => PngFixture::bytes(400, 225),
        ];
        self::resolve($this->art(), [self::entry()]); // kept: every picture drawn
        $changes = 1; // plugins lent and took back converters before the store opened
        $fetch = $this->http->fetch();
        $art = new StoreArt($fetch, new StoreAssets($fetch, $this->home->path('none'), clock: fn (): int => $this->now, converterChanges: static function () use (&$changes): int {
            return $changes;
        }), $this->home->path('art'), clock: fn (): int => $this->now);
        unset($this->http->bodies['https://img.test/logo.png']);
        $this->assertNull(self::resolve($art, [self::entry()])['acme/porta-rector']->icon, 'the kept icon did not come');

        $this->http->bodies['https://img.test/logo.png'] = PngFixture::bytes(40, 40);
        $this->assertNull(self::resolve($art, [self::entry()])['acme/porta-rector']->icon, 'until the converters change, what this session found stands');
        ++$changes;
        $this->assertSame([40, 40], self::dimensions(self::resolve($art, [self::entry()])['acme/porta-rector']->icon));
    }

    public function testEveryEntryAndEveryPictureIsLookedAtPastOneThatIsNot(): void
    {
        $this->http->bodies = [
            self::README => "![gone](https://img.test/gone.png)\n![wide](https://img.test/wide.png)\n",
            'https://img.test/wide.png' => PngFixture::bytes(400, 225),
            'https://raw.githubusercontent.com/acme/porta-other/abc/README.md' => '![logo](https://img.test/logo.png)',
            'https://img.test/logo.png' => PngFixture::bytes(40, 40),
            \sprintf(PackagistMetadata::RELEASES, 'vendor/tool') => (string) \json_encode(
                ['packages' => ['vendor/tool' => [['source' => ['url' => 'https://github.com/vendor/tool-repo.git']]]]],
                \JSON_THROW_ON_ERROR,
            ),
            'https://raw.githubusercontent.com/vendor/tool-repo/HEAD/README.md' => '![logo](https://img.test/tool.png)',
            'https://img.test/tool.png' => PngFixture::bytes(44, 44),
        ];
        $art = $this->art();
        self::resolve($art, [self::entry()]);
        $found = self::resolve($art, [
            self::entry(),
            self::entry('other', readme: 'https://raw.githubusercontent.com/acme/porta-other/abc/README.md'),
            self::entry('tooled', tools: ['github:vendor/gone', 'composer:vendor/tool'], readme: null),
        ]);

        $this->assertSame(['https://img.test/wide.png'], $found['acme/porta-rector']->shots, 'a picture that never came is passed over');
        $this->assertSame([40, 40], self::dimensions($found['acme/porta-other']->icon), 'one already known does not end the look');
        $this->assertSame([44, 44], self::dimensions($found['acme/porta-tooled']->icon), 'a GitHub tool does not keep the next one from being looked up');
    }

    public function testAPluginWithoutPicturesBorrowsTheMastheadOfItsToolsReadme(): void
    {
        $tool = 'https://raw.githubusercontent.com/sebastianbergmann/phpunit/HEAD/README.md';
        $named = 'https://raw.githubusercontent.com/phpstan/phpstan/HEAD/README.md';
        $github = 'https://raw.githubusercontent.com/janalis/custos/HEAD/README.md';
        $this->http->bodies = [
            self::README => "# Rector\n\nNo picture.\n",
            \sprintf(PackagistMetadata::RELEASES, 'phpunit/phpunit') => (string) \json_encode(
                [
                    'packages' => ['phpunit/phpunit' => [['source' => ['url' => 'https://github.com/sebastianbergmann/phpunit.git']]]],
                ],
                \JSON_THROW_ON_ERROR,
            ),
            \sprintf(PackagistMetadata::RELEASES, 'phpstan/phpstan') => (string) \json_encode(['packages' => ['phpstan/phpstan' => [['dist' => []]]]], \JSON_THROW_ON_ERROR),
            $tool => "<img src=\".github/img/logo.png\">\n\n## Sponsors\n\n![s](https://img.test/sponsor.png)\n",
            $named => "![logo](https://img.test/phpstan.png)\n",
            $github => "![logo](https://img.test/custos.png)\n",
            'https://raw.githubusercontent.com/sebastianbergmann/phpunit/HEAD/.github/img/logo.png' => PngFixture::bytes(48, 48),
            'https://img.test/phpstan.png' => PngFixture::bytes(50, 50),
            'https://img.test/custos.png' => PngFixture::bytes(52, 52),
        ];
        $found = self::resolve($this->art(), [
            self::entry('phpunit', tools: ['composer:phpunit/phpunit']),
            self::entry('phpstan', tools: ['composer:phpstan/phpstan'], readme: null),
            self::entry('custos', tools: ['github:janalis/custos']),
            self::entry('todo'),
        ]);

        $this->assertSame([48, 48], self::dimensions($found['acme/porta-phpunit']->icon), 'the repository Packagist names');
        $this->assertSame([50, 50], self::dimensions($found['acme/porta-phpstan']->icon), 'none named: the one the package is called after');
        $this->assertSame([52, 52], self::dimensions($found['acme/porta-custos']->icon));
        $this->assertNull($found['acme/porta-todo']->icon, 'no tool, no picture: the initials stay');
        $this->assertNotContains('https://img.test/sponsor.png', \array_merge(...$this->http->batches), 'a tool lends its masthead, never its sponsors');
    }

    public function testWhatWasFoundIsKeptForAWeekPerVersion(): void
    {
        $this->http->bodies = [self::README => "![logo](https://img.test/logo.png)\n", 'https://img.test/logo.png' => PngFixture::bytes(40, 40)];
        $art = $this->art();
        $first = self::resolve($art, [self::entry()]);
        $batches = \count($this->http->batches);

        $this->assertSame($first, self::resolve($art, [self::entry()]), 'asked again, the same art at once');
        $this->assertCount($batches, $this->http->batches);

        $this->http->bodies[self::README] = 'changed';
        $this->assertSame([40, 40], self::dimensions(self::resolve($this->art(), [self::entry()])['acme/porta-rector']->icon), 'a new session reads what was found');
        $this->assertNotContains(self::README, \array_merge(...\array_slice($this->http->batches, $batches)));

        // Another version's README is read at its own tag.
        $this->http->bodies['https://raw.githubusercontent.com/acme/porta-rector/def/README.md'] = 'no picture';
        $this->assertNull(
            self::resolve($this->art(), [self::entry(readme: 'https://raw.githubusercontent.com/acme/porta-rector/def/README.md', version: '2.0')])['acme/porta-rector']->icon,
            'another version is looked at again',
        );

        // A week past the kept file's own time, which can be a second past the clock's.
        $this->now += (7 * 86_400) + 60;
        $this->assertNull(self::resolve($this->art(), [self::entry()])['acme/porta-rector']->icon, 'a week on, too');
    }

    public function testAKeptFileThatIsNotWhatWasFoundIsLookedAtAgain(): void
    {
        $this->http->bodies = [self::README => "![logo](https://img.test/logo.png)\n", 'https://img.test/logo.png' => PngFixture::bytes(40, 40)];
        $this->home->write('art/'.\sha1('acme/porta-rector@1.0').'.json', '{"shots": []}');
        $this->assertNotNull(self::resolve($this->art(), [self::entry()])['acme/porta-rector']->icon);

        $this->home->write('art/'.\sha1('acme/porta-rector@1.0').'.json', '{"icon": 7, "shots": "x"}');
        $kept = self::resolve($this->art(), [self::entry()])['acme/porta-rector'];
        $this->assertNull($kept->icon, 'what is not an address is no icon');
        $this->assertSame([], $kept->shots);
    }

    public function testAnEntryBeingLookedAtIsLookedAtOnce(): void
    {
        $this->http->bodies = [self::README => "![logo](https://img.test/logo.png)\n", 'https://img.test/logo.png' => PngFixture::bytes(40, 40)];
        $this->http->hold = true;
        $art = $this->art();
        $first = [];
        $art->resolve([self::entry()], static function (string $package) use (&$first): void {
            $first[] = $package;
        });
        $second = self::resolve($art, [self::entry()]);
        $this->http->release();
        $this->http->release();

        $this->assertSame([], $second, 'the ask that started it is answered');
        $this->assertSame(['acme/porta-rector'], $first);
        $this->assertCount(1, \array_filter($this->http->batches, static fn (array $batch): bool => \in_array(self::README, $batch, strict: true)));
    }

    public function testNowhereToKeepStillFinds(): void
    {
        $this->http->bodies = [self::README => "![logo](https://img.test/logo.png)\n", 'https://img.test/logo.png' => PngFixture::bytes(40, 40)];
        $fetch = $this->http->fetch();
        $this->assertNotNull(self::resolve(new StoreArt($fetch, new StoreAssets($fetch, null), null), [self::entry()])['acme/porta-rector']->icon);

        $this->home->write('blocked', '');
        $this->assertNotNull(self::resolve(new StoreArt($fetch, new StoreAssets($fetch, null), $this->home->path('blocked')), [self::entry()])['acme/porta-rector']->icon);
    }

    public function testProductionWiringNeedsNoNetworkToBuild(): void
    {
        $this->expectNotToPerformAssertions();
        $http = HttpFetch::create(new RunningProcesses());
        StoreArt::create($http, StoreAssets::create($http, new PluginCapabilities()));
    }
}
