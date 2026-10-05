<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Lsp\LspSession;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\Uri;
use Porta\Lsp\WatchedFiles;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\TempProject;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class WatchedFilesTest extends TestCase
{
    private TempProject $project;

    private MockClock $clock;

    /** @var array<string, FakeLspClient> server name => the client the pool built for it */
    private array $clients = [];

    private LspSessionPool $pool;

    private WatchedFiles $watched;

    protected function setUp(): void
    {
        $this->project = new TempProject([
            'src/A.php' => '<?php // a',
            'src/B.php' => '<?php // b',
            'composer.json' => '{}',
            'README.md' => '# r',
            'vendor/acme/lib/C.php' => '<?php // c',
            '.git/hooks/D.php' => '<?php // d',
        ]);
        $this->clock = new MockClock();
        $this->pool = new LspSessionPool(
            $this->project->dir,
            new FixedActiveFile(),
            new Translator('en'),
            fn (array $command, string $rootDir, \Closure $onLog, ?array $options): FakeLspClient => $this->clients[$command[0]] = new FakeLspClient(),
        );
        $this->pool->configure(
            [
                'php' => [['command' => ['php-ls'], 'name' => 'php-ls'], ['command' => ['other-ls'], 'name' => 'other-ls']],
            ],
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );
        $this->watched = new WatchedFiles($this->pool, $this->project->dir, $this->clock);
    }

    protected function tearDown(): void
    {
        $this->pool->stop();
    }

    /** Start both servers, the way a file's first didOpen does. */
    private function startServers(): void
    {
        $this->pool->notifyDidOpen($this->project->path('src/A.php'), '<?php // a', 'php', 1);
    }

    /** @return list<list<array{uri: string, type: int}>> every didChangeWatchedFiles $server was sent */
    private function sent(string $server): array
    {
        $sent = [];
        foreach ($this->clients[$server]->notifications as $notification) {
            if ('workspace/didChangeWatchedFiles' !== $notification['method']) {
                continue;
            }
            /** @var array{changes: list<array{uri: string, type: int}>} $params */
            $params = $notification['params'];
            $sent[] = $params['changes'];
        }

        return $sent;
    }

    private function uri(string $relative): string
    {
        return Uri::fromPath($this->project->path($relative));
    }

    public function testTheFirstSweepIsABaselineAndTheNextReportsCreatedChangedAndDeleted(): void
    {
        $this->startServers();
        $this->clients['php-ls']->fileWatchers = [['glob' => '**/*.php', 'kind' => 7]];

        $this->watched->tick();
        $this->assertSame([], $this->sent('php-ls'), 'the server indexed what was there: nothing has changed yet');

        $this->project->write('src/A.php', '<?php // a, edited');
        $this->project->write('src/New.php', '<?php // new');
        \unlink($this->project->path('src/B.php'));
        $this->watched->tick();

        $this->assertSame(
            [[ // in path order, whatever order the directory listed them
                ['uri' => $this->uri('src/A.php'), 'type' => 2],
                ['uri' => $this->uri('src/B.php'), 'type' => 3],
                ['uri' => $this->uri('src/New.php'), 'type' => 1],
            ]],
            $this->sent('php-ls'),
        );

        $this->watched->tick();
        $this->assertCount(1, $this->sent('php-ls'), 'a sweep that sees nothing new says nothing');
    }

    public function testEachServerHearsOnlyItsOwnGlobsAndKinds(): void
    {
        $this->startServers();
        $this->clients['php-ls']->fileWatchers = [['glob' => '**/*.php', 'kind' => 7]];
        // Only changes, and only to the manifest.
        $this->clients['other-ls']->fileWatchers = [['glob' => '**/composer.json', 'kind' => 2]];
        $this->watched->tick();

        $this->project->write('composer.json', '{"name": "x"}');
        $this->project->write('src/New.php', '<?php // new');
        $this->project->write('README.md', '# changed, and watched by nobody');
        $this->watched->tick();

        $this->assertSame([[['uri' => $this->uri('src/New.php'), 'type' => 1]]], $this->sent('php-ls'));
        $this->assertSame([[['uri' => $this->uri('composer.json'), 'type' => 2]]], $this->sent('other-ls'));

        \unlink($this->project->path('composer.json'));
        $this->watched->tick();
        $this->assertCount(1, $this->sent('other-ls'), 'a deletion it did not ask for (kind 2 has no delete bit) is not sent');
    }

    public function testVendorAndVersionControlAreNeverWalked(): void
    {
        $this->startServers();
        $this->clients['php-ls']->fileWatchers = [['glob' => '**/*.php', 'kind' => 7]];
        $this->watched->tick();

        $this->project->write('vendor/acme/lib/C.php', '<?php // c, updated by composer');
        $this->project->write('.git/hooks/D.php', '<?php // d, changed');
        $this->watched->tick();

        $this->assertSame([], $this->sent('php-ls'));
    }

    public function testNothingIsWalkedUntilAServerWatchesAndItsFirstSweepIsItsBaseline(): void
    {
        $this->startServers();
        $this->watched->tick();
        $this->project->write('src/A.php', '<?php // a, edited before anyone watched');

        $this->clients['php-ls']->fileWatchers = [['glob' => '**/*.php', 'kind' => 7]];
        $this->watched->tick();
        $this->assertSame([], $this->sent('php-ls'), 'the first sweep a watcher sees is its baseline');

        // Every watcher gone: the baseline goes with them, so a server that
        // registers again later is not told about what changed in between.
        $this->clients['php-ls']->fileWatchers = [];
        $this->watched->tick();
        $this->project->write('src/B.php', '<?php // b, edited while nobody watched');
        $this->clients['php-ls']->fileWatchers = [['glob' => '**/*.php', 'kind' => 7]];
        $this->watched->tick();
        $this->assertSame([], $this->sent('php-ls'));
    }

    public function testASweepTooBigForOneTickIsSlicedAndReportsOnlyWhenItCompletes(): void
    {
        for ($i = 0; $i < 600; ++$i) {
            $this->project->write(\sprintf('src/Many/F%03d.php', $i), '<?php');
        }
        $this->startServers();
        $this->clients['php-ls']->fileWatchers = [['glob' => '**/*.php', 'kind' => 7]];
        $this->watched->tick();
        $this->watched->tick(); // 600 files and their directories: two slices make the baseline

        $this->project->write('src/A.php', '<?php // a, edited');
        $this->watched->tick();
        $this->assertSame([], $this->sent('php-ls'), 'half a sweep has not seen everything: it reports nothing yet');
        $this->watched->tick();
        $this->assertSame([[['uri' => $this->uri('src/A.php'), 'type' => 2]]], $this->sent('php-ls'));
    }

    public function testASliceThatOverrunsStandsTheNextTickDown(): void
    {
        $this->startServers();
        $this->clients['php-ls']->fileWatchers = [['glob' => '**/*.php', 'kind' => 7]];
        $this->watched->tick();

        $this->project->write('src/A.php', '<?php // a, edited');
        // A clock on which every reading is 60ms after the last: each slice
        // overruns the 50ms budget, so the tick after it is stood down.
        $slow = new WatchedFiles($this->pool, $this->project->dir, new class implements ClockInterface {
            private float $now = 0.0;

            public function now(): \DateTimeImmutable
            {
                $this->now += 0.06;

                return new \DateTimeImmutable(\sprintf('@%.6F', $this->now));
            }
        });
        $slow->tick(); // its baseline, slowly
        $this->project->write('src/A.php', '<?php // a, edited again');
        $slow->tick();
        $this->assertSame([], $this->sent('php-ls'), 'the tick after an overrun is skipped');
        $slow->tick();
        $this->assertCount(1, $this->sent('php-ls'), 'and the one after that sweeps');
    }

    public function testAFileNamedInDigitsIsReportedLikeAnyOther(): void
    {
        // `2024` keys an array as an int: handed to preg_match() or a string
        // parameter as one, it was a TypeError out of the loop timer.
        $this->startServers();
        $this->clients['php-ls']->fileWatchers = [['glob' => '**/*', 'kind' => 7]];
        $this->watched->tick();

        $this->project->write('2024', 'a year');
        $this->watched->tick();

        $this->assertSame([[['uri' => $this->uri('2024'), 'type' => 1]]], $this->sent('php-ls'));
    }

    public function testAChangeToWhatIsWatchedIsANewBaselineNotAFloodOfCreations(): void
    {
        $this->startServers();
        $this->clients['php-ls']->fileWatchers = [['glob' => '**/*.php', 'kind' => 7]];
        $this->watched->tick();

        // A second server starts watching the manifest: every file it covers
        // already existed, and is not news to it.
        $this->clients['other-ls']->fileWatchers = [['glob' => '**/composer.json', 'kind' => 7]];
        $this->watched->tick();
        $this->assertSame([], $this->sent('other-ls'));
        $this->assertSame([], $this->sent('php-ls'));

        $this->project->write('composer.json', '{"name": "x"}');
        $this->watched->tick();
        $this->assertSame([[['uri' => $this->uri('composer.json'), 'type' => 2]]], $this->sent('other-ls'));
    }

    public function testWhatGitIgnoresIsNotWalked(): void
    {
        $this->project->write('.gitignore', "/var/\n");
        $this->startServers();
        $this->clients['php-ls']->fileWatchers = [['glob' => '**/*.php', 'kind' => 7]];
        $this->watched->tick();

        $this->project->write('var/cache/Container.php', '<?php // a cache warmup');
        $this->project->write('src/A.php', '<?php // a, edited');
        $this->watched->tick();

        $this->assertSame([[['uri' => $this->uri('src/A.php'), 'type' => 2]]], $this->sent('php-ls'));
    }

    public function testAnAbsoluteGlobMatchesTheAbsolutePath(): void
    {
        $this->startServers();
        $this->clients['php-ls']->fileWatchers = [['glob' => $this->project->dir.'/src/*.php', 'kind' => 7]];
        $this->watched->tick();

        $this->project->write('src/A.php', '<?php // a, edited');
        $this->project->write('README.md', '# not under the glob');
        $this->watched->tick();

        $this->assertSame([[['uri' => $this->uri('src/A.php'), 'type' => 2]]], $this->sent('php-ls'));
    }

    public function testAServerGoneOrNotYetUpIsToldNothing(): void
    {
        // A session whose server never started watches nothing and is sent nothing.
        $session = new LspSession($this->project->dir);
        $this->assertSame([], $session->fileWatchers());
        $session->notifyWatchedFiles([['uri' => $this->uri('src/A.php'), 'type' => 2]]);

        // A signature whose session is gone since the sweep began is a no-op.
        $this->pool->notifyWatchedFiles('gone', [['uri' => $this->uri('src/A.php'), 'type' => 2]]);
        $this->assertSame([], $this->pool->fileWatchers(), 'no server was started');
    }

    public function testAProjectRootThatIsNotADirectoryIsAnEmptyWalk(): void
    {
        $this->startServers();
        $this->clients['php-ls']->fileWatchers = [['glob' => '**/*.php', 'kind' => 7]];
        $gone = new WatchedFiles($this->pool, $this->project->path('nowhere'), $this->clock);
        $gone->tick();
        $gone->tick();
        $this->assertSame([], $this->sent('php-ls'));
    }
}
