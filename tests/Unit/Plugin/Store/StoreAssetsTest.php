<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Store;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Store\HttpFetch;
use Porta\Plugin\Store\StoreAssets;
use Porta\Plugin\Tool\RunningProcesses;
use Porta\Tests\Support\FakeHttp;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Graphics\PngDimensions;
use Porta\Ui\Graphics\RasterImage;

/** A page's changelog, README and screenshots: fetched once, checked, kept a week. */
final class StoreAssetsTest extends TestCase
{
    private const string PNG = "\x89PNG\r\n\x1a\nrest";

    private const string TEXT = 'https://raw.test/CHANGELOG.md';

    private const string SHOT = 'https://img.test/a.png';

    private FakeHttp $http;

    private TempProject $home;

    /** The real clock's now: what is kept is stamped with the file's own mtime. */
    private int $now = 0;

    protected function setUp(): void
    {
        $this->http = new FakeHttp();
        $this->home = new TempProject();
        $this->now = \time();
    }

    private function assets(): StoreAssets
    {
        return new StoreAssets($this->http->fetch(), $this->home->path('assets'), clock: fn (): int => $this->now);
    }

    /** @return list<?string> what each ask answered */
    // @mago-expect lint:no-boolean-flag-parameter -- a picture or a text: the one fact the ask carries.
    private static function ask(StoreAssets $assets, string $url, bool $image = false): array
    {
        $answers = [];
        $collect = static function (?string $body) use (&$answers): void {
            $answers[] = $body;
        };
        $image ? $assets->image($url, $collect) : $assets->text($url, $collect);

        return $answers;
    }

    public function testATextIsFetchedOnceAndKeptForTheNextSession(): void
    {
        $this->http->bodies = [self::TEXT => "## 1.0\n"];
        $assets = $this->assets();

        $this->assertSame(["## 1.0\n"], self::ask($assets, self::TEXT));
        $this->assertSame(["## 1.0\n"], self::ask($assets, self::TEXT));
        $this->assertCount(1, $this->http->batches, 'the second ask is answered from memory');
        $this->assertSame((string) StoreAssets::MAX_TEXT, $this->http->argvs[0][\array_search('--max-filesize', $this->http->argvs[0], strict: true) + 1]);

        $this->assertSame(["## 1.0\n"], self::ask($this->assets(), self::TEXT));
        $this->assertCount(1, $this->http->batches, 'a new session reads what was kept');
    }

    public function testATextReachesThePageWithNoEscapeSequenceOfItsOwn(): void
    {
        $this->http->bodies = [self::TEXT => "# Log\r\n\x1b]52;c;Zm9v\x07- fixed\n"];

        $this->assertSame(["# Log\n]52;c;Zm9v- fixed\n"], self::ask($this->assets(), self::TEXT));

        // What porta 0.1.6 kept is the body as it came: it is fetched again, not drawn.
        $this->home->write('assets/'.\sha1('https://raw.test/README.md'), "\x1b]0;title\x07raw");
        $this->http->bodies['https://raw.test/README.md'] = 'clean';
        $this->assertSame(['clean'], self::ask($this->assets(), 'https://raw.test/README.md'));
    }

    public function testWhatWasKeptAWeekAgoIsAskedForAgain(): void
    {
        $this->http->bodies = [self::TEXT => 'old'];
        self::ask($this->assets(), self::TEXT);
        $this->http->bodies = [self::TEXT => 'new'];
        \touch($this->home->path('assets/'.\sha1(self::TEXT)), $this->now - (7 * 86_400));

        $this->assertSame(['new'], self::ask($this->assets(), self::TEXT));
    }

    public function testAnImageMustBeAPngAndATextValidUtf8(): void
    {
        $this->http->bodies = [self::SHOT => self::PNG, 'https://img.test/b.gif' => 'GIF89a', 'https://raw.test/bin' => "\xff\xfe"];
        $assets = $this->assets();

        $this->assertSame([self::PNG], self::ask($assets, self::SHOT, image: true));
        $this->assertSame([null], self::ask($assets, 'https://img.test/b.gif', image: true));
        $this->assertSame([null], self::ask($assets, 'https://raw.test/bin'));
        $this->assertSame([null], self::ask($assets, 'https://raw.test/missing'));
        $this->assertFileDoesNotExist($this->home->path('assets/'.\sha1('https://img.test/b.gif')), 'nothing invalid is kept');

        $this->home->write('assets/'.\sha1('https://img.test/c.png'), 'not a png');
        $this->http->bodies['https://img.test/c.png'] = self::PNG;
        $this->assertSame([self::PNG], self::ask($assets, 'https://img.test/c.png', image: true), 'a kept file that fails the check is fetched again');
    }

    public function testTwoAsksForOneAddressShareOneFetch(): void
    {
        $this->http->bodies = [self::TEXT => 'x'];
        $this->http->hold = true;
        $assets = $this->assets();
        $answers = [];
        foreach (['a', 'b'] as $who) {
            $assets->text(self::TEXT, static function (?string $body) use (&$answers, $who): void {
                $answers[] = $who.$body;
            });
        }
        $this->http->release();

        $this->assertSame(['ax', 'bx'], $answers);
        $this->assertCount(1, $this->http->batches);
    }

    public function testNowhereToKeepStillAnswers(): void
    {
        $this->http->bodies = [self::TEXT => 'x'];
        $this->assertSame(['x'], self::ask(new StoreAssets($this->http->fetch(), null), self::TEXT));

        // A cache directory that is a file: nothing can be kept under it.
        $this->home->write('blocked', '');
        $this->assertSame(['x'], self::ask(new StoreAssets($this->http->fetch(), $this->home->path('blocked')), self::TEXT));
    }

    public function testManyAddressesAreOneFetchAndAnswerTogether(): void
    {
        $this->http->bodies = ['https://raw.test/a' => 'A', 'https://raw.test/b' => 'B'];
        $assets = $this->assets();
        self::ask($assets, 'https://raw.test/a');
        $answers = [];
        $assets->texts(['https://raw.test/a', 'https://raw.test/b', 'https://raw.test/gone', 'https://raw.test/b'], static function (array $bodies) use (&$answers): void {
            $answers[] = $bodies;
        });

        $this->assertSame([['https://raw.test/a' => 'A', 'https://raw.test/b' => 'B', 'https://raw.test/gone' => null]], $answers, 'once, every address, in the order asked');
        $this->assertSame([['https://raw.test/a'], ['https://raw.test/b', 'https://raw.test/gone']], $this->http->batches, 'what was known is not fetched again');

        $none = [];
        $assets->images([], static function (array $bodies) use (&$none): void {
            $none[] = $bodies;
        });
        $this->assertSame([[]], $none, 'nothing asked, nothing fetched, answered all the same');
        $this->assertCount(2, $this->http->batches);
    }

    public function testAPictureIsKeptAsThePngItWasMadeInto(): void
    {
        \ob_start();
        \imagegif(\imagecreatetruecolor(6, height: 4));
        $this->http->bodies = ['https://img.test/a.gif' => (string) \ob_get_clean()];
        $made = [];
        $assets = new StoreAssets($this->http->fetch(), $this->home->path('assets'), clock: fn (): int => $this->now, raster: new RasterImage());
        $assets->images(['https://img.test/a.gif'], static function (array $bodies) use (&$made): void {
            $made = $bodies;
        });

        $png = (string) $made['https://img.test/a.gif'];
        $this->assertSame([6, 4], PngDimensions::read($png));
        $this->assertSame($png, (string) \file_get_contents($this->home->path('assets/'.\sha1('https://img.test/a.gif'))), 'the PNG is kept, so the GIF is decoded once');
    }

    public function testProductionWiringNeedsNoNetworkToBuild(): void
    {
        $this->expectNotToPerformAssertions();
        StoreAssets::create(HttpFetch::create(new RunningProcesses()));
    }
}
