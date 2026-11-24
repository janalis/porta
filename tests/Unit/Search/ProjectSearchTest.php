<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Filesystem\ProjectLocation;
use Porta\Filesystem\RemoteFilesystem;
use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteTransport;
use Porta\Filesystem\RemoteTransportSource;
use Porta\I18n\Translator;
use Porta\Search\FileIndex;
use Porta\Search\ProjectSearch;
use Porta\Search\SearchOptions;
use Porta\Tests\Support\FakeRemoteTransport;
use Porta\Tests\Support\NoOpTransportVerbs;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTui;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Process\ExecutableFinder;

final class ProjectSearchTest extends TestCase
{
    use PreservesPath;
    use PumpsLoop;

    /** @return list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}> */
    private function awaitSearch(
        ProjectSearch $search,
        string $query,
        SearchOptions $options = new SearchOptions(),
        float $timeout = 3.0,
        string|array $scope = '.',
        string $mask = '',
    ): array {
        $results = null;
        $search->search(
            $query,
            static function (array $r) use (&$results): void {
                $results = $r;
            },
            $options,
            $scope,
            $mask,
        );

        $this->await(
            static function () use (&$results): bool {
                return null !== $results;
            },
            $timeout,
            what: 'the search to complete',
        );
        \assert(null !== $results, description: 'the search delivered results');

        return $results;
    }

    private function fixtures(): TempProject
    {
        return new TempProject([
            'src/A.php' => "<?php\nclass A { public \$needle = 1; }\n",
            'src/B.php' => "<?php\n// needle here too\n",
            'vendor/lib/C.php' => "<?php\n// needle in vendor is excluded\n",
            '.porta/history/ab12/1700000000.snap' => "<?php\n// needle in a local-history snapshot\n",
            'README.md' => "no match\n",
        ]);
    }

    /**
     * A remote project's cache holds only what was opened; its grep runs on
     * the server, so a file nobody fetched is found — with the same paths a
     * local search answers.
     */
    public function testARemoteProjectIsSearchedOnTheServer(): void
    {
        $server = new TempProject(['src/Deep.php' => "<?php\n// needle here\n", 'vendor/x.php' => 'needle']);
        $cache = new TempProject(['src/Deep.php' => '']);
        $transport = new FakeRemoteTransport($server->dir);
        $remote = $transport->filesystem($cache->dir);

        $results = $this->awaitSearch($search = new ProjectSearch($cache->dir, $remote), query: 'needle');

        $this->assertSame(
            [['path' => 'src/Deep.php', 'line' => 1]],
            \array_map(static fn (array $r): array => ['path' => $r['path'], 'line' => $r['line']], $results),
            'vendor/ is left out there as here',
        );
        $this->awaitSearch($search, query: 'needle');
        $this->assertContains('run grep', $transport->calls, 'searched by the server\'s grep');
        $this->assertNull(
            new RemoteFilesystem(
                new ProjectLocation(),
                $cache->dir,
                new readonly class implements RemoteTransportSource {
                    public function remoteTransport(RemoteSpec $spec): ?RemoteTransport
                    {
                        return null;
                    }
                },
                new Notifier(new StatusBarWidget(), TestTui::create()),
                new Translator('en'),
                new BufferManager(),
                new FileIndex($cache->dir),
                new EventDispatcher(),
            )->remoteCommand(['grep']),
            'a local project runs its commands here',
        );
    }

    /** On a server the vendor dir its composer.json moved is left out too: what the walk read, grep's hits there dropped. */
    public function testARemoteProjectLeavesOutTheVendorDirItsServerMoved(): void
    {
        $server = new TempProject([
            'composer.json' => '{"config":{"vendor-dir":"lib/deps"}}',
            'lib/Own.php' => "<?php\n// needle\n",
            'lib/deps/acme/Lib.php' => "<?php\n// needle\n",
            'lib/deps/other/X.php' => "<?php\n// needle\n",
            'src/lib/deps/Own.php' => "<?php\n// needle\n",
        ]);
        $cache = new TempProject();
        $transport = new FakeRemoteTransport($server->dir);
        $transport->walks = true;
        $remote = $transport->filesystem($cache->dir);
        $remote->poll();
        $this->await(static fn (): bool => null !== $remote->vendorPath(), what: 'the walk to read the server\'s composer.json');

        $paths = \array_column($this->awaitSearch(new ProjectSearch($cache->dir, $remote), query: 'needle'), 'path');
        \sort($paths);
        $this->assertSame(['lib/Own.php', 'src/lib/deps/Own.php'], $paths);
        $named = \array_column($this->awaitSearch(new ProjectSearch($cache->dir, $remote), query: 'needle', scope: ['lib/deps']), 'path');
        \sort($named);
        $this->assertSame(['lib/deps/acme/Lib.php', 'lib/deps/other/X.php'], $named, 'named as the scope, its hits are kept');
        $mixed = \array_column($this->awaitSearch(new ProjectSearch($cache->dir, $remote), query: 'needle', scope: ['lib', 'lib/deps/acme']), 'path');
        \sort($mixed);
        $this->assertSame(['lib/Own.php', 'lib/deps/acme/Lib.php'], $mixed, 'per scope path: one naming a part of it keeps that part only — once');

        // More hits there than a search keeps, read first and long enough to
        // come in many chunks: they must not stop it before the real one.
        $server->write('lib/deps/acme/Many.php', \str_repeat('needle '.\str_repeat('x', times: 60)."\n", times: 20_000));
        $paths = \array_column($this->awaitSearch(new ProjectSearch($cache->dir, $remote), query: 'needle', scope: ['lib', 'src']), 'path');
        $this->assertContains('src/lib/deps/Own.php', $paths);
        $remote->close();
    }

    /**
     * A whole-project search prints `./lib/deps/…`: those hits are not
     * counted toward the early stop either. grep walks `.` in the
     * directory's own order, so the vendor dir is whichever it reads first.
     */
    public function testARemoteWholeProjectSearchIsNotStoppedByTheMovedVendorDir(): void
    {
        $server = new TempProject(['p/.keep' => '', 'q/.keep' => '']);
        $dir = \opendir($server->dir);
        \assert(false !== $dir, description: 'the server dir opens');
        $order = [];
        while (false !== ($entry = \readdir($dir))) {
            $order[] = $entry; // as the disk hands them over, unsorted
        }
        \closedir($dir);
        $order = \array_values(\array_intersect($order, ['p', 'q']));
        [$vendor, $own] = $order;
        $server->write('composer.json', (string) \json_encode(['config' => ['vendor-dir' => $vendor]], \JSON_THROW_ON_ERROR));
        $server->write($vendor.'/acme/Many.php', \str_repeat('needle '.\str_repeat('x', times: 60)."\n", times: 20_000));
        $server->write($own.'/Own.php', "<?php\n// needle\n");
        $cache = new TempProject();
        $transport = new FakeRemoteTransport($server->dir);
        $transport->walks = true;
        $remote = $transport->filesystem($cache->dir);
        $remote->poll();
        $this->await(static fn (): bool => null !== $remote->vendorPath(), what: 'the walk to read the server\'s composer.json');

        $this->assertSame([$own.'/Own.php'], \array_column($this->awaitSearch(new ProjectSearch($cache->dir, $remote), query: 'needle'), 'path'));
        $remote->close();
    }

    /**
     * A line read without a NUL is tried at every `:N:` — up to PATH_MAX, no
     * further: no path is longer. A 166 KB log line of 10,000 timestamps,
     * tried at each, held 871 MB of readings (measured), one checked path apiece.
     */
    public function testALongLineIsTriedNoFurtherThanAPathGoes(): void
    {
        // A server grep without --null, whose files this copy has not listed:
        // no reading names one here, so every place is tried. PATH_MAX bounds it.
        [$search, $kept] = $this->serverSearchWith(self::REFUSES_NULL, ['logs/app.json' => \str_repeat('{"t":"12:30:45"},', times: 10_000)."needle\n"]);

        $this->assertSame(['logs/app.json'], \array_column($this->awaitSearch($search, query: 'needle'), 'path'));
        $this->assertLessThan(1_000, \count(Probe::get(Probe::object($search, 'hits'), property: 'files')), 'the paths it checked');
        unset($kept);
    }

    /** A search before the server is reached has no one to ask: --null is asked once it is. */
    public function testTheServerIsAskedAboutNullOnceItIsReached(): void
    {
        $server = new TempProject(['src/A.php' => "<?php\n// needle\n"]);
        $cache = new TempProject();
        $source = new class implements RemoteTransportSource {
            public ?RemoteTransport $transport = null;

            public function remoteTransport(RemoteSpec $spec): ?RemoteTransport
            {
                return $this->transport;
            }
        };
        $remote = new RemoteFilesystem(
            new ProjectLocation(new RemoteSpec('ssh', 'example.org', '/srv')),
            $cache->dir,
            $source,
            new Notifier(new StatusBarWidget(), TestTui::create()),
            new Translator('en'),
            new BufferManager(),
            new FileIndex($cache->dir),
            new EventDispatcher(),
        );
        $search = new ProjectSearch($cache->dir, $remote);
        $this->awaitSearch($search, query: 'needle');
        $this->assertTrue(self::takes($search), 'no server yet: searched here, and this machine\'s grep was asked');
        $this->assertTrue($search->searchedLocalCopyOnly(), 'and the search says it read only this copy');
        $this->assertNull(self::takes($search, '--null@server'), 'the server\'s, not');

        $source->transport = new FakeRemoteTransport($server->dir);
        $this->awaitSearch($search, query: 'needle');

        $this->assertTrue(self::takes($search, '--null@server'), 'the server, reached, was asked, and the search waited for it');
    }

    /**
     * A search of a server whose grep runs $prelude first (a line of sh, given
     * the arguments) and then the real grep — and what must outlive it.
     *
     * @param array<string, string> $files the server's
     *
     * @return array{ProjectSearch, list<TempProject>}
     */
    // @mago-expect lint:no-boolean-flag-parameter -- whether the tree was walked is the one fact the fixture varies
    private function serverSearchWith(string $prelude, array $files = ['src/A.php' => "<?php\n// needle\n"], bool $walked = false): array
    {
        $tools = new TempProject([], 'porta-wrapgrep-');
        $real = (string) new ExecutableFinder()->find('grep');
        $tools->write('grep', "#!/bin/sh\n{$prelude}\nexec '{$real}' \"\$@\"\n");
        \chmod($tools->path('grep'), permissions: 0o755);
        \putenv('PATH='.$tools->dir.':'.\PORTA_TEST_PRISTINE_PATH);
        $server = new TempProject($files);
        $cache = new TempProject();
        $transport = new FakeRemoteTransport($server->dir);
        $transport->walks = $walked;
        $remote = $transport->filesystem($cache->dir);
        if ($walked) {
            $remote->poll();
            $this->await(static fn (): bool => \array_any(\array_keys($files), static fn (string $file): bool => \is_file($cache->path($file))), what: 'the walk to land the placeholders');
        }

        return [new ProjectSearch($cache->dir, $remote), [$tools, $server, $cache]];
    }

    /** A sh line for {@see serverSearchWith()}: a server grep that refuses --null, as BusyBox's may. */
    private const string REFUSES_NULL = 'for a in "$@"; do [ "$a" = --null ] && exit 2; done';

    /** @return bool|null what the search's grep said of $flag, null before it has */
    private static function takes(ProjectSearch $search, string $flag = '--null'): ?bool
    {
        /** @var array<string, bool> $takes */
        $takes = Probe::get(Probe::object($search, 'answers'), property: 'takes');

        return $takes[$flag] ?? null;
    }

    /** A search waiting on grep's answers and replaced before they land never runs: only the one that replaced it answers. */
    public function testASearchReplacedWhileItWaitsNeverAnswers(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// needle\n// other\n"]);
        $search = new ProjectSearch($project->dir);
        $first = null;
        $search->search('needle', static function (array $results) use (&$first): void {
            $first = $results;
        });
        $this->assertNotSame([], Probe::get(Probe::object($search, 'answers'), property: 'waiting'), 'the premise: it waits on the answer');

        $this->assertSame(['src/A.php'], \array_column($this->awaitSearch($search, query: 'other'), 'path'));
        $this->assertNull($first, 'the replaced search never ran');
    }

    /** One cancelled while it waits (the panel closed) does not run when the answer lands. */
    public function testASearchCancelledWhileItWaitsNeverAnswers(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// needle\n"]);
        $search = new ProjectSearch($project->dir);
        $answered = false;
        $search->search('needle', static function () use (&$answered): void {
            $answered = true;
        });

        $search->cancel();
        $this->await(static fn (): bool => null !== self::takes($search), what: 'grep to answer');
        $this->pump(0.2);

        $this->assertFalse($answered);
    }

    /**
     * A regex here whose -P question got no answer is never handed to grep
     * as ERE (GNU's reads `\d+` as "ddd" there): the built-in engine reads it.
     */
    public function testARegexWithNoAnswerAboutPcreIsReadByTheBuiltInEngine(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// 42\n"]);
        $tools = new TempProject([], 'porta-nopgrep-');
        $log = $tools->path('argv.log');
        $tools->write('grep', "#!/bin/sh\nif [ \"\$1\" = --null ] && [ \"\$2\" = -q ]; then exit 1; fi\nif [ \"\$1\" = -qP ]; then exit 255; fi\nprintf '%s\\n' \"\$*\" >> '{$log}'\nexit 1\n");
        \chmod($tools->path('grep'), permissions: 0o755);
        \putenv('PATH='.$tools->dir.':/bin');

        $results = $this->awaitSearch(new ProjectSearch($project->dir), query: '\d+', options: new SearchOptions(regex: true));

        $this->assertSame(['src/A.php'], \array_column($results, 'path'), 'found, by the engine that reads it as PCRE');
        $this->assertFileDoesNotExist($log, 'grep was never handed it');
    }

    /** On a server reached, a regex asks this machine's grep nothing about -P: the server's is asked for ERE. */
    public function testARegexOnAServerWaitsOnNoQuestionOfThisMachine(): void
    {
        $server = new TempProject(['src/A.php' => "<?php\n// 42\n"]);
        $cache = new TempProject();
        $search = new ProjectSearch($cache->dir, new FakeRemoteTransport($server->dir)->filesystem($cache->dir));

        $this->assertSame(['src/A.php'], \array_column($this->awaitSearch($search, query: '[0-9]+', options: new SearchOptions(regex: true)), 'path'));
        $this->assertArrayNotHasKey('-P', (array) Probe::get(Probe::object($search, 'answers'), property: 'asking'), 'this machine\'s grep was never asked');
    }

    /** A probe that gets no answer (ssh failing: 255) settles nothing: the next search asks again. */
    public function testAProbeWithNoAnswerIsAskedAgain(): void
    {
        $markers = new TempProject();
        $marker = $markers->path('asked');
        [$search, $kept] = $this->serverSearchWith("if [ \"\$1\" = --null ] && [ \"\$2\" = -q ] && [ ! -e '{$marker}' ]; then : > '{$marker}'; exit 255; fi");

        $this->assertSame(['src/A.php'], \array_column($this->awaitSearch($search, query: 'needle'), 'path'), 'searched all the same');
        $this->assertFileExists($marker);
        $this->assertNull(self::takes($search, '--null@server'), 'settled nothing');

        // Asked again; once unanswered, it is not waited on, but it answers.
        $this->awaitSearch($search, query: 'needle');
        $this->await(static fn (): bool => true === self::takes($search, '--null@server'), what: 'the second question to answer');
        unset($kept);
    }

    /** The server's grep is asked whether it takes --null once, over every search after. */
    public function testTheServersGrepIsAskedOnce(): void
    {
        $logs = new TempProject();
        $log = $logs->path('asked.log');
        // Where it runs is logged: the server's question runs in the server's project dir.
        [$search, $kept] = $this->serverSearchWith("if [ \"\$1\" = --null ] && [ \"\$2\" = -q ]; then pwd -P >> '{$log}'; fi");

        $this->awaitSearch($search, query: 'needle');
        $this->awaitSearch($search, query: 'needle');

        $this->assertSame(\realpath($kept[1]->dir)."\n", \file_get_contents($log), 'once, and of the server');
        unset($kept);
    }

    /**
     * A server whose -P question gets no answer (a slow link) still searches
     * the server, as ERE: the built-in engine would read only the copy here,
     * and a short list read as the whole answer is worse.
     */
    public function testAServerWithNoAnswerAboutPcreIsStillSearchedThere(): void
    {
        [$search, $kept] = $this->serverSearchWith('[ "$1" = -qP ] && exit 255', ['src/A.php' => "<?php\n// 42\n"]);

        $this->assertSame(['src/A.php'], \array_column($this->awaitSearch($search, query: '[0-9]+', options: new SearchOptions(regex: true)), 'path'), 'found on the server: this copy holds nothing');
        $this->assertFalse($search->searchedLocalCopyOnly());
        unset($kept);
    }

    /**
     * A server whose -P question got no answer is not handed a regex only
     * PCRE reads (its ERE reads `\d` as `d`): the built-in engine reads it,
     * over the copy here, and the search says so — not a wrong answer that
     * looks whole.
     */
    public function testAServerWithNoAnswerAboutPcreIsNotHandedWhatOnlyPcreReads(): void
    {
        [$search, $kept] = $this->serverSearchWith('[ "$1" = -qP ] && exit 255', ['src/A.php' => "<?php\n// 42\n"]);

        $this->awaitSearch($search, query: '\d+', options: new SearchOptions(regex: true));

        $this->assertTrue($search->searchedLocalCopyOnly());
        unset($kept);
    }

    /** A grep here that refused -P is not handed a regex only PCRE reads either; one ERE reads the same, it is. */
    public function testAGrepRefusingPcreIsHandedOnlyWhatEreReadsTheSame(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// 42\n"]);
        $tools = new TempProject([], 'porta-noperlgrep-');
        $log = $tools->path('argv.log');
        $real = (string) new ExecutableFinder()->find('grep');
        $tools->write('grep', "#!/bin/sh\n[ \"\$1\" = -qP ] && exit 2\nprintf '%s\\n' \"\$*\" >> '{$log}'\nexec '{$real}' \"\$@\"\n");
        \chmod($tools->path('grep'), permissions: 0o755);
        \putenv('PATH='.$tools->dir.':'.\PORTA_TEST_PRISTINE_PATH);
        $regex = new SearchOptions(regex: true);
        $search = new ProjectSearch($project->dir);

        $this->assertSame(['src/A.php'], \array_column($this->awaitSearch($search, query: '\d+', options: $regex), 'path'), 'read by the built-in engine');
        $this->assertFalse(\is_file($log) && \str_contains((string) \file_get_contents($log), '\d+'), 'grep was never handed it');

        $this->assertSame(['src/A.php'], \array_column($this->awaitSearch($search, query: '[0-9]+', options: $regex), 'path'));
        $this->assertStringContainsString('-E -- [0-9]+', (string) \file_get_contents($log), 'one ERE reads the same goes to grep');
    }

    /** The server's grep is asked to run in UTF-8, as the one here: ssh often hands it C, where `.` is a byte. */
    public function testTheServersGrepRunsInUtf8(): void
    {
        $server = new TempProject(['src/A.php' => "<?php\n// needle\n"]);
        $cache = new TempProject([]);
        $seen = new \ArrayObject();
        $recording = new class($server->dir, $seen) extends RemoteTransport {
            use NoOpTransportVerbs;

            /** @param \ArrayObject<int, list<string>> $seen */
            public function __construct(
                private readonly string $root,
                private readonly \ArrayObject $seen,
            ) {}

            public function remoteCommand(array $argv): array
            {
                $this->seen->append($argv);

                return ['sh', '-c', 'cd "$1" && shift && exec "$@"', 'sh', $this->root, ...$argv];
            }
        };
        $search = new ProjectSearch($cache->dir, FakeRemoteTransport::over($recording, new RemoteSpec('ssh', 'example.org', '/srv'), $cache->dir));

        $this->assertSame(['src/A.php'], \array_column($this->awaitSearch($search, query: 'needle'), 'path'));
        $searches = \array_values(\array_filter($seen->getArrayCopy(), static fn (array $argv): bool => \in_array('needle', $argv, strict: true)));
        $this->assertSame(['env', 'LC_ALL=C.UTF-8', 'grep'], \array_slice($searches[0] ?? [], offset: 0, length: 3));
    }

    /** A server that stops building commands mid-search has no grep run: the built-in engine reads the copy here, and says so. */
    public function testAServerThatStopsBuildingCommandsIsReadInTheCopy(): void
    {
        $server = new TempProject(['src/A.php' => "<?php\n// needle\n"]);
        $cache = new TempProject(['src/A.php' => "<?php\n// needle\n"]);
        $once = new class($server->dir) extends RemoteTransport {
            use NoOpTransportVerbs;

            private int $built = 0;

            public function __construct(
                private readonly string $root,
            ) {}

            public function remoteCommand(array $argv): ?array
            {
                return 1 === ++$this->built ? ['sh', '-c', 'cd "$1" && shift && exec "$@"', 'sh', $this->root, ...$argv] : null;
            }
        };
        $search = new ProjectSearch($cache->dir, FakeRemoteTransport::over($once, new RemoteSpec('ssh', 'example.org', '/srv'), $cache->dir));

        $this->assertSame(['src/A.php'], \array_column($this->awaitSearch($search, query: 'needle'), 'path'));
        $this->assertTrue($search->searchedLocalCopyOnly());
    }

    /** A machine with no grep of its own still has a remote project searched by the server's. */
    public function testWithNoGrepHereAServerIsStillSearched(): void
    {
        [$search, $kept] = $this->serverSearchWith('');
        Probe::set($search, property: 'grepResolved', value: true); // this machine's grep: looked for, and not found

        $this->assertSame(['src/A.php'], \array_column($this->awaitSearch($search, query: 'needle'), 'path'));
        $this->assertFalse($search->searchedLocalCopyOnly(), 'the server answered');
        unset($kept);
    }

    /** A server whose grep refuses --null (BusyBox's may) is never handed it: its searches go on as they were. */
    public function testAServersGrepWithoutNullIsNotHandedIt(): void
    {
        [$search, $kept] = $this->serverSearchWith(self::REFUSES_NULL);

        $this->awaitSearch($search, query: 'needle');

        $this->assertFalse(self::takes($search, '--null@server'));
        $this->assertSame(['src/A.php'], \array_column($this->awaitSearch($search, query: 'needle'), 'path'));
        $this->assertFalse(Probe::get($search, property: 'localOnly'), 'the server answered, not this copy');
        unset($kept);
    }

    public function testGrepSearchFindsMatchesAndExcludesVendor(): void
    {
        $project = $this->fixtures();
        $results = $this->awaitSearch(new ProjectSearch($project->dir), query: 'needle');

        $paths = \array_column($results, 'path');
        \sort($paths);
        $this->assertSame(['src/A.php', 'src/B.php'], $paths);
        $this->assertSame(1, $results[0]['line'], '0-based line index');
    }

    /**
     * Local history's snapshots are left out, and nothing else of `.porta/`:
     * a project's own plugins live in `.porta/plugins`.
     *
     * @return list<string> the sorted result paths, for the whole project and scoped to `.porta`
     */
    /** @param (\Closure(string): RemoteFilesystem)|null $wiring what the search is built with, for the project's root */
    private function portaPaths(SearchBackend $backend, string|array $scope, ?\Closure $wiring = null): array
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// needle\n",
            '.porta/plugins/acme/porta.php' => "<?php\n// needle in a project plugin\n",
            '.porta/history/ab12/1700000000.snap' => "<?php\n// needle in a snapshot\n",
        ]);
        if (SearchBackend::PhpFallback === $backend) {
            \putenv('PATH=/nonexistent');
        }
        $search = new ProjectSearch($project->dir, null === $wiring ? null : $wiring($project->dir));
        $paths = \array_column($this->awaitSearch($search, query: 'needle', scope: $scope), 'path');
        \sort($paths);
        $this->assertFalse(Probe::get($search, property: 'localOnly'), 'a local project has no server to have missed');

        return $paths;
    }

    /** What the container hands a LOCAL project: a remote filesystem with no transport behind it. */
    private static function localRemoteFilesystem(string $root): RemoteFilesystem
    {
        return new RemoteFilesystem(
            new ProjectLocation(),
            $root,
            new readonly class implements RemoteTransportSource {
                public function remoteTransport(RemoteSpec $spec): ?RemoteTransport
                {
                    return null;
                }
            },
            new Notifier(new StatusBarWidget(), TestTui::create()),
            new Translator('en'),
            new BufferManager(),
            new FileIndex($root),
            new EventDispatcher(),
        );
    }

    /** Handing grep the root's entries must not hand it a link out of the project: a walk of `.` never follows one. */
    public function testLeavingOutHistoryNeverHandsGrepALinkOutOfTheProject(): void
    {
        $outside = new TempProject(['secret.txt' => "needle outside\n"]);
        $project = new TempProject(['src/A.php' => "<?php\n// needle\n", '.porta/history/ab/1.snap' => "needle\n"]);
        \symlink($outside->dir, $project->path('escape'));
        \symlink($outside->dir, $project->path('.porta/escape'));

        $paths = \array_column($this->awaitSearch(new ProjectSearch($project->dir), query: 'needle'), 'path');
        $this->assertSame(['src/A.php'], $paths);

        // ugrep and BSD grep follow no link under -r; GNU grep follows one on
        // its command line. What grep is HANDED is what decides it on Linux.
        $tools = new TempProject([], 'porta-argvgrep-');
        $log = $tools->path('argv.log');
        $tools->write('grep', "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> '{$log}'\nexit 1\n");
        \chmod($tools->path('grep'), permissions: 0o755);
        \putenv('PATH='.$tools->dir.':/bin');
        $this->awaitSearch(new ProjectSearch($project->dir), query: 'needle');
        $argv = (string) \file_get_contents($log);
        $this->assertStringContainsString(' src', $argv, 'the premise: the root is handed over by its entries');
        $this->assertStringNotContainsString('escape', $argv);
    }

    public function testGrepLeavesOutLocalHistoryAndNotTheRestOfPorta(): void
    {
        $this->assertSame(
            ['.porta/plugins/acme/porta.php', 'src/A.php'],
            $this->portaPaths(SearchBackend::Grep, scope: '.', wiring: self::localRemoteFilesystem(...)),
            'wired as the container wires it',
        );
        $this->assertSame(['.porta/plugins/acme/porta.php', 'src/A.php'], $this->portaPaths(SearchBackend::Grep, scope: '.'));
        $this->assertSame(['.porta/plugins/acme/porta.php'], $this->portaPaths(SearchBackend::Grep, scope: '.porta'));
        $this->assertSame(['.porta/history/ab12/1700000000.snap'], $this->portaPaths(SearchBackend::Grep, ['.porta/history']), 'named as the scope, it is searched');
        $this->assertSame(['.porta/history/ab12/1700000000.snap'], $this->portaPaths(SearchBackend::PhpFallback, ['.porta/history']), 'by either engine');
    }

    /**
     * A vendor dir composer.json moved is left out by its PATH, as the
     * history is: a folder of that name elsewhere, or a sibling beside it,
     * is the project's own. Both engines, and what grep is handed.
     */
    public function testAMovedVendorDirIsLeftOutByItsPath(): void
    {
        $project = new TempProject([
            'composer.json' => '{"config":{"vendor-dir":"lib/deps"}}',
            'src/A.php' => "<?php\n// needle\n",
            'src/deps/Own.php' => "<?php\n// needle in a folder of the same name\n",
            'src/lib/deps/Own.php' => "<?php\n// needle at the same path, deeper\n",
            'lib/Own.php' => "<?php\n// needle beside the vendor dir\n",
            'lib/deps/pkg/B.php' => "<?php\n// needle in a package\n",
            'vendor/stale/C.php' => "<?php\n// needle in a vendor/ by name\n",
            'vendor/stale/vendor/D.php' => "<?php\n// needle in a vendor/ below it\n",
        ]);
        $found = function (string|array $scope) use ($project): array {
            $paths = \array_column($this->awaitSearch(new ProjectSearch($project->dir), query: 'needle', scope: $scope), 'path');
            \sort($paths);

            return $paths;
        };
        $own = ['lib/Own.php', 'src/A.php', 'src/deps/Own.php', 'src/lib/deps/Own.php'];

        $this->assertSame($own, $found('.'), 'grep');
        $this->assertSame(['lib/Own.php'], $found('lib'));
        $this->assertSame(['src/lib/deps/Own.php'], $found('src/lib'));
        // What the scope names is searched; what is skipped below it, still skipped.
        $this->assertSame(['lib/deps/pkg/B.php'], $found(['lib/deps']), 'named as the scope');
        $this->assertSame(['vendor/stale/C.php'], $found('vendor'), 'vendor/ named, not its own vendor/');

        \putenv('PATH=/nonexistent');
        $this->assertSame($own, $found('.'), 'the PHP walk');
        $this->assertSame(['lib/Own.php'], $found('lib'));
        $this->assertSame(['src/lib/deps/Own.php'], $found('src/lib'));
        $this->assertSame(['lib/deps/pkg/B.php'], $found(['lib/deps']));
        $this->assertSame(['vendor/stale/C.php'], $found('vendor'));

        $tools = new TempProject([], 'porta-argvgrep-');
        $log = $tools->path('argv.log');
        $tools->write('grep', "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> '{$log}'\nexit 1\n");
        \chmod($tools->path('grep'), permissions: 0o755);
        \putenv('PATH='.$tools->dir.':/bin');
        $found('.');
        $argv = (string) \file_get_contents($log);
        $this->assertStringContainsString(' lib/Own.php', $argv, 'the way there is handed over by its entries');
        $this->assertStringNotContainsString('lib/deps', $argv);
        $found('vendor');
        $this->assertStringContainsString(' -- needle vendor/.'."\n", (string) \file_get_contents($log), 'named so grep cannot skip it by name');
    }

    /**
     * vendor/ named as a server's scope, holding the dir its composer.json
     * moved: searched — though the cache never listed it — but not that dir,
     * whose hits grep prints as `vendor/./deps/…`.
     */
    public function testARemoteNamedVendorKeepsTheMovedDirBelowItSkipped(): void
    {
        $server = new TempProject([
            'composer.json' => '{"config":{"vendor-dir":"vendor/deps"}}',
            'vendor/Own.php' => "<?php\n// needle\n",
            'vendor/deps/pkg/B.php' => "<?php\n// needle in a package\n",
        ]);
        $cache = new TempProject();
        $transport = new FakeRemoteTransport($server->dir);
        $transport->walks = true;
        $remote = $transport->filesystem($cache->dir);
        $remote->poll();
        $this->await(static fn (): bool => null !== $remote->vendorPath(), what: 'the walk to read the server\'s composer.json');
        $this->assertDirectoryDoesNotExist($cache->path('vendor'), 'the premise: the cache has no vendor/');

        $this->assertSame(['vendor/Own.php'], \array_column($this->awaitSearch(new ProjectSearch($cache->dir, $remote), query: 'needle', scope: 'vendor'), 'path'));
        $remote->close();
    }

    /** Overlapping scope paths reach a file twice; each hit is listed once, by grep as by the built-in engine. */
    public function testOverlappingScopePathsListEachHitOnce(): void
    {
        $project = new TempProject(['lib/a/A.php' => "<?php\n// needle\n// needle\n", 'lib/B.php' => "<?php\n// needle\n"]);
        // In the panel's order, by path then line, whichever engine walked them.
        $hits = fn (): array => \array_map(
            static fn (array $r): string => $r['path'].':'.$r['line'],
            $this->awaitSearch(new ProjectSearch($project->dir), query: 'needle', scope: ['lib', 'lib/a']),
        );

        $this->assertSame(['lib/B.php:1', 'lib/a/A.php:1', 'lib/a/A.php:2'], $hits(), 'grep');
        \putenv('PATH=/nonexistent');
        $this->assertSame(['lib/B.php:1', 'lib/a/A.php:1', 'lib/a/A.php:2'], $hits(), 'the built-in engine');
    }

    /**
     * The early stop counts what a search keeps: overlapping scope paths
     * print the same hits twice, and those twice-printed lines stopped grep
     * before the hits beyond them — fewer than a search keeps, and nothing
     * said so. 300 nested hits and 400 more: all 500 a search keeps.
     */
    public function testOverlappingScopesDoNotStopASearchShort(): void
    {
        $project = new TempProject([
            'src/In/A.php' => \str_repeat("needle\n", times: 300),
            'src/B.php' => \str_repeat("needle\n", times: 400),
        ]);

        $this->assertCount(ProjectSearch::MAX_RESULTS, $this->awaitSearch(new ProjectSearch($project->dir), query: 'needle', scope: ['src/In', 'src']));
    }

    /** The files a grep line cannot tell apart by `path:line:` alone: names holding `:N:`, and a shorter name beside one. */
    private const array COLON_NAMES = [
        'notes/a' => "nothing here\n", // `notes/a:12:b.txt:3:` reads as this, line 12, at first
        'notes/a:12:b.txt' => "x\nx\nneedle\n",
        'notes/meeting-10:30' => "x\nx\nneedle\n", // `:30:3:` overlaps: `:30:` and `:3:` share a colon
        'notes/x:1:y:2:z.txt' => "needle :7: tail\n", // two pairs: x:1:y is no file
        'src/A.php' => "<?php\n// needle :5: here\n",
    ];

    /** @return list<array{string, int, string}> path, line, text of each hit */
    private function colonHits(ProjectSearch $search): array
    {
        return \array_map(static fn (array $r): array => [$r['path'], $r['line'], $r['text']], $this->awaitSearch($search, query: 'needle'));
    }

    /**
     * A file name may hold `:12:` itself. Here grep ends each name with a NUL
     * (`--null`), and every one is read exactly — beside a shorter name that
     * a `path:line:` reading would have taken.
     */
    public function testAFileNameHoldingAColonNumberColonIsReadWhole(): void
    {
        $project = new TempProject(self::COLON_NAMES);
        $search = new ProjectSearch($project->dir); // its first search waits for this grep to say it takes --null

        $this->assertSame(
            [
                ['notes/a:12:b.txt',    2, 'needle'],
                ['notes/meeting-10:30', 2, 'needle'],
                ['notes/x:1:y:2:z.txt', 0, 'needle :7: tail'],
                ['src/A.php',           1, '// needle :5: here'],
            ],
            $this->colonHits($search),
            'and a :5: in a line\'s text stays text',
        );
    }

    /**
     * A server's grep that refuses --null is read without the NUL: every
     * `:N:` is a place the name may end, overlapping ones too, and the first
     * that names a file this copy holds is the hit. Beside `notes/a`,
     * `notes/a:12:b.txt` cannot be told from line 12 of it: the shorter wins.
     */
    public function testAServersGrepWithoutNullIsReadAtTheFirstPlaceANameEnds(): void
    {
        [$search, $kept] = $this->serverSearchWith(self::REFUSES_NULL, self::COLON_NAMES, walked: true);

        $this->assertSame(
            [
                ['notes/a',             11, 'b.txt:3:needle'],
                ['notes/meeting-10:30', 2,  'needle'],
                ['notes/x:1:y:2:z.txt', 0,  'needle :7: tail'],
                ['src/A.php',           1,  '// needle :5: here'],
            ],
            $this->colonHits($search),
        );
        unset($kept);
    }

    /** A server's grep that takes --null is asked so before the first search, which then reads names exactly. */
    public function testAServersGrepWithNullReadsNamesExactly(): void
    {
        $server = new TempProject(self::COLON_NAMES);
        $cache = new TempProject();
        $remote = new FakeRemoteTransport($server->dir)->filesystem($cache->dir);

        $this->assertSame(
            [
                ['notes/a:12:b.txt',    2, 'needle'],
                ['notes/meeting-10:30', 2, 'needle'],
                ['notes/x:1:y:2:z.txt', 0, 'needle :7: tail'],
                ['src/A.php',           1, '// needle :5: here'],
            ],
            $this->colonHits(new ProjectSearch($cache->dir, $remote)),
        );
        $remote->close();
    }

    /** A scope holding nothing but a skipped folder leaves grep nothing to read — not its stdin, where it would wait. */
    public function testAScopeOfNothingButHistoryFindsNothing(): void
    {
        $project = new TempProject(['.porta/history/ab/1.snap' => "needle\n"]);

        $this->assertSame([], $this->awaitSearch(new ProjectSearch($project->dir), query: 'needle', scope: '.porta'));
    }

    /** vendor/ named as the scope, holding the vendor dir composer.json moved: searched, but not that dir. */
    public function testANamedVendorKeepsTheMovedDirBelowItSkipped(): void
    {
        $project = new TempProject([
            'composer.json' => '{"config":{"vendor-dir":"vendor/deps"}}',
            'vendor/Own.php' => "<?php\n// needle\n",
            'vendor/deps/pkg/B.php' => "<?php\n// needle in a package\n",
        ]);

        $this->assertSame(['vendor/Own.php'], \array_column($this->awaitSearch(new ProjectSearch($project->dir), query: 'needle', scope: 'vendor'), 'path'));
    }

    public function testThePhpWalkLeavesOutLocalHistoryAndNotTheRestOfPorta(): void
    {
        $this->assertSame(
            ['.porta/plugins/acme/porta.php', 'src/A.php'],
            $this->portaPaths(SearchBackend::PhpFallback, scope: '.', wiring: self::localRemoteFilesystem(...)),
            'wired as the container wires it: no "server" warning',
        );
        $this->assertSame(['.porta/plugins/acme/porta.php', 'src/A.php'], $this->portaPaths(SearchBackend::PhpFallback, scope: '.'));
        $this->assertSame(['.porta/plugins/acme/porta.php'], $this->portaPaths(SearchBackend::PhpFallback, scope: '.porta'));
    }

    /** @return list<string> the sorted result paths */
    private function scopedPaths(SearchBackend $backend): array
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// needle in src\n",
            'src/sub/B.php' => "<?php\n// needle deeper in src\n",
            'app/D.php' => "<?php\n// needle in app\n",
            'README.md' => "needle at the root\n",
        ]);
        if (SearchBackend::PhpFallback === $backend) {
            \putenv('PATH=/nonexistent');
        }

        $paths = \array_column($this->awaitSearch(new ProjectSearch($project->dir), query: 'needle', scope: 'src'), 'path');
        \sort($paths);

        return $paths;
    }

    public function testGrepSearchScopedToAFolder(): void
    {
        // Only the src/ subtree is searched; app/ and the root README are excluded,
        // and the returned paths stay project-root-relative.
        $this->assertSame(['src/A.php', 'src/sub/B.php'], $this->scopedPaths(SearchBackend::Grep));
    }

    public function testPhpFallbackScopedToAFolder(): void
    {
        // The Finder-based fallback prefixes the scope back on so paths match grep.
        $this->assertSame(['src/A.php', 'src/sub/B.php'], $this->scopedPaths(SearchBackend::PhpFallback));
    }

    public function testMatchOffsetsReturnsByteOffsetsOfEachHit(): void
    {
        $tempProject = new TempProject();
        $search = new ProjectSearch($tempProject->dir);

        $this->assertSame([0, 12], $search->matchOffsets('needle here needle', query: 'needle', options: new SearchOptions()));
        $this->assertSame([], $search->matchOffsets('', query: 'needle', options: new SearchOptions()), 'an empty line has no offsets');
        // An invalid user regex makes preg_match_all fail → no offsets (no crash).
        $this->assertSame([], $search->matchOffsets('needle', query: '(', options: new SearchOptions(regex: true)), 'a malformed regex yields no offsets');
        // A pattern that also matches empty (`a*`) skips its zero-width hits and
        // keeps only the real one (the leading 'a' at offset 0).
        $this->assertSame([0], $search->matchOffsets('abc', query: 'a*', options: new SearchOptions(regex: true)), 'zero-width matches are skipped');
    }

    public function testMultilineScanSkipsFilesThatBlowThePcreBacktrackLimit(): void
    {
        // A catastrophic-backtracking regex that is valid against '' but exceeds
        // the PCRE backtrack limit on the file's long run: preg_match_all returns
        // false for that file, which the whole-file scan skips (no results).
        $project = new TempProject(['src/A.php' => \str_repeat('a', times: 30)."!\nz\n"]);
        $search = new ProjectSearch($project->dir);
        $results = $this->awaitSearch($search, query: "(a+)+\nz", options: new SearchOptions(regex: true));

        $this->assertSame([], $results);
        // And it SAYS so. This used to assert the empty list alone, which is
        // the same answer PCRE gives for "nothing here" — a project search
        // quietly returning fewer results is worse than the find bar doing it,
        // because a handful of skipped files among thousands is invisible.
        $this->assertTrue($search->gaveUp(), 'the engine gave up and the search admits it');

        // Skipped, that file — not the rest of the scan: a file after it still answers.
        $project->write('src/B.php', content: "a\nz\n");
        $after = $this->awaitSearch($search, query: "(a+)+\nz", options: new SearchOptions(regex: true), scope: ['src/A.php', 'src/B.php']);
        $this->assertSame(['src/B.php'], \array_column($after, 'path'));
    }

    /** The PHP walk passes over a directory nobody may list, as grep does, and finds the rest. */
    public function testThePhpWalkPassesOverAnUnreadableDirectory(): void
    {
        \putenv('PATH=/nonexistent');
        $project = $this->fixtures();
        if (!$project->lock('docker/db')) {
            $this->markTestSkipped('this user reads every directory');
        }

        $paths = \array_column($this->awaitSearch(new ProjectSearch($project->dir), query: 'needle'), 'path');
        \sort($paths);

        $this->assertSame(['src/A.php', 'src/B.php'], $paths);
    }

    /**
     * Ignoring case, É is é — to grep, whatever locale Porta itself runs in,
     * and to the pattern that highlights and replaces what grep listed.
     */
    public function testIgnoringCaseGrepFoldsEveryLetterAsThePatternDoes(): void
    {
        $locale = \getenv('LC_ALL');
        \putenv('LC_ALL=C');
        try {
            $project = new TempProject(['a.txt' => "café\n", 'b.txt' => "CAFE\n"]);
            $results = $this->awaitSearch(new ProjectSearch($project->dir), query: 'cafÉ', options: new SearchOptions(caseSensitive: false));
        } finally {
            \putenv(false === $locale ? 'LC_ALL' : 'LC_ALL='.$locale);
        }

        $this->assertSame([['path' => 'a.txt', 'line' => 0, 'text' => 'café', 'ranges' => [['start' => 0, 'length' => 4]]]], $results);
    }

    /** The PHP walk folds every letter too, and still searches a file that is not UTF-8. */
    public function testThePhpWalkFoldsEveryLetterAndSearchesALatin1File(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.txt' => "café\n", 'latin1.txt' => "caf\xe9 au CAFE\n"]);

        $this->assertSame(['a.txt'], \array_column($this->awaitSearch(new ProjectSearch($project->dir), query: 'CAFÉ', options: new SearchOptions(caseSensitive: false)), 'path'));
        $this->assertSame(['latin1.txt'], \array_column($this->awaitSearch(new ProjectSearch($project->dir), query: 'cafe', options: new SearchOptions(caseSensitive: false)), 'path'));
    }

    /** The PHP walk shows a hit as grep's results are shown: without its indentation. */
    public function testThePhpWalkShowsAHitTrimmed(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n    \$needle = 1;  \n"]);

        $results = $this->awaitSearch(new ProjectSearch($project->dir), query: 'needle');

        $this->assertSame(['$needle = 1;'], \array_column($results, 'text'));
    }

    /**
     * An ordinary search does not claim the engine gave up — including one that
     * FOLLOWS a search that did.
     *
     * One controller holds one ProjectSearch for the session, so the reuse case
     * is the only one that happens. A flag set on a bad pattern and never
     * cleared would warn about every search afterwards, which is the same lie
     * in the other direction.
     */
    public function testTheGiveUpFlagDoesNotOutliveTheSearchThatSetIt(): void
    {
        // The a-run followed by `z` is what drives the pattern into the
        // expensive scan; `needle` is there for the ordinary search after it.
        $project = new TempProject(['src/A.php' => \str_repeat('a', times: 30)."!\nz\nneedle\n"]);
        $search = new ProjectSearch($project->dir);

        $this->awaitSearch($search, query: "(a+)+\nz", options: new SearchOptions(regex: true));
        $this->assertTrue($search->gaveUp(), 'the first search gave up');

        $this->awaitSearch($search, query: 'needle');
        $this->assertFalse($search->gaveUp(), 'and the next one does not inherit it');
    }

    public function testMultilineQueryMatchesAcrossLinesWithAPreview(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\nfunction foo()\n{\n    return 1;\n}\n",
            'src/B.php' => "<?php\nfunction foo() { return 2; }\n", // one line → no cross-line match
        ]);
        $results = $this->awaitSearch(new ProjectSearch($project->dir), query: "foo()\n{");

        $this->assertCount(1, $results, 'only the file whose foo() spans lines matches');
        $this->assertSame('src/A.php', $results[0]['path']);
        $this->assertSame(1, $results[0]['line'], '0-based start line of the match');
        $this->assertSame('foo() ⏎…', $results[0]['text'], 'the preview shows the first line + a continuation marker');
        $this->assertSame([['start' => 0, 'length' => 5]], $results[0]['ranges'], 'the first-line portion is highlighted');
    }

    /** A typed line break is the file's own: a CRLF file's `\r\n` matches it too. */
    public function testMultilineQueryMatchesACrlfFile(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\r\nfunction foo()\r\n{\r\n    return 1;\r\n}\r\n"]);
        $results = $this->awaitSearch(new ProjectSearch($project->dir), query: "foo()\n{");

        $this->assertCount(1, $results);
        $this->assertSame(1, $results[0]['line']);
        $this->assertSame('foo() ⏎…', $results[0]['text'], 'no stray CR in the preview');
    }

    /** A multi-line replacement goes into a CRLF file with CRLF breaks, not mixed endings. */
    public function testAMultilineReplacementTakesTheFilesLineEnding(): void
    {
        $search = new ProjectSearch('/p');

        $this->assertSame("a\r\nX\r\nY\r\nc\r\n", $search->applyReplacement("a\r\nb\r\nc\r\n", query: "\nb\n", replacement: "\nX\nY\n", options: new SearchOptions()));
        $this->assertSame("a\nX\nY\nc\n", $search->applyReplacement("a\nb\nc\n", query: "\nb\n", replacement: "\nX\nY\n", options: new SearchOptions()), 'an LF file stays LF');
        $this->assertSame("a\rX\rY\rc\r", $search->applyReplacement("a\rb\rc\r", query: "\rb\r", replacement: "\nX\nY\n", options: new SearchOptions()), 'and a CR file, CR');
    }

    public function testMultilineScanDeduplicatesSkipsBinaryAndCaps(): void
    {
        $project = new TempProject([
            'src/A.php' => "x\nfoo\nbar\ny\n",
            'src/bin.dat' => "foo\nbar\n\0\n", // contains a NUL → skipped as binary
        ]);
        // Overlapping scopes (the file and its directory) must still match A.php once.
        $results = $this->awaitSearch(new ProjectSearch($project->dir), query: "foo\nbar", scope: ['src/A.php', 'src']);
        $paths = \array_column($results, 'path');
        $this->assertSame(['src/A.php'], $paths, 'deduplicated across overlapping scopes; the binary file is skipped');

        // The result cap applies to the whole-file scan too.
        $many = new TempProject(['big.txt' => \str_repeat("A\nB\n", ProjectSearch::MAX_RESULTS + 5)]);
        $capped = $this->awaitSearch(new ProjectSearch($many->dir), query: "A\nB");
        $this->assertCount(ProjectSearch::MAX_RESULTS, $capped);
    }

    public function testEmptyScopeListMatchesNothing(): void
    {
        // An empty scope (e.g. Open Files with nothing open) short-circuits to no results.
        $project = new TempProject(['src/A.php' => "<?php\n// needle\n"]);
        $this->assertSame([], $this->awaitSearch(new ProjectSearch($project->dir), query: 'needle', scope: []));
    }

    public function testScopeListOfExplicitFilesAndDirsDeduplicates(): void
    {
        \putenv('PATH=/nonexistent'); // force the PHP walk so scopeFiles is exercised
        $project = new TempProject([
            'src/A.php' => "<?php\n// needle in A\n",
            'src/B.php' => "<?php\n// needle in B\n",
            'other/C.php' => "<?php\n// needle in C\n",
        ]);
        // 'src/A.php' (a file) overlaps 'src' (a dir); A.php is matched once, C.php stays out of scope.
        $paths = \array_column($this->awaitSearch(new ProjectSearch($project->dir), query: 'needle', scope: ['src/A.php', 'src']), 'path');
        \sort($paths);
        $this->assertSame(['src/A.php', 'src/B.php'], $paths);
    }

    public function testPhpFallbackWhenGrepUnavailable(): void
    {
        $project = $this->fixtures();
        // Also exercise the binary-file and oversized-file skips
        $project->write('src/bin.dat', content: "nee\0dle");
        $project->write('src/huge.txt', \str_repeat('needle filler ', times: 80_000)); // > 1 MB, skipped
        \putenv('PATH=/nonexistent');

        $results = $this->awaitSearch(new ProjectSearch($project->dir), query: 'needle');

        $paths = \array_column($results, 'path');
        \sort($paths);
        $this->assertSame(['src/A.php', 'src/B.php'], $paths);
    }

    /** @return list<array{start: int, length: int}> the ranges of the sole result */
    private function rangesOf(SearchBackend $backend, string $content, string $query, SearchOptions $options = new SearchOptions()): array
    {
        $project = new TempProject(['a.txt' => $content."\n"]);
        if (SearchBackend::PhpFallback === $backend) {
            \putenv('PATH=/nonexistent');
        }
        $results = $this->awaitSearch(new ProjectSearch($project->dir), $query, options: $options);
        $this->assertCount(1, $results);

        return $results[0]['ranges'];
    }

    public function testGrepMatchRangesAreDisplayColumns(): void
    {
        // "the needle here" → 'needle' starts at column 4, length 6.
        $this->assertSame([['start' => 4, 'length' => 6]], $this->rangesOf(SearchBackend::Grep, content: 'the needle here', query: 'needle'));
        // Regex matches map the same way.
        $this->assertSame([['start' => 4, 'length' => 6]], $this->rangesOf(SearchBackend::Grep, content: 'the needle here', query: 'ne+dle', options: new SearchOptions(regex: true)));
        // A multibyte prefix ("café " is 6 bytes but 5 display columns) → start 5.
        $this->assertSame([['start' => 5, 'length' => 6]], $this->rangesOf(SearchBackend::Grep, content: 'café needle x', query: 'needle'));
        // The same word written e + U+0301 is still five columns as the panel
        // draws it; summing code points put the highlight one column late.
        $this->assertSame([['start' => 5, 'length' => 6]], $this->rangesOf(SearchBackend::Grep, content: "cafe\u{0301} needle x", query: 'needle'));
    }

    public function testPhpFallbackMatchRangesMatchGrep(): void
    {
        $this->assertSame([['start' => 4, 'length' => 6]], $this->rangesOf(SearchBackend::PhpFallback, content: 'the needle here', query: 'needle'));
        $this->assertSame([['start' => 5, 'length' => 6]], $this->rangesOf(SearchBackend::PhpFallback, content: 'café needle x', query: 'needle'));
        $this->assertSame([['start' => 5, 'length' => 6]], $this->rangesOf(SearchBackend::PhpFallback, content: "cafe\u{0301} needle x", query: 'needle'));
    }

    /** @return list<string> sorted result paths for a mask over a fixed fixture */
    private function maskedPaths(SearchBackend $backend, string $mask): array
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// needle\n",
            'src/B.js' => "// needle\n",
            'src/C.twig' => "{# needle #}\n",
        ]);
        if (SearchBackend::PhpFallback === $backend) {
            \putenv('PATH=/nonexistent');
        }
        $paths = \array_column($this->awaitSearch(new ProjectSearch($project->dir), query: 'needle', mask: $mask), 'path');
        \sort($paths);

        return $paths;
    }

    public function testFileMaskRestrictsMatchedFilesWithGrep(): void
    {
        $this->assertSame(['src/A.php'], $this->maskedPaths(SearchBackend::Grep, mask: '*.php'));
        $this->assertSame(['src/A.php', 'src/B.js'], $this->maskedPaths(SearchBackend::Grep, mask: '*.{php,js}'), 'brace mask');
        $this->assertSame(['src/A.php', 'src/C.twig'], $this->maskedPaths(SearchBackend::Grep, mask: '*.php, *.twig'), 'comma mask');
    }

    public function testFileMaskRestrictsMatchedFilesWithPhpFallback(): void
    {
        $this->assertSame(['src/A.php'], $this->maskedPaths(SearchBackend::PhpFallback, mask: '*.php'));
        $this->assertSame(['src/A.php', 'src/B.js'], $this->maskedPaths(SearchBackend::PhpFallback, mask: '*.{php,js}'), 'brace mask via Finder');
    }

    /**
     * A scope entry that names one FILE rather than a directory, under a mask.
     *
     * @return list<string> the result paths
     */
    private function fileScopePaths(SearchBackend $backend, string $scope, string $mask): array
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// needle\n",
            'src/B.js' => "// needle\n",
        ]);
        if (SearchBackend::PhpFallback === $backend) {
            \putenv('PATH=/nonexistent');
        }

        return \array_column($this->awaitSearch(new ProjectSearch($project->dir), query: 'needle', scope: $scope, mask: $mask), 'path');
    }

    /**
     * The two engines used to disagree here: grep's `--include` filters every
     * candidate including one named on the command line, while the fallback
     * yielded a named file whatever the mask said. Same fixture, same scope,
     * same mask, both engines — asserted together so the next divergence fails
     * rather than being discovered by a user whose PATH has no grep on it.
     */
    public function testAMaskNarrowsAnExplicitFileScopeInBothEngines(): void
    {
        foreach ([SearchBackend::Grep, SearchBackend::PhpFallback] as $backend) {
            $name = $backend->name;
            $this->assertSame(['src/A.php'], $this->fileScopePaths($backend, scope: 'src/A.php', mask: '*.php'), $name.': the mask admits the named file');
            $this->assertSame([], $this->fileScopePaths($backend, scope: 'src/B.js', mask: '*.php'), $name.': the mask excludes the named file');
            $this->assertSame(['src/B.js'], $this->fileScopePaths($backend, scope: 'src/B.js', mask: ''), $name.': no mask, so the named file is searched');
            $this->assertSame(['src/B.js'], $this->fileScopePaths($backend, scope: 'src/B.js', mask: '*.{php,js}'), $name.': a later glob in the mask admits it');
        }
    }

    public function testEmptyQueryReturnsNothing(): void
    {
        $project = $this->fixtures();
        $results = $this->awaitSearch(new ProjectSearch($project->dir), query: '   ');

        $this->assertSame([], $results);
    }

    public function testRegexAndOptionsWithGrep(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n\$needle = 1;\n\$NEEDLES = 2;\nneedless();\n",
        ]);
        $search = new ProjectSearch($project->dir);

        // Regex.
        $this->assertNotSame([], $this->awaitSearch($search, query: 'ne+dle', options: new SearchOptions(regex: true)));
        // Case-sensitive: NEEDLES does not match lowercase "needle".
        $lines = \array_column($this->awaitSearch($search, query: 'needle', options: new SearchOptions(caseSensitive: true)), 'line');
        $this->assertNotContains(2, $lines, 'case-sensitive skips $NEEDLES');
        // Whole-word: "needle" must not match inside "needless".
        $wordLines = \array_column($this->awaitSearch($search, query: 'needle', options: new SearchOptions(wholeWord: true)), 'line');
        $this->assertNotContains(3, $wordLines, 'whole-word skips needless()');
        $this->assertContains(1, $wordLines);
    }

    public function testRegexAndOptionsWithPhpFallback(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n\$needle = 1;\n\$NEEDLES = 2;\nneedless();\n",
        ]);
        \putenv('PATH=/nonexistent');
        $search = new ProjectSearch($project->dir);

        $this->assertNotSame([], $this->awaitSearch($search, query: 'ne+dle', options: new SearchOptions(regex: true)));
        $this->assertNotContains(2, \array_column($this->awaitSearch($search, query: 'needle', options: new SearchOptions(caseSensitive: true)), 'line'));
        $wordLines = \array_column($this->awaitSearch($search, query: 'needle', options: new SearchOptions(wholeWord: true)), 'line');
        $this->assertNotContains(3, $wordLines);
        $this->assertContains(1, $wordLines);
    }

    public function testInvalidRegexYieldsNoResults(): void
    {
        $project = $this->fixtures();
        // Unbalanced group is not a valid pattern.
        $this->assertSame([], $this->awaitSearch(new ProjectSearch($project->dir), query: 'needle(', options: new SearchOptions(regex: true)));
    }

    public function testApplyReplacement(): void
    {
        $search = new ProjectSearch('/tmp');

        // Fixed, literal replacement: $ and \ in the replacement stay literal.
        $this->assertSame(
            'take $5.00',
            $search->applyReplacement('take 5 bucks', query: '5 bucks', replacement: '$5.00', options: new SearchOptions()),
        );
        // Case-insensitive fixed: both casings are replaced.
        $this->assertSame('x x', $search->applyReplacement('ab AB', query: 'ab', replacement: 'x', options: new SearchOptions()));
        // Whole-word fixed: the standalone X is replaced, "Xy" is not.
        $this->assertSame('a Y b Xy', $search->applyReplacement('a X b Xy', query: 'X', replacement: 'Y', options: new SearchOptions(caseSensitive: true, wholeWord: true)));
        // Regex with a backreference.
        $this->assertSame('b=a', $search->applyReplacement('a=b', query: '(\w)=(\w)', replacement: '$2=$1', options: new SearchOptions(regex: true, caseSensitive: true)));
        // Bad regex leaves the content untouched.
        $this->assertSame('a=b', $search->applyReplacement('a=b', query: '(', replacement: 'X', options: new SearchOptions(regex: true)));
    }

    public function testApplyReplacementPreservesCase(): void
    {
        $search = new ProjectSearch('/tmp');
        $preserve = new SearchOptions(preserveCase: true);

        // Each occurrence's casing is mapped onto the replacement, overriding the
        // typed casing: lowercase→lower, Title→Title, UPPER→UPPER.
        $this->assertSame(
            'newname Newname NEWNAME',
            $search->applyReplacement('oldname Oldname OLDNAME', query: 'oldname', replacement: 'newName', options: $preserve),
        );
        // A mixed-case match keeps the replacement verbatim.
        $this->assertSame('newName', $search->applyReplacement('oLdNaMe', query: 'oldname', replacement: 'newName', options: $preserve));
        // An empty replacement deletes the match (nothing to recase).
        $this->assertSame('', $search->applyReplacement('oldname', query: 'oldname', replacement: '', options: $preserve));
        // Multibyte title-case (case-sensitive match; PCRE `i` is not UTF-8-aware here).
        $this->assertSame('Éclair', $search->applyReplacement('Ötür', query: 'Ötür', replacement: 'éclair', options: new SearchOptions(preserveCase: true, caseSensitive: true)));
        // Preserve-case is inert in regex mode (falls back to the plain replacement).
        $this->assertSame('newName', $search->applyReplacement('OLDNAME', query: 'oldname', replacement: 'newName', options: new SearchOptions(regex: true, preserveCase: true)));
    }

    public function testResultCapWithGrep(): void
    {
        $project = new TempProject([
            'big.txt' => \str_repeat("needle\n", ProjectSearch::MAX_RESULTS + 200),
        ]);

        $results = $this->awaitSearch(new ProjectSearch($project->dir), query: 'needle', timeout: 5.0);

        $this->assertCount(ProjectSearch::MAX_RESULTS, $results);
    }

    public function testResultCapWithPhpFallback(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            'big.txt' => \str_repeat("needle\n", ProjectSearch::MAX_RESULTS + 200),
        ]);

        $results = $this->awaitSearch(new ProjectSearch($project->dir), query: 'needle', timeout: 5.0);

        $this->assertCount(ProjectSearch::MAX_RESULTS, $results);
    }

    /**
     * A grep on PATH that cannot run answers like no grep at all: the built-in
     * search. macOS spawns the dead binary and learns from its exit code; Linux
     * refuses the spawn outright. Both used to differ, and macOS showed nothing.
     */
    public function testABrokenGrepBinaryFallsBackToTheBuiltInSearch(): void
    {
        $project = $this->fixtures();
        $noGrep = new TempProject([], 'porta-nogrep-');
        \putenv('PATH='.$noGrep->dir);
        $builtIn = $this->awaitSearch(new ProjectSearch($project->dir), query: 'needle');
        $this->assertNotSame([], $builtIn, 'the premise: the built-in search finds the needle');

        $tools = new TempProject([], 'porta-badgrep-');
        $badGrep = $tools->write('grep', content: "\x00\x01 not an executable format");
        \chmod($badGrep, permissions: 0o755);
        \putenv('PATH='.$tools->dir);

        $this->assertSame($builtIn, $this->awaitSearch(new ProjectSearch($project->dir), query: 'needle'));
        $regex = new SearchOptions(regex: true);
        $this->assertSame($builtIn, $this->awaitSearch(new ProjectSearch($project->dir), query: 'need+le', options: $regex), 'nor does asking it whether it reads PCRE');
    }

    /**
     * grep -r prints in the file system's order — ext4 hashes it, so on a CI
     * runner File7.php came before File0.php and the first files were not the
     * first rows. The results are listed by path, compared as bytes, then line.
     */
    public function testGrepResultsAreListedByPathThenLine(): void
    {
        $tools = new TempProject([], 'porta-hashgrep-');
        $tools->write('grep', content: "#!/bin/sh\nprintf '%s\\n' './src/b.php:3:tok' './src/a.php:9:tok' './src/a.php:2:tok' './src/9.php:1:tok' './src/10.php:1:tok'\n");
        \chmod($tools->path('grep'), permissions: 0o755);
        \putenv('PATH='.$tools->dir.':/bin');

        $project = $this->fixtures();
        $results = $this->awaitSearch(new ProjectSearch($project->dir), query: 'tok');

        $this->assertSame(
            ['src/10.php:0', 'src/9.php:0', 'src/a.php:1', 'src/a.php:8', 'src/b.php:2'],
            \array_map(static fn (array $r): string => $r['path'].':'.$r['line'], $results),
            '"10.php" before "9.php": the bytes, not their numbers',
        );
    }

    /**
     * grep picks the lines, so it reads a regex as the find bar does: PCRE
     * (`-P`) where this grep takes it — GNU's ERE reads `\d` as a `d` — asked
     * once; ERE where it does not, and on a server, whose grep nobody asked.
     */
    public function testARegexIsReadAsPcreWhereGrepCanReadIt(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// needle\n"]); // no history: grep is handed `.`
        $tools = new TempProject([], 'porta-pcregrep-');
        $log = $tools->path('argv.log');
        $grep = static function (int $probe) use ($tools, $log): void {
            // The probe answers $probe; a search says what it was asked, and finds nothing.
            // --null is asked too, once: it is taken, and not what this test counts.
            $tools->write(
                'grep',
                "#!/bin/sh\nif [ \"\$1\" = --null ] && [ \"\$2\" = -q ]; then exit 1; fi\nif [ \"\$1\" = -qP ]; then echo probe >> '{$log}'; exit {$probe}; fi\nprintf '%s\\n' \"\$*\" >> '{$log}'\nexit 1\n",
            );
            \chmod($tools->path('grep'), permissions: 0o755);
            \file_put_contents($log, data: '');
        };
        $asked = static fn (): array => \array_values(\array_filter(\explode("\n", (string) \file_get_contents($log))));
        $tail = static fn (string $line): string => \substr($line, (int) \strrpos($line, needle: ' -- ') - 2);
        $regex = new SearchOptions(regex: true, caseSensitive: true);
        \putenv('PATH='.$tools->dir.':/bin:/usr/bin'); // sh and env for the fake server's command; grep is the stub

        $grep(1);
        $search = new ProjectSearch($project->dir);
        $this->awaitSearch($search, query: '\d+', options: $regex);
        $this->awaitSearch($search, query: '\d+', options: $regex);
        $this->assertSame(['probe', '-P -- \d+ .', '-P -- \d+ .'], [$asked()[0] ?? null, ...\array_map($tail, \array_slice($asked(), offset: 1))], 'PCRE, and grep asked once');

        $grep(1);
        $this->awaitSearch(new ProjectSearch($project->dir), query: 'needle');
        $this->assertSame(['-F -- needle .'], \array_map($tail, $asked()), 'a literal asks nothing');

        $grep(2);
        $this->awaitSearch(new ProjectSearch($project->dir), query: '\d+', options: $regex);
        $this->assertSame(['probe'], $asked(), 'no -P, and a regex only PCRE reads: the built-in engine, not ERE');
        $grep(2);
        $this->awaitSearch(new ProjectSearch($project->dir), query: '[0-9]+', options: $regex);
        $this->assertSame(['probe', '-E -- [0-9]+ .'], [$asked()[0] ?? null, ...\array_map($tail, \array_slice($asked(), offset: 1))], 'no -P, and one ERE reads the same: ERE');

        $grep(1);
        $cache = new TempProject([]);
        $this->awaitSearch(new ProjectSearch($cache->dir, new FakeRemoteTransport($project->dir)->filesystem($cache->dir)), query: '\d+', options: $regex);
        $searched = \array_values(\array_filter($asked(), static fn (string $line): bool => 'probe' !== $line));
        $this->assertSame(['-P -- \d+ .'], \array_map($tail, $searched), "the server's grep was asked about -P itself, said it takes it, and reads it as PCRE");

        // A remote project whose server is not reached yet is searched here,
        // by this machine's grep: -P is asked for, and taken.
        $grep(1);
        $unreached = new TempProject(['src/A.php' => "<?php\n// 42\n"]);
        $remote = new RemoteFilesystem(
            new ProjectLocation(new RemoteSpec('ssh', 'example.org', '/srv')),
            $unreached->dir,
            new readonly class implements RemoteTransportSource {
                public function remoteTransport(RemoteSpec $spec): ?RemoteTransport
                {
                    return null;
                }
            },
            new Notifier(new StatusBarWidget(), TestTui::create()),
            new Translator('en'),
            new BufferManager(),
            new FileIndex($unreached->dir),
            new EventDispatcher(),
        );
        $this->awaitSearch(new ProjectSearch($unreached->dir, $remote), query: '\d+', options: $regex);
        $this->assertSame(['probe', '-P -- \d+ .'], [$asked()[0] ?? null, ...\array_map($tail, \array_slice($asked(), offset: 1))], 'searched here: as PCRE');
    }

    /**
     * The PHP walk holds the loop a slice at a time — a large project used to
     * freeze the UI for the whole scan (12 s over this repository) — and a
     * new search or a cancel drops the rest of the old one.
     */
    public function testThePhpWalkAnswersBetweenFramesAndStopsWhenCancelled(): void
    {
        $scan = static function (): \Generator {
            for ($file = 0; $file < 10; ++$file) {
                \usleep(3000); // a file that takes 3 ms: ten of them outlast a slice
                yield;
            }

            return [['path' => 'a.php', 'line' => 0, 'text' => 'done', 'ranges' => []]];
        };
        $search = new ProjectSearch('/tmp');
        $results = null;
        $onDone = static function (array $r) use (&$results): void {
            $results = $r;
        };

        Probe::invoke($search, 'sliced', $scan(), $onDone);
        $this->assertNull($results, 'the scan yields the loop before it is through');
        $this->await(static function () use (&$results): bool {
            return null !== $results;
        }, what: 'the rest of the scan, on later turns');
        $this->assertSame('done', $results[0]['text'] ?? null);

        $results = null;
        Probe::invoke($search, 'sliced', $scan(), $onDone);
        $search->cancel();
        $this->pump(0.1);
        $this->assertNull($results, 'cancelled: the rest never runs');
    }

    /** A line grep printed that the pattern does not match is no hit: grep reads Unicode its own way (BSD grep -w takes `x²` for the word `x`). */
    public function testParseSkipsMalformedGrepLinesAndLinesThePatternDoesNotMatch(): void
    {
        $search = new ProjectSearch('/tmp');
        $results = Probe::invoke($search, 'parse', "src/A.php:3:a match here\ngarbage without line number\n\n./src/B.php:7:no hit\n./src/C.php:2:  \tmatch\n", '/^\s+match/');

        $this->assertSame(
            [
                ['path' => 'src/C.php', 'line' => 1, 'text' => 'match', 'ranges' => []],
            ],
            $results,
            'matched on the line as the file holds it, before trimming',
        );
        $this->assertSame(
            ['src/A.php'],
            \array_column(Probe::invoke($search, 'parse', "src/A.php:3:a match here\n./src/B.php:7:no hit\n", '/match/'), 'path'),
        );
    }

    public function testMatchRangesHandleEmptyLinesAndZeroWidthMatches(): void
    {
        $search = new ProjectSearch('/tmp');
        // An empty (whitespace-only, trimmed) line yields no ranges.
        $this->assertSame([], Probe::invoke($search, 'rangesFor', '', '/x/'));
        // A pattern that can match empty (`l*`) skips its zero-width matches but
        // keeps the real one ("ll" at column 2).
        $this->assertSame([['start' => 2, 'length' => 2]], Probe::invoke($search, 'rangesFor', 'hello', '/l*/'));
        // A malformed pattern makes preg_match_all fail → no ranges.
        $this->assertSame([], Probe::invoke($search, 'rangesFor', 'hello', '/(/'));
    }

    public function testCancelStopsInFlightSearch(): void
    {
        $project = $this->fixtures();
        $search = new ProjectSearch($project->dir);

        $called = false;
        $search->search('needle', static function () use (&$called): void {
            $called = true;
        });
        $search->cancel();

        // Give the (cancelled) watcher time to have fired if cancel failed
        $this->pump(0.3);

        $this->assertFalse($called);
    }

    public function testNewSearchCancelsPrevious(): void
    {
        $project = $this->fixtures();
        $search = new ProjectSearch($project->dir);

        $firstCompleted = false;
        $search->search('needle', static function () use (&$firstCompleted): void {
            $firstCompleted = true;
        });
        $results = $this->awaitSearch($search, query: 'needle');

        $this->assertFalse($firstCompleted, 'first search superseded');
        $this->assertNotEmpty($results);
    }
}

/** Which search backend a helper exercises: the grep path or the PHP Finder fallback. */
// @mago-expect lint:single-class-per-file -- test-local helper enum, kept beside the test that uses it
enum SearchBackend
{
    case Grep;
    case PhpFallback;
}
