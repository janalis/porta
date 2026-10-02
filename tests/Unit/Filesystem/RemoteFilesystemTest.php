<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Filesystem;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Event\StatusCellsChanged;
use Porta\Filesystem\ProjectLocation;
use Porta\Filesystem\RemoteFilesystem;
use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteStamp;
use Porta\Filesystem\RemoteTransport;
use Porta\Filesystem\RemoteTransportSource;
use Porta\I18n\Translator;
use Porta\Search\FileIndex;
use Porta\Tests\Support\FakeRemoteTransport;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\RecordingEvents;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTui;
use Porta\Ui\Glyphs;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Porta\Workbench\StatusTone;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A server's project through its cache: what is listed lands as placeholders,
 * what is opened is fetched, and what changes here reaches the server in the
 * order it was made — refused when the server's copy moved underneath.
 */
final class RemoteFilesystemTest extends TestCase
{
    use PumpsLoop;
    use StatusReads;

    private TempProject $server;
    private TempProject $cache;
    private FakeRemoteTransport $transport;
    private StatusBarWidget $status;
    private BufferManager $buffers;

    protected function setUp(): void
    {
        $this->server = new TempProject(['src/App.php' => "<?php // server\n", 'README.md' => "# hi\n", 'empty.txt' => '']);
        $this->cache = new TempProject();
        $this->transport = new FakeRemoteTransport($this->server->dir);
        $this->status = new StatusBarWidget();
        $this->buffers = new BufferManager();
    }

    private function files(?RemoteSpec $spec = new RemoteSpec('ssh', 'example.org', '/srv/app')): RemoteFilesystem
    {
        $transport = $this->transport;

        return new RemoteFilesystem(
            new ProjectLocation($spec),
            $this->cache->dir,
            new readonly class($transport) implements RemoteTransportSource {
                public function __construct(
                    private ?RemoteTransport $transport,
                ) {}

                public function remoteTransport(RemoteSpec $spec): ?RemoteTransport
                {
                    return $this->transport;
                }
            },
            new Notifier($this->status, TestTui::create()),
            new Translator('en'),
            $this->buffers,
            new FileIndex($this->cache->dir),
            new EventDispatcher(),
        );
    }

    private function filesWith(FileIndex $index): RemoteFilesystem
    {
        $transport = $this->transport;

        return new RemoteFilesystem(
            new ProjectLocation(new RemoteSpec('ssh', 'example.org', '/srv/app')),
            $this->cache->dir,
            new readonly class($transport) implements RemoteTransportSource {
                public function __construct(
                    private RemoteTransport $transport,
                ) {}

                public function remoteTransport(RemoteSpec $spec): RemoteTransport
                {
                    return $this->transport;
                }
            },
            new Notifier($this->status, TestTui::create()),
            new Translator('en'),
            $this->buffers,
            $index,
            new EventDispatcher(),
        );
    }

    private function noTransport(): RemoteFilesystem
    {
        return new RemoteFilesystem(
            new ProjectLocation(new RemoteSpec('ftp', 'example.org', '/')),
            $this->cache->dir,
            new readonly class implements RemoteTransportSource {
                public function remoteTransport(RemoteSpec $spec): ?RemoteTransport
                {
                    return null;
                }
            },
            new Notifier($this->status, TestTui::create()),
            new Translator('en'),
            $this->buffers,
            new FileIndex($this->cache->dir),
            new EventDispatcher(),
        );
    }

    public function testListingADirectoryLandsItsEntriesAsPlaceholders(): void
    {
        $files = $this->files();

        $this->assertFalse($files->listed($this->cache->dir), 'the first look starts a listing');
        $this->assertTrue($files->listed($this->cache->dir), 'and the second knows it is under way');

        $this->assertDirectoryExists($this->cache->path('src'));
        $this->assertSame('', \file_get_contents($this->cache->path('README.md')), 'a file lands empty until it is opened');
        $this->assertFileDoesNotExist($this->cache->path('src/App.php'), 'a directory below is listed only when shown');
        $this->assertSame(['list '], $this->transport->calls);
    }

    public function testOpeningAPlaceholderFetchesItThenAsksAgain(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $opened = 0;

        $this->assertFalse($files->available($this->cache->path('README.md'), static function () use (&$opened): void {
            ++$opened;
        }));

        $this->assertSame(1, $opened, 'the open is asked again once the content is here');
        $this->assertSame("# hi\n", \file_get_contents($this->cache->path('README.md')));
        $this->assertTrue($files->available($this->cache->path('README.md'), static fn (): null => null), 'and fetched once only');
        $this->assertStringContainsString('Fetching README.md', $this->flashText($this->status));
    }

    public function testAnEmptyFileOnTheServerIsAlreadyAllThereIs(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);

        $this->assertTrue($files->available($this->cache->path('empty.txt'), static fn (): null => null));
        $this->assertNotContains('fetch empty.txt', $this->transport->calls);
    }

    public function testWhatTheServerNeverNamedIsReadAsItIs(): void
    {
        $files = $this->files();
        $made = $this->cache->write('local.txt', 'mine');

        $this->assertTrue($files->available($made, static fn (): null => null));
        $this->assertTrue($files->available($this->cache->path('.porta/session.json'), static fn (): null => null), 'Porta\'s own state is never the server\'s');
        $this->assertTrue($files->available('/elsewhere/file.txt', static fn (): null => null));
        $this->assertTrue($files->listed('/elsewhere'));
        $this->assertSame([], $this->transport->calls);
    }

    public function testTheLatestAskForAFetchInFlightIsTheOneAnswered(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $this->transport->hold = true;
        $answered = [];

        $files->available($this->cache->path('README.md'), static function () use (&$answered): void {
            $answered[] = 'first';
        });
        $files->available($this->cache->path('README.md'), static function () use (&$answered): void {
            $answered[] = 'second';
        });
        $this->transport->release();

        $this->assertSame(['second'], $answered);
        $this->assertSame(['list ', 'fetch README.md'], $this->transport->calls, 'one fetch, not two');
    }

    public function testAFailedFetchSaysSoAndNeverOpens(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $this->transport->fail['README.md'] = 'permission denied';

        $files->available($this->cache->path('README.md'), function (): never {
            $this->fail('a failed fetch must not open the placeholder');
        });

        $this->assertSame('Cannot read README.md from the server: permission denied', $this->flashText($this->status));
        $this->assertFalse($files->available($this->cache->path('README.md'), static fn (): null => null), 'still a placeholder: the next open fetches again');
    }

    public function testAFailedListingIsAskedAgainNextTime(): void
    {
        $files = $this->files();
        $this->transport->fail[''] = 'no route to host';

        $files->listed($this->cache->dir);

        $this->assertSame('Cannot read / from the server: no route to host', $this->flashText($this->status));
        $this->assertFalse($files->listed($this->cache->dir), 'the root is listed again when the tree next shows it');
    }

    public function testASaveUploadsWhatWasFetched(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $files->available($this->cache->path('README.md'), static fn (): null => null);

        $files->dumpFile($this->cache->path('README.md'), "# changed\n");

        $this->assertSame("# changed\n", \file_get_contents($this->server->path('README.md')));
        $this->assertSame(0, $files->pending());
    }

    public function testTheStatusBarIsToldWhenTheCellsCountOrTroubleMoves(): void
    {
        $events = new RecordingEvents();
        $this->server->write('more.md', 'not fetched yet');
        $files = $this->transport->filesystem($this->cache->dir, $this->status, $events);
        $files->listed($this->cache->dir);
        $files->available($this->cache->path('README.md'), static fn (): null => null);
        $told = static fn (): int => \count($events->take(StatusCellsChanged::class));
        $told();

        $this->transport->hold = true;
        $files->dumpFile($this->cache->path('README.md'), 'one');
        $this->assertSame(1, $told(), 'on its way: the count went up');
        $this->transport->release();
        $this->assertSame(1, $told(), 'answered: the count went down, with nothing else on screen to repaint the cell');

        $this->transport->fail['*'] = 'disk full';
        $files->dumpFile($this->cache->path('README.md'), 'two');
        $this->assertSame(3, $told(), 'up, refused — the cell says why — and down');
        $files->dumpFile($this->cache->path('README.md'), 'three');
        $this->assertSame(3, $told(), 'up, accepted — the refusal goes — and down');
        $files->poll();
        $files->poll();
        $this->assertSame(0, $told(), 'a poll that finds nothing new tells nobody');

        $this->transport->fail['*'] = 'connection refused';
        $files->available($this->cache->path('more.md'), static fn (): null => null);
        $this->assertSame(1, $told(), 'a read refused: the cell says so');
        $cell = $files->statusCells()[0];
        $this->assertSame(StatusTone::Accent, $cell->tone);
        $this->assertStringEndsWith("\nconnection refused", (string) $cell->tooltip);
    }

    public function testSavesWhileAnUploadIsQueuedRideTheSameUpload(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $files->available($this->cache->path('README.md'), static fn (): null => null);
        $this->transport->hold = true;

        $files->mkdir($this->cache->path('docs')); // something ahead of it in the queue
        $files->dumpFile($this->cache->path('README.md'), 'one');
        $files->dumpFile($this->cache->path('README.md'), 'two');
        $files->dumpFile($this->cache->path('README.md'), 'three');
        $this->assertSame(2, $files->pending());

        $this->transport->release();
        $this->transport->release(); // the upload asked once the mkdir answered

        $this->assertSame('three', \file_get_contents($this->server->path('README.md')), 'the file as it was when its turn came');
        $this->assertCount(1, \array_filter($this->transport->calls, static fn (string $call): bool => 'put README.md' === $call));
        $this->assertSame(0, $files->pending());
    }

    public function testAnUploadOverAServerChangeIsRefusedThenTheNextSaveOverwrites(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $files->available($this->cache->path('README.md'), static fn (): null => null);
        $this->server->write('README.md', "# someone else, longer\n");

        $files->dumpFile($this->cache->path('README.md'), 'mine');

        $this->assertSame("# someone else, longer\n", \file_get_contents($this->server->path('README.md')), 'theirs is kept');
        $this->assertSame('README.md changed on the server — save again to overwrite', $this->flashText($this->status));

        $files->dumpFile($this->cache->path('README.md'), 'mine');

        $this->assertSame('mine', \file_get_contents($this->server->path('README.md')));
    }

    public function testAnUploadTheServerRefusesIsSaidAndKeptUntilItIsSavedAgain(): void
    {
        $files = $this->files();
        $this->transport->fail['new.txt'] = 'disk full';

        $files->dumpFile($this->cache->path('new.txt'), 'x');
        $files->dumpFile($this->cache->path('other.txt'), 'o');

        $this->assertSame('Cannot change new.txt on the server: disk full', $this->flashText($this->status));
        $this->assertSame('o', \file_get_contents($this->server->path('other.txt')), 'the queue moved on');
        $this->assertSame(1, $files->pending(), 'but what was refused is still not on the server');
        $this->assertSame('⇅ example.org ⇡1', $files->statusCells()[0]->text);
        $this->assertContains('list ', $this->transport->calls, 'the link was checked: up, so a refusal');

        $files->dumpFile($this->cache->path('new.txt'), 'x');

        $this->assertSame(0, $files->pending());
        $this->assertSame('x', \file_get_contents($this->server->path('new.txt')));
    }

    public function testAFileMadeHereIsSentWithoutAStampToCheck(): void
    {
        $files = $this->files();

        $files->dumpFile($this->cache->path('deep/new.txt'), 'new');

        $this->assertSame('new', \file_get_contents($this->server->path('deep/new.txt')));
    }

    /**
     * The server takes the same steps the cache took, in order: a file saved
     * and then renamed before its upload ran is uploaded under its old name
     * and then renamed — reading the file at its turn found nothing there,
     * and the rename after it failed on the server.
     */
    public function testAFileRenamedBeforeItsUploadRanStillArrives(): void
    {
        $files = $this->files();
        $this->transport->hold = true;
        $files->mkdir($this->cache->path('a'));
        $files->dumpFile($this->cache->path('b.txt'), 'x');
        $files->rename($this->cache->path('b.txt'), $this->cache->path('c.txt'));
        $files->dumpFile($this->cache->path('b.txt'), 'a new b');

        $this->transport->release();

        $this->assertSame(['mkdir a', 'put b.txt', 'rename b.txt c.txt', 'put b.txt'], $this->transport->calls);
        $this->assertSame('x', \file_get_contents($this->server->path('c.txt')));
        $this->assertSame('a new b', \file_get_contents($this->server->path('b.txt')), 'the save after the rename is a new file, not the old upload');
        $this->assertSame(0, $files->pending());
    }

    public function testAnUploadWhosePathWentStillSaysWhenItFails(): void
    {
        $files = $this->files();
        $this->transport->hold = true;
        $files->mkdir($this->cache->path('a'));
        $files->dumpFile($this->cache->path('b.txt'), 'x');
        $files->remove($this->cache->path('b.txt'));
        $this->transport->fail['b.txt'] = 'quota exceeded';

        $this->transport->release();

        $this->assertSame('Cannot change b.txt on the server: quota exceeded', $this->flashText($this->status));
        $this->assertSame(0, $files->pending());
    }

    /** A renamed placeholder is still a placeholder: its content was never here, at either name. */
    public function testARenamedPlaceholderIsFetchedUnderItsNewName(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $files->listed($this->cache->path('src'));

        $files->rename($this->cache->path('src'), $this->cache->path('lib'));

        $this->assertFalse($files->available($this->cache->path('lib/App.php'), static fn (): null => null));
        $this->assertSame("<?php // server\n", \file_get_contents($this->cache->path('lib/App.php')));
    }

    /** What an upload answers is what the next save is checked against — not the stamp it was first fetched at. */
    public function testSavesInARowEachReachTheServer(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $files->available($this->cache->path('README.md'), static fn (): null => null);

        $files->dumpFile($this->cache->path('README.md'), 'a longer first save');
        $files->dumpFile($this->cache->path('README.md'), 'second');

        $this->assertSame('second', \file_get_contents($this->server->path('README.md')));
        $this->assertStringNotContainsString('changed on the server', $this->flashText($this->status), 'no conflict with our own upload');
    }

    /** Buffers carry resolved paths; the cache may have been opened through a symlink. Both are the project. */
    public function testThePathsOfThisProjectAreItsOwnHoweverSpelled(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $resolved = (string) \realpath($this->cache->dir);
        $alias = $this->cache->alias();

        $this->assertFalse($files->available($resolved.'/README.md', static fn (): null => null), 'resolved: a placeholder');
        $this->assertTrue($files->available($this->cache->dir.'x/README.md', static fn (): null => null), 'a sibling sharing the prefix is not inside');
        $this->assertTrue($files->listed($alias.'/src'), 'a spelling it was not opened with is not inside either');
    }

    public function testAPathThatClimbsOutIsNeverSent(): void
    {
        $files = $this->files();
        $this->cache->write('a/.keep', '');

        $files->dumpFile($this->cache->path('a/../b.txt'), 'x');

        $this->assertSame([], $this->transport->calls);
    }

    /** A slow server gets no second round of listings while the first is still out. */
    public function testThePollWaitsForTheLastRoundToAnswer(): void
    {
        $this->server->write('a/x', '');
        $this->server->write('b/x', '');
        $files = $this->files();
        foreach (['', 'a', 'b'] as $dir) {
            $files->listed($this->cache->path($dir));
        }
        $this->transport->calls = [];
        $this->transport->hold = true;

        $files->poll();
        $files->poll();

        $this->assertSame(['list ', 'list a'], $this->transport->calls);
        $this->transport->release();
    }

    /** Two directories a round — each once, though two open files share one. */
    public function testAPollListsTwoDirectoriesEachOnce(): void
    {
        $this->server->write('a/x', '');
        $this->server->write('b/x', '');
        $files = $this->files();
        foreach (['', 'a', 'b'] as $dir) {
            $files->listed($this->cache->path($dir));
        }
        $this->buffers->open($this->cache->path('README.md'), '');
        $this->buffers->open($this->cache->path('empty.txt'), '');
        $this->transport->calls = [];

        $files->poll();

        $this->assertSame(['list ', 'list a'], $this->transport->calls);
    }

    public function testADirectoryIsNeverListedTwiceAtOnce(): void
    {
        $source = new class implements RemoteTransportSource {
            public ?RemoteTransport $transport = null;

            public function remoteTransport(RemoteSpec $spec): ?RemoteTransport
            {
                return $this->transport;
            }
        };
        $files = new RemoteFilesystem(
            new ProjectLocation(new RemoteSpec('ssh', 'example.org', '/srv/app')),
            $this->cache->dir,
            $source,
            new Notifier($this->status, TestTui::create()),
            new Translator('en'),
            $this->buffers,
            new FileIndex($this->cache->dir),
            new EventDispatcher(),
        );
        $files->listed($this->cache->dir); // before any plugin: wanted
        $source->transport = $this->transport;
        $this->transport->hold = true;

        $files->poll(); // lists what was wanted, then the listed ones in turn — the root again

        $this->assertSame(['list '], $this->transport->calls);
        $this->transport->release();
    }

    public function testPortasOwnFilesOnTheServerAreNotTheCaches(): void
    {
        $this->server->write('.porta.json', '{"theirs": true}');
        $this->server->write('.porta/session.json', '{}');
        $files = $this->files();

        $files->listed($this->cache->dir);

        $this->assertFileDoesNotExist($this->cache->path('.porta.json'));
        $this->assertFileDoesNotExist($this->cache->path('.porta/session.json'));
        $this->assertFileExists($this->cache->path('README.md'), 'and the rest of the listing still lands');
    }

    public function testASaveThenARemoveBeforeItsTurnLeavesNothing(): void
    {
        $files = $this->files();
        $this->transport->hold = true;
        $files->mkdir($this->cache->path('a'));
        $files->dumpFile($this->cache->path('a/b.txt'), 'x');
        $files->remove($this->cache->path('a'));

        $this->transport->release();

        $this->assertDirectoryDoesNotExist($this->server->path('a'));
        $this->assertSame(['mkdir a', 'put a/b.txt', 'rmdir a'], $this->transport->calls);
    }

    public function testAMadeDirectoryReachesTheServerAndIsKnownListed(): void
    {
        $files = $this->files();

        $files->mkdir($this->cache->path('docs'));

        $this->assertDirectoryExists($this->server->path('docs'));
        $this->assertTrue($files->listed($this->cache->path('docs')), 'nothing to list in a directory we made');
    }

    public function testARenameMovesTheFileOnTheServerAndKeepsWhatWasFetched(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $files->listed($this->cache->path('src'));
        $files->available($this->cache->path('src/App.php'), static fn (): null => null);

        $files->rename($this->cache->path('src'), $this->cache->path('lib'));

        $this->assertFileExists($this->server->path('lib/App.php'));
        $this->assertDirectoryDoesNotExist($this->server->path('src'));
        $this->assertTrue($files->available($this->cache->path('lib/App.php'), static fn (): null => null), 'its content came along: no fetch');
        $this->assertTrue($files->listed($this->cache->path('lib')), 'and so did its listing');
    }

    public function testARenameIntoPortasOwnStateRemovesItFromTheServer(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);

        $files->rename($this->cache->path('README.md'), $this->cache->path('.porta/README.md'));

        $this->assertFileDoesNotExist($this->server->path('README.md'));
        $this->assertContains('remove README.md', $this->transport->calls);
    }

    public function testARenameOutOfPortasOwnStateUploadsIt(): void
    {
        $files = $this->files();
        $this->cache->write('.porta/notes.md', 'notes');

        $files->rename($this->cache->path('.porta/notes.md'), $this->cache->path('notes.md'));

        $this->assertSame('notes', \file_get_contents($this->server->path('notes.md')));
    }

    public function testARenameEntirelyOutsideIsNotTheServersBusiness(): void
    {
        $files = $this->files();
        $outside = new TempProject(['a.txt' => 'a']);

        $files->rename($outside->path('a.txt'), $outside->path('b.txt'));

        $this->assertFileExists($outside->path('b.txt'));
        $this->assertSame([], $this->transport->calls);
    }

    public function testRemovingAFileAndADirectory(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);

        $files->remove($this->cache->path('README.md'));
        $files->remove($this->cache->path('src'));

        $this->assertFileDoesNotExist($this->server->path('README.md'));
        $this->assertDirectoryDoesNotExist($this->server->path('src'));
        $this->assertSame(['list ', 'remove README.md', 'rmdir src'], $this->transport->calls);
        $this->assertFileDoesNotExist($this->cache->path('README.md'), 'gone here too');
        $this->assertDirectoryDoesNotExist($this->cache->path('src'));
    }

    public function testCopyingAFetchedFileUploadsTheCopy(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $files->available($this->cache->path('README.md'), static fn (): null => null);

        $files->copy($this->cache->path('README.md'), $this->cache->path('COPY.md'));

        $this->assertSame("# hi\n", \file_get_contents($this->server->path('COPY.md')));
        $this->assertContains('put COPY.md', $this->transport->calls);
        $this->assertNotContains('fetch COPY.md', $this->transport->calls);
    }

    public function testCopyingAPlaceholderCopiesTheServersOriginal(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);

        $files->copy($this->cache->path('README.md'), $this->cache->path('COPY.md'));

        $this->assertSame("# hi\n", \file_get_contents($this->server->path('COPY.md')));
        $this->assertSame("# hi\n", \file_get_contents($this->cache->path('COPY.md')), 'the copy here has the content too');
        $this->assertTrue($files->available($this->cache->path('COPY.md'), static fn (): null => null));
    }

    public function testCopyingADirectoryMakesItAndCopiesWhatItHolds(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $files->listed($this->cache->path('src'));

        $files->copy($this->cache->path('src'), $this->cache->path('src2'));

        $this->assertSame("<?php // server\n", \file_get_contents($this->server->path('src2/App.php')));
        $this->assertContains('mkdir src2', $this->transport->calls);
    }

    public function testACopyWhoseOriginalCannotBeReadSaysSo(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $this->transport->fail['README.md'] = 'gone';

        $files->copy($this->cache->path('README.md'), $this->cache->path('COPY.md'));

        $this->assertSame('Cannot read COPY.md from the server: gone', $this->flashText($this->status));
        $this->assertSame(0, $files->pending());
    }

    public function testACopyThatCannotBeWrittenSaysSo(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $this->transport->fail['COPY.md'] = 'read-only';

        $files->copy($this->cache->path('README.md'), $this->cache->path('COPY.md'));

        $this->assertSame('Cannot change COPY.md on the server: read-only', $this->flashText($this->status));
        $this->assertSame(0, $files->pending(), 'and the queue moves on, with nothing of ours left unsent');
    }

    public function testAStructuralChangeThatFailsSaysSo(): void
    {
        $files = $this->files();
        $this->transport->fail['docs'] = 'denied';

        $files->mkdir($this->cache->path('docs'));

        $this->assertSame('Cannot change docs on the server: denied', $this->flashText($this->status));
    }

    public function testTheServersChangesReachTheCacheOnTheNextListing(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $files->available($this->cache->path('README.md'), static fn (): null => null);
        $this->server->write('README.md', "# edited there, longer\n");
        $this->server->write('NEW.md', 'new');
        \unlink($this->server->path('empty.txt'));
        new Filesystem()->remove($this->server->path('src'));
        $this->server->write('src', 'now a file');

        $files->poll();

        $this->assertSame("# edited there, longer\n", \file_get_contents($this->cache->path('README.md')), 'a fetched file is fetched again');
        $this->assertFileExists($this->cache->path('NEW.md'));
        $this->assertFileDoesNotExist($this->cache->path('empty.txt'), 'what went there goes here');
        $this->assertFileExists($this->cache->path('src'));
        $this->assertDirectoryDoesNotExist($this->cache->path('src'), 'a directory that became a file');
        $this->assertStringNotContainsString('empty.txt', (string) \file_get_contents($this->cache->path('.porta/remote-index.json')), 'and the index forgets it');
    }

    public function testADirectoryThatWasAFileHereBecomesOne(): void
    {
        $files = $this->files();
        $this->cache->write('src', 'stale');

        $files->listed($this->cache->dir);

        $this->assertDirectoryExists($this->cache->path('src'));
    }

    public function testTheListingLeavesAloneWhatHasAChangeOfOursOnItsWay(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $files->available($this->cache->path('README.md'), static fn (): null => null);
        $this->transport->hold = true;
        $files->dumpFile($this->cache->path('README.md'), 'mine, not sent yet');
        \unlink($this->server->path('README.md'));
        $files->poll();
        $this->transport->held = \array_reverse($this->transport->held); // the listing answers before the upload

        $this->transport->release();

        $this->assertSame('mine, not sent yet', \file_get_contents($this->cache->path('README.md')), 'not taken for something the server deleted');
    }

    public function testAFetchThatLandsAfterALocalChangeIsDropped(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $this->transport->hold = true;
        $files->available($this->cache->path('README.md'), function (): never {
            $this->fail('the server\'s copy is stale by the time it lands');
        });
        $files->dumpFile($this->cache->path('README.md'), 'mine');

        $this->transport->release();

        $this->assertSame('mine', \file_get_contents($this->cache->path('README.md')));
    }

    public function testThePollPrefersTheDirectoriesOfOpenFilesAndSkipsWhileAListingRuns(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $files->listed($this->cache->path('src'));
        $this->buffers->open($this->cache->path('src/App.php'), '');
        $this->buffers->open('/elsewhere/x.php', '');
        $this->transport->calls = [];
        $this->transport->hold = true;

        $files->poll();
        $files->poll(); // a listing is still out: nothing more is asked

        $this->assertSame(['list src', 'list '], $this->transport->calls);
        $this->transport->release();
    }

    public function testThePollWalksTheListedDirectoriesInTurn(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $dir) {
            $this->server->write($dir.'/x', '');
        }
        $files = $this->files();
        $files->listed($this->cache->dir);
        foreach (['a', 'b', 'c', 'd'] as $dir) {
            $files->listed($this->cache->path($dir));
        }
        $this->transport->calls = [];

        $files->poll();
        $files->poll();
        $files->poll();

        $this->assertSame(['list ', 'list a', 'list b', 'list c', 'list d', 'list '], $this->transport->calls);
    }

    public function testAPollWithNothingShownListsNothing(): void
    {
        $files = $this->files();

        $files->poll();

        $this->assertSame([], $this->transport->calls);
    }

    public function testWithNoPluginForTheSchemeItSaysSoOnceAndTheCacheIsAllThereIs(): void
    {
        $files = $this->noTransport();
        $this->cache->write('a.txt', 'kept');

        $this->assertFalse($files->listed($this->cache->dir));
        $this->assertSame('', $this->flashText($this->status), 'at boot no plugin has activated yet: not news');
        $files->dumpFile($this->cache->path('a.txt'), 'changed');
        $files->mkdir($this->cache->path('d'));
        $files->rename($this->cache->path('d'), $this->cache->path('e'));
        $files->remove($this->cache->path('e'));
        $files->copy($this->cache->path('a.txt'), $this->cache->path('b.txt'));
        $files->poll();

        $this->assertSame('changed', \file_get_contents($this->cache->path('b.txt')), 'the cache still changes');
        $this->assertSame(0, $files->pending());
    }

    public function testAPlaceholderCannotBeOpenedWithoutATransport(): void
    {
        new RemoteFilesystem(
            new ProjectLocation(new RemoteSpec('ssh', 'example.org', '/srv/app')),
            $this->cache->dir,
            new readonly class($this->transport) implements RemoteTransportSource {
                public function __construct(
                    private RemoteTransport $transport,
                ) {}

                public function remoteTransport(RemoteSpec $spec): RemoteTransport
                {
                    return $this->transport;
                }
            },
            new Notifier($this->status, TestTui::create()),
            new Translator('en'),
            $this->buffers,
            new FileIndex($this->cache->dir),
            new EventDispatcher(),
        )->listed($this->cache->dir);
        $files = $this->noTransport();

        $this->assertFalse($files->available($this->cache->path('README.md'), function (): never {
            $this->fail('nothing can fetch it');
        }));
        $this->assertSame('No enabled plugin opens ftp:// projects', $this->flashText($this->status));
        $this->status->flash('', 0.0);
        $files->available($this->cache->path('README.md'), static fn (): null => null);
        $this->assertSame('', $this->flashText($this->status), 'said once');
    }

    /**
     * The tree is first built before plugins activate, so its first ask
     * finds no transport. The directory is remembered, and listed by the
     * first poll once a plugin answers — Ide runs one as soon as the loop
     * starts.
     */
    public function testADirectoryAskedForBeforeAnyPluginIsListedByTheFirstPoll(): void
    {
        $source = new class implements RemoteTransportSource {
            public ?RemoteTransport $transport = null;

            public function remoteTransport(RemoteSpec $spec): ?RemoteTransport
            {
                return $this->transport;
            }
        };
        $files = new RemoteFilesystem(
            new ProjectLocation(new RemoteSpec('ssh', 'example.org', '/srv/app')),
            $this->cache->dir,
            $source,
            new Notifier($this->status, TestTui::create()),
            new Translator('en'),
            $this->buffers,
            new FileIndex($this->cache->dir),
            new EventDispatcher(),
        );

        $this->assertFalse($files->listed($this->cache->dir));
        $files->poll();
        $this->assertFileDoesNotExist($this->cache->path('README.md'), 'still no plugin');

        $source->transport = $this->transport;
        $files->poll();

        $this->assertFileExists($this->cache->path('README.md'));
        $this->assertTrue($files->listed($this->cache->dir));
    }

    public function testTheIndexOutlivesTheSession(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $files->available($this->cache->path('README.md'), static fn (): null => null);

        $again = $this->files();

        $this->assertTrue($again->available($this->cache->path('README.md'), static fn (): null => null), 'fetched last session');
        $again->listed($this->cache->dir);
        $this->assertNotContains('fetch README.md', \array_slice($this->transport->calls, offset: 2), 'and not fetched again when listed again');
        $this->assertTrue($again->available($this->cache->path('README.md'), static fn (): null => null), 'still fetched after the listing');
    }

    public function testALocalProjectIsNeverTheServers(): void
    {
        $files = $this->files(spec: null);

        $files->dumpFile($this->cache->path('a.txt'), 'x');
        $files->poll();

        $this->assertTrue($files->listed($this->cache->dir));
        $this->assertSame([], $this->transport->calls);
    }

    /**
     * A saved buffer looks saved; the status bar is the one place an upload
     * still on its way shows — and the last thing the server refused, until
     * it next says yes.
     */
    public function testTheStatusBarSaysWhereTheProjectIsAndWhatIsStillOnItsWay(): void
    {
        $files = $this->files();

        [$cell] = $files->statusCells();
        $this->assertSame(Glyphs::REMOTE.' example.org', $cell->text);
        $this->assertSame(StatusTone::Subtle, $cell->tone);
        $this->assertStringContainsString('This project is on example.org', (string) $cell->tooltip);

        $this->transport->hold = true;
        $files->dumpFile($this->cache->path('a.txt'), 'a');
        $this->assertSame('1 change on its way to example.org', $files->statusCells()[0]->tooltip);
        $files->dumpFile($this->cache->path('b.txt'), 'b');
        [$cell] = $files->statusCells();
        $this->assertSame(Glyphs::REMOTE.' example.org '.Glyphs::UPLOADING.'2', $cell->text);
        $this->assertSame(StatusTone::Active, $cell->tone);
        $this->assertSame('2 changes on their way to example.org', $cell->tooltip);
        $this->transport->release();
    }

    /** The last refusal stays visible until the server next says yes — to a listing, a fetch or an upload. */
    public function testARefusedChangeStaysOnTheCellUntilItLands(): void
    {
        $files = $this->files();
        $this->transport->fail['a.txt'] = 'denied';
        $files->dumpFile($this->cache->path('a.txt'), 'x');
        $refused = "This project is on example.org: a file is fetched when you open it, and sent back when you save it\n1 change the server refused is only here — save it again to retry\ndenied";
        $this->assertSame(StatusTone::Accent, $files->statusCells()[0]->tone);
        $this->assertSame($refused, $files->statusCells()[0]->tooltip);

        $files->listed($this->cache->dir);
        $files->available($this->cache->path('README.md'), static fn (): null => null);
        $files->dumpFile($this->cache->path('d.txt'), 'd');
        $this->assertSame(StatusTone::Accent, $files->statusCells()[0]->tone, 'whatever else the server says yes to, that change is still not there');

        $files->dumpFile($this->cache->path('a.txt'), 'x');
        $this->assertSame(StatusTone::Subtle, $files->statusCells()[0]->tone, 'until it is');
    }

    public function testAReadTheServerRefusesStaysOnTheCellUntilTheServerNextAnswersYes(): void
    {
        $files = $this->files();
        $this->transport->fail['README.md'] = 'denied';
        $files->listed($this->cache->dir);
        $files->available($this->cache->path('README.md'), static fn (): null => null);
        $this->assertSame(StatusTone::Accent, $files->statusCells()[0]->tone);
        $this->assertStringEndsWith("\ndenied", (string) $files->statusCells()[0]->tooltip);

        $files->listed($this->cache->path('src'));

        $this->assertSame(StatusTone::Subtle, $files->statusCells()[0]->tone, 'a listing is a yes');
    }

    /**
     * The poll's first round asks for the whole tree, so go-to-file finds a
     * file in a directory nobody expanded — landed as placeholders a chunk
     * per loop turn, then handed to the index go-to-file reads.
     */
    public function testTheFirstPollWalksTheWholeTreeIntoTheCache(): void
    {
        for ($i = 0; $i < 600; ++$i) {
            $this->server->write('deep/many/f'.$i.'.php', '');
        }
        $this->server->write('deep/one/Needle.php', '<?php');
        $this->server->write('vendor/lib/Skipped.php', '<?php');
        $this->server->write('.porta/state.json', '{}');
        $this->server->write('.porta.json', '{"theirs": true}');
        $this->transport->walks = true;
        $index = new FileIndex($this->cache->dir);
        $files = $this->filesWith($index);
        $this->assertSame([], $index->search('Needle', 5), 'nothing here yet — and the index has looked');

        $files->poll();
        $this->assertFileDoesNotExist($this->cache->path('deep/one/Needle.php'), 'the last of 600+ lands on a later turn');
        $this->await(fn (): bool => \is_file($this->cache->path('deep/one/Needle.php')));

        $this->assertSame('', \file_get_contents($this->cache->path('deep/one/Needle.php')), 'a placeholder');
        $this->assertFalse($files->available($this->cache->path('deep/one/Needle.php'), static fn (): null => null), 'fetched when opened');
        $this->assertSame(['deep/one/Needle.php'], $index->search('Needle', 5), 'and go-to-file sees it');
        $this->assertDirectoryDoesNotExist($this->cache->path('vendor'), 'what go-to-file leaves out is not walked');
        $this->assertFileDoesNotExist($this->cache->path('.porta/state.json'));
        $this->assertFileDoesNotExist($this->cache->path('.porta.json'), 'the server\'s settings are not this copy\'s');
        $this->assertFileExists($this->cache->path('README.md'), 'and what comes after it in the walk still lands');
        $this->assertSame(['walk '], \array_values(\array_filter($this->transport->calls, static fn (string $call): bool => \str_starts_with($call, 'walk'))));

        $this->transport->calls = [];
        $files->poll();
        $this->assertNotContains('walk ', $this->transport->calls, 'once a session; listings after that');
    }

    public function testAWalkLeavesAloneWhatHasAChangeOfOursOnItsWay(): void
    {
        $this->transport->walks = true;
        $files = $this->files();
        $this->transport->hold = true;
        $files->dumpFile($this->cache->path('README.md'), 'mine');
        $files->poll();
        $this->transport->held = \array_reverse($this->transport->held);

        $this->transport->release();
        $this->pump(0.05);

        $this->assertSame('mine', \file_get_contents($this->cache->path('README.md')));
    }

    public function testAWalkThatFailsSaysSo(): void
    {
        $this->transport->walks = true;
        $this->transport->fail[''] = 'find: not found';
        $files = $this->files();

        $files->poll();

        $this->assertSame('Cannot read / from the server: find: not found', $this->flashText($this->status));
    }

    public function testATransportThatCannotWalkIsLeftToItsListings(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $this->transport->calls = [];

        $files->poll();

        $this->assertSame(['list '], $this->transport->calls);
        $bare = new class extends RemoteTransport {
            public function list(string $path, \Closure $done): void {}

            public function fetch(string $path, \Closure $done): void {}

            public function put(string $path, string $bytes, ?RemoteStamp $expected, \Closure $done): void {}

            public function mkdir(string $path, \Closure $done): void {}

            public function rename(string $from, string $to, \Closure $done): void {}

            public function remove(string $path, bool $isDir, \Closure $done): void {}

            public function close(): void {}
        };
        $this->assertFalse($bare->walk('', [], static function (): void {}), 'walking is optional, and off unless a transport says');
        $this->assertNull($bare->remoteCommand(['grep']), 'so is running a command there');
    }

    /**
     * A listing the server refused is asked again half a minute later — a
     * server down for a moment, or a password given since, lists on its own.
     */
    public function testARefusedListingIsAskedAgainHalfAMinuteLater(): void
    {
        $files = $this->files();
        $this->transport->fail[''] = 'Access denied';
        $files->listed($this->cache->dir);

        for ($i = 1; $i < 10; ++$i) {
            $files->poll();
        }
        $this->assertFileDoesNotExist($this->cache->path('README.md'), 'not before');

        $files->poll();

        $this->assertFileExists($this->cache->path('README.md'));
    }

    /**
     * What the server said is kept for the next session — after a walk, a
     * move, a made directory and a removal alike — so a placeholder is never
     * read as an empty file, and a fetched file is not fetched again.
     */
    public function testWhatWasLearntSurvivesARestart(): void
    {
        $this->server->write('deep/one/A.php', '<?php');
        $this->transport->walks = true;
        $files = $this->files();
        $files->poll();
        $this->await(fn (): bool => \is_file($this->cache->path('deep/one/A.php')));
        $this->assertFalse($this->files()->available($this->cache->path('deep/one/A.php'), static fn (): null => null), 'walked: a placeholder, next session too');

        $files->listed($this->cache->dir);
        $files->available($this->cache->path('README.md'), static fn (): null => null);
        $files->rename($this->cache->path('README.md'), $this->cache->path('READ.md'));
        $files->mkdir($this->cache->path('made'));
        $files->remove($this->cache->path('empty.txt'));

        $index = (string) \file_get_contents($this->cache->path('.porta/remote-index.json'));
        $this->assertStringContainsString('"READ.md"', $index, 'moved');
        $this->assertStringNotContainsString('"README.md"', $index);
        $this->assertStringContainsString('"made"', $index, 'made');
        $this->assertStringNotContainsString('"empty.txt"', $index, 'removed');
    }

    public function testALocalProjectHasNoCell(): void
    {
        $this->assertSame([], $this->files(spec: null)->statusCells());
    }

    public function testClosingClosesTheTransport(): void
    {
        $files = $this->files();
        $files->close();
        $this->assertFalse($this->transport->closed, 'never asked for: nothing to close');

        $files->listed($this->cache->dir);
        $files->close();

        $this->assertTrue($this->transport->closed);
    }

    public function testAChangeMadeWhileTheServerIsUnreachableWaitsAndLandsOnceItIsBack(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $this->transport->fail['src'] = 'Permission denied';
        $files->listed($this->cache->path('src')); // refused, with the link up: trouble on the cell
        $this->assertSame(StatusTone::Accent, $files->statusCells()[0]->tone);
        $this->transport->down = 'Network is unreachable';

        $files->dumpFile($this->cache->path('README.md'), 'written offline');
        $files->dumpFile($this->cache->path('more.txt'), 'and more');

        $this->assertSame('Lost example.org (Network is unreachable) — trying again', $this->flashText($this->status));
        $this->assertSame(2, $files->pending(), 'nothing dropped');
        $cell = $files->statusCells()[0];
        $this->assertSame('⇅ example.org '.Glyphs::OUT_OF_TOUCH.' ⇡2', $cell->text);
        $this->server->write('THEIRS.md', 'made there meanwhile');
        $this->assertStringContainsString('Cannot reach example.org: Network is unreachable — trying again', (string) $cell->tooltip);
        $calls = \count($this->transport->calls);
        $files->poll();
        $this->assertCount($calls, $this->transport->calls, 'the poll asks nothing of a server that is not there');

        $this->transport->down = null;
        $this->await(static fn (): bool => 0 === $files->pending(), what: 'the backoff, then the changes');

        $this->assertSame('written offline', \file_get_contents($this->server->path('README.md')));
        $this->assertSame('and more', \file_get_contents($this->server->path('more.txt')));
        $this->assertSame('Back in touch with example.org', $this->flashText($this->status));
        $this->assertSame('⇅ example.org', $files->statusCells()[0]->text);
        $this->assertSame(StatusTone::Subtle, $files->statusCells()[0]->tone, 'the root answered: the server says yes');
        $this->assertFileExists($this->cache->path('THEIRS.md'), 'what changed meanwhile is seen at once, as the root is listed');
        $files->close();
    }

    public function testStillDownTheRootIsAskedAgainLaterAndReconnectAsksNow(): void
    {
        $files = $this->files();
        $this->transport->down = 'Connection timed out';
        $files->listed($this->cache->dir);
        $this->assertSame(0, $files->pending());
        $this->assertSame(StatusTone::Accent, $files->statusCells()[0]->tone, 'down, with nothing on its way, is still news');
        $files->dumpFile($this->cache->path('a.txt'), 'a');
        $menu = $files->statusCells()[0]->menu;
        $this->assertNotNull($menu);
        $this->assertTrue($menu()[0]->enabled(), 'Reconnect Now, while down');

        $this->await(fn (): bool => 4 <= \count(\array_filter($this->transport->calls, static fn (string $c): bool => 'list ' === $c)), what: 'a second probe');
        $this->transport->down = null;
        ($menu()[0]->run ?? static function (): void {})();

        $this->assertSame(0, $files->pending(), 'at once, not after the next backoff');
        $this->assertSame('a', \file_get_contents($this->server->path('a.txt')));
        $this->assertFalse($menu()[0]->enabled());
        $files->close();
    }

    public function testARefusedLoginIsNotAskedAgainUntilAPasswordIsGiven(): void
    {
        $files = $this->files();
        $this->transport->down = FakeRemoteTransport::LOGIN_DENIED;
        $files->dumpFile($this->cache->path('a.txt'), 'a');
        $this->assertStringContainsString('example.org refused the login: login denied — right-click to try again', (string) $files->statusCells()[0]->tooltip);
        $calls = \count($this->transport->calls);

        $this->pump(1.5);
        $this->assertCount($calls, $this->transport->calls, 'no login every second for a server that counts them');

        $this->transport->down = null;
        $this->transport->passwordGiven();

        $this->assertSame(0, $files->pending());
        $files->close();
    }

    public function testAnUploadThatLandedButLostItsAnswerIsNotTakenForAConflict(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $files->available($this->cache->path('README.md'), static fn (): null => null);
        // The bytes reach the server; the answer is lost with the link.
        $this->server->write('README.md', 'landed, and longer');
        $this->transport->fail['README.md'] = 'Broken pipe';
        $this->transport->down = 'Broken pipe';

        $files->dumpFile($this->cache->path('README.md'), 'landed, and longer');
        $this->transport->down = null;
        $this->await(static fn (): bool => 0 === $files->pending(), what: 'the retry');

        $this->assertSame('Back in touch with example.org', $this->flashText($this->status), 'no "changed on the server"');
        $files->dumpFile($this->cache->path('README.md'), 'saved again');
        $this->assertSame('saved again', \file_get_contents($this->server->path('README.md')), 'its stamp was adopted: the next save is no conflict');
        $files->close();
    }

    public function testAConflictTheServerReallyHasIsStillOne(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $files->available($this->cache->path('README.md'), static fn (): null => null);
        $this->server->write('README.md', 'theirs, different');

        $files->dumpFile($this->cache->path('README.md'), 'mine');

        $this->assertSame('README.md changed on the server — save again to overwrite', $this->flashText($this->status));
    }

    public function testWhatWasBeingReadWhenTheLinkWentIsReadOnceItIsBack(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $this->transport->down = 'Host is down';
        $opened = false;

        $files->available($this->cache->path('README.md'), static function () use (&$opened): void {
            $opened = true;
        });
        $files->listed($this->cache->path('src'));
        $this->assertFalse($opened);

        $this->transport->down = null;
        $files->reconnect();
        $files->poll();

        $this->assertTrue($opened, 'whoever waited for it still gets it');
        $this->assertSame("# hi\n", \file_get_contents($this->cache->path('README.md')));
        $this->assertFileExists($this->cache->path('src/App.php'), 'the listing asked again');
        $files->close();
    }

    public function testADirectoryTheServerWouldNotMakeKeepsWhatWasPutInIt(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $this->transport->fail['docs'] = 'Permission denied';
        $this->transport->fail['docs/guide.md'] = 'Permission denied';
        $files->mkdir($this->cache->path('docs'));
        $files->dumpFile($this->cache->path('docs/guide.md'), 'mine');

        $files->poll();
        $files->listed($this->cache->dir);

        $this->assertSame('mine', \file_get_contents($this->cache->path('docs/guide.md')), 'not removed for being missing on the server');
        $this->assertSame(2, $files->pending());
    }

    public function testClosingStopsAskingAndForgetsWhatWaited(): void
    {
        $files = $this->files();
        $this->transport->down = 'Network is unreachable';
        $files->dumpFile($this->cache->path('a.txt'), 'a');
        $files->close();
        $calls = \count($this->transport->calls);

        $this->pump(1.3);

        $this->assertCount($calls, $this->transport->calls);
    }

    public function testACopyOfAFileNeverFetchedIsMadeThereAndHere(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);

        $files->copy($this->cache->path('README.md'), $this->cache->path('COPY.md'));

        $this->assertSame(0, $files->pending());
        $this->assertSame("# hi\n", \file_get_contents($this->server->path('COPY.md')));
        $this->assertSame("# hi\n", \file_get_contents($this->cache->path('COPY.md')));
        $this->server->write('COPY.md', 'theirs, and longer');
        $files->dumpFile($this->cache->path('COPY.md'), 'mine');
        $this->assertSame('COPY.md changed on the server — save again to overwrite', $this->flashText($this->status), 'its stamp was kept: a change of theirs is seen, not overwritten');
    }

    public function testARefusedFileInADirectoryTheServerLostIsKept(): void
    {
        $files = $this->files();
        $files->listed($this->cache->dir);
        $files->listed($this->cache->path('src'));
        $this->transport->fail['src/New.php'] = 'Permission denied';
        $files->dumpFile($this->cache->path('src/New.php'), 'mine');
        new Filesystem()->remove($this->server->path('src'));

        $files->listed($this->cache->dir);
        $files->poll();

        $this->assertSame('mine', \file_get_contents($this->cache->path('src/New.php')), 'its directory is not removed with it');
    }

    public function testARefusedDirectoryMadeLaterIsNoLongerUnsent(): void
    {
        $files = $this->files();
        $this->transport->fail['docs'] = 'Permission denied';
        $files->mkdir($this->cache->path('docs'));
        $this->assertSame(1, $files->pending());

        $files->mkdir($this->cache->path('docs'));

        $this->assertSame(0, $files->pending());
        $this->assertDirectoryExists($this->server->path('docs'));
    }

    public function testTheRootAnsweringAfterAnOutageIsAYes(): void
    {
        $files = $this->files();
        $this->transport->fail['src'] = 'Permission denied';
        $files->listed($this->cache->path('src'));
        $this->transport->down = 'Network is unreachable';
        $files->listed($this->cache->dir);
        $this->assertSame(StatusTone::Accent, $files->statusCells()[0]->tone);

        $this->transport->down = null;
        $files->reconnect();

        $this->assertSame(StatusTone::Subtle, $files->statusCells()[0]->tone, 'the old refusal is no longer the news');
        $files->close();
    }

    public function testAChangeThatThrowsDoesNotStallTheQueue(): void
    {
        $files = $this->files();
        $this->transport->throws = new \LogicException('a bug in a transport');

        try {
            $files->dumpFile($this->cache->path('a.txt'), 'a');
            $this->fail('what threw is still heard');
        } catch (\LogicException $error) {
            $this->assertSame('a bug in a transport', $error->getMessage());
        }

        $files->dumpFile($this->cache->path('b.txt'), 'b');
        $this->assertSame('b', \file_get_contents($this->server->path('b.txt')), 'the queue moved on');
    }

    public function testAWalkLostWithTheLinkIsAskedForAgainOnceItIsBack(): void
    {
        $this->transport->walks = true;
        $files = $this->files();
        $files->listed($this->cache->dir);
        $this->transport->down = 'Network is unreachable';
        $files->poll();
        $this->assertContains('walk ', $this->transport->calls);

        $this->transport->down = null;
        $files->reconnect();
        $files->poll();

        $this->assertCount(2, \array_filter($this->transport->calls, static fn (string $call): bool => 'walk ' === $call), 'walked again');
        $files->close();
    }

    public function testARefusedChangeRenamedStaysUnsent(): void
    {
        $files = $this->files();
        $this->server->write('a.txt', 'theirs');
        $files->listed($this->cache->dir);
        $files->available($this->cache->path('a.txt'), static fn (): null => null);
        $this->transport->fail['a.txt'] = 'Disk quota exceeded';
        $files->dumpFile($this->cache->path('a.txt'), 'mine');
        $this->assertSame(1, $files->pending());

        $files->rename($this->cache->path('a.txt'), $this->cache->path('b.txt'));

        $this->assertSame('theirs', \file_get_contents($this->server->path('b.txt')), 'the server moved its own copy');
        $this->assertSame(1, $files->pending(), 'what it refused is still only here');
    }
}
