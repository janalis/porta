<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Store;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Store\HttpFetch;
use Porta\Plugin\Store\StoreAssets;
use Porta\Plugin\Tool\RunningProcesses;
use Porta\Tests\Support\FakeHttp;
use Porta\Tests\Support\TempProject;

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

    public function testProductionWiringNeedsNoNetworkToBuild(): void
    {
        $this->expectNotToPerformAssertions();
        StoreAssets::create(HttpFetch::create(new RunningProcesses()));
    }
}
