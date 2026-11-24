<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Filesystem;

use PHPUnit\Framework\TestCase;
use Porta\Filesystem\RemoteEntry;
use Porta\Filesystem\RemoteFile;
use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteStamp;
use Porta\Filesystem\RemoteTransport;
use Porta\Tests\Support\FakeRemoteTransport;
use Porta\Tests\Support\NoOpTransportVerbs;
use Porta\Tests\Support\TempProject;

/**
 * What RemoteTransport gives a transport plugin by default, for the optional
 * walk: a transport written before paths could be skipped keeps walking, and
 * one that overrides either half is taken to walk.
 */
final class RemoteTransportTest extends TestCase
{
    /**
     * A transport written before paths could be skipped overrides walk() only:
     * it still walks, and what lies under a skipped path is dropped from its
     * answer — the server listed it, the tree never sees it.
     */
    public function testATransportThatWalksByNameOnlyStillSkipsAPath(): void
    {
        $byName = new class extends RemoteTransport {
            use NoOpTransportVerbs;

            public function walk(string $path, array $skip, \Closure $done): bool
            {
                $done(\array_map(static fn (string $name): RemoteEntry => new RemoteEntry($name, isDir: !\str_contains($name, needle: '.')), [
                    'lib',
                    'lib/Own.php',
                    'lib/deps',
                    'lib/deps/a.php',
                    'lib/depsx',
                    'src/lib/deps',
                ]));

                return true;
            }
        };
        $this->assertTrue($byName->walks());

        $answer = null;
        $this->assertTrue($byName->walkSkipping('', [], ['lib/deps'], static function (array|string $entries) use (&$answer): void {
            $answer = $entries;
        }));
        $this->assertIsArray($answer);
        $this->assertSame(
            ['lib', 'lib/Own.php', 'lib/depsx', 'src/lib/deps'],
            \array_map(static fn (RemoteEntry $entry): string => $entry->name, $answer),
            'the path and under it; not a name that starts the same, nor the same path elsewhere',
        );

        $failed = null;
        $failing = new class extends RemoteTransport {
            use NoOpTransportVerbs;

            public function walk(string $path, array $skip, \Closure $done): bool
            {
                $done('find: not found');

                return true;
            }
        };
        $failing->walkSkipping('', [], ['lib/deps'], static function (array|string $entries) use (&$failed): void {
            $failed = $entries;
        });
        $this->assertSame('find: not found', $failed, 'a failure passes through');
    }

    /** A transport that overrides only walkSkipping() walks too. */
    public function testATransportThatPrunesByPathOnlyStillWalks(): void
    {
        $pruning = new class extends RemoteTransport {
            use NoOpTransportVerbs;

            public function walkSkipping(string $path, array $skip, array $skipPaths, \Closure $done): bool
            {
                return true;
            }
        };

        $this->assertTrue($pruning->walks());
    }

    /** A transport that says it walks and then declines is listed in that same poll, not one round late. */
    public function testATransportThatDeclinesTheWalkIsListedInTheSamePoll(): void
    {
        $declining = new class extends RemoteTransport {
            use NoOpTransportVerbs;

            /** @var list<string> */
            public array $calls = [];

            public function list(string $path, \Closure $done): void
            {
                $this->calls[] = 'list '.$path;
                $done([]);
            }

            public function fetch(string $path, \Closure $done): void
            {
                $this->calls[] = 'fetch '.$path;
                $done(new RemoteFile('{}', new RemoteStamp(1, 2)));
            }

            public function walks(): bool
            {
                return true;
            }

            public function walkSkipping(string $path, array $skip, array $skipPaths, \Closure $done): bool
            {
                $this->calls[] = 'walk '.$path;

                return false;
            }
        };
        $cache = new TempProject();
        $files = FakeRemoteTransport::over($declining, new RemoteSpec('ssh', 'example.org', '/srv'), $cache->dir);
        $files->listed($cache->dir);
        $declining->calls = [];

        $files->poll();

        $this->assertSame(['fetch composer.json', 'walk ', 'list '], $declining->calls);
        $files->close();
    }
}
