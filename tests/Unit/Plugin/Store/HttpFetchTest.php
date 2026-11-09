<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Store;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Store\HttpFetch;
use Porta\Plugin\Tool\LatestVersions;
use Porta\Plugin\Tool\RunningProcesses;
use Porta\Tests\Support\FakeHttp;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;

/** One curl for a batch of addresses, each answering its body or nothing. */
final class HttpFetchTest extends TestCase
{
    use PumpsLoop;

    public function testABatchIsOneHardenedCurlAndEachAddressAnswersItsBodyOrNothing(): void
    {
        $http = new FakeHttp();
        $http->bodies = ['https://a.test/one' => 'ONE', 'https://a.test/empty' => ''];
        $answers = null;
        $http->fetch()->get(
            ['https://a.test/one', 'https://a.test/empty', 'https://a.test/missing'],
            static function (array $bodies) use (&$answers): void {
                $answers = $bodies;
            },
            maxBytes: 99,
        );

        $this->assertSame(['https://a.test/one' => 'ONE', 'https://a.test/empty' => null, 'https://a.test/missing' => null], $answers);
        $argv = $http->argvs[0];
        $this->assertSame(LatestVersions::CURL, \array_slice($argv, offset: 0, length: \count(LatestVersions::CURL)), 'bounded and https-only, like every download');
        $this->assertContains('--parallel', $argv);
        $this->assertContains('--globoff', $argv, 'an address off the network is never a curl pattern');
        $this->assertSame('99', $argv[\array_search('--max-filesize', $argv, strict: true) + 1]);
        $this->assertDirectoryDoesNotExist(\dirname($argv[\array_search('-o', $argv, strict: true) + 1]), 'the scratch directory goes with the answer');

        $http->fetch()->get(['https://a.test/one'], static function (): void {});
        $this->assertNotContains('--max-filesize', $http->argvs[1], 'no cap unless asked');
    }

    /** A body on disk is not a whole body: curl's own outcome for that transfer decides. */
    public function testATransferCutShortIsNothingThoughItsFileIsThere(): void
    {
        $fetch = self::fetchWith(...);

        $this->assertSame(['https://a.test/1' => 'PART', 'https://a.test/2' => null], $fetch("0 0 200\n1 28 200\n"), 'exit 28: the transfer timed out half way');
        $this->assertSame(['https://a.test/1' => null, 'https://a.test/2' => null], $fetch("0 0 404\nnoise\n"), 'a 404 page is no body; a transfer curl said nothing of is none either');
        $this->assertSame(['https://a.test/1' => 'PART', 'https://a.test/2' => 'PART'], $fetch(''), 'a curl too old to say: the files are all there is to go on');
    }

    /**
     * Two addresses, each answered with a file, and $outcomes as what curl wrote.
     *
     * @return array<string, ?string>
     */
    private static function fetchWith(string $outcomes): array
    {
        $answers = [];
        new HttpFetch(static function (array $argv, string $cwd, \Closure $onExit) use ($outcomes): void {
            foreach ($argv as $i => $arg) {
                if ('-o' !== $arg) {
                    continue;
                }
                \file_put_contents($argv[$i + 1], data: 'PART');
            }
            $onExit(28, $outcomes);
        })->get(['https://a.test/1', 'https://a.test/2'], static function (array $bodies) use (&$answers): void {
            $answers = $bodies;
        });

        return $answers;
    }

    public function testNothingToFetchAnswersAtOnceWithoutASpawn(): void
    {
        $answers = null;
        HttpFetch::create(new RunningProcesses())->get([], static function (array $bodies) use (&$answers): void {
            $answers = $bodies;
        });
        $this->assertSame([], $answers);
    }

    public function testARealCurlAnswersAFileAddress(): void
    {
        // https-only is the point, so a refused protocol is a failed transfer:
        // the real process path, end to end, with no network.
        $project = new TempProject(['page.txt' => 'hello']);
        $answers = null;
        new HttpFetch()->get(['file://'.$project->path('page.txt')], static function (array $bodies) use (&$answers): void {
            $answers = $bodies;
        });
        $this->await(static function () use (&$answers): bool {
            return null !== $answers;
        }, what: 'curl to exit');
        $this->assertSame(['file://'.$project->path('page.txt') => null], $answers);
    }
}
