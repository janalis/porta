<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Filesystem;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Filesystem\LocalFilesystem;
use Porta\Filesystem\ProjectFiles;
use Porta\Filesystem\ProjectLocation;
use Porta\Filesystem\RemoteFilesystem;
use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteStamp;
use Porta\Filesystem\RemoteTransport;
use Porta\Filesystem\RemoteTransportSource;
use Porta\I18n\Translator;
use Porta\Tests\Support\FakeRemoteTransport;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTui;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

/** A project on this machine: everything is here, and every verb is the disk's. */
final class LocalFilesystemTest extends TestCase
{
    public function testEverythingIsAlreadyHere(): void
    {
        $files = new LocalFilesystem();

        $this->assertTrue($files->available('/anything', function (): never {
            $this->fail('nothing to wait for');
        }));
        $this->assertTrue($files->listed('/anywhere'));
        $this->assertSame(0, $files->pending());
    }

    public function testTheVerbsActOnTheDisk(): void
    {
        $project = new TempProject(['a.txt' => 'a', 'dir/b.txt' => 'b']);
        $files = new LocalFilesystem();

        $files->dumpFile($project->path('new/c.txt'), 'c');
        $files->mkdir($project->path('empty'));
        $files->copy($project->path('a.txt'), $project->path('a2.txt'));
        $files->copy($project->path('dir'), $project->path('dir2'));
        $files->rename($project->path('a2.txt'), $project->path('a3.txt'));
        $files->remove($project->path('a.txt'));

        $this->assertSame('c', \file_get_contents($project->path('new/c.txt')));
        $this->assertDirectoryExists($project->path('empty'));
        $this->assertSame('a', \file_get_contents($project->path('a3.txt')));
        $this->assertSame('b', \file_get_contents($project->path('dir2/b.txt')));
        $this->assertFileDoesNotExist($project->path('a.txt'));
    }

    public function testACopyReplacesANewerTargetAndARenameRefusesATakenNameUnlessAllowed(): void
    {
        $project = new TempProject(['old.txt' => 'old', 'new.txt' => 'new']);
        \touch($project->path('old.txt'), \time() - 100);
        $files = new LocalFilesystem();

        $files->copy($project->path('old.txt'), $project->path('new.txt'));
        $this->assertSame('old', \file_get_contents($project->path('new.txt')), 'the caller decided the target may go');

        $project->write('x.txt', 'x');
        try {
            $files->rename($project->path('x.txt'), $project->path('new.txt'));
            $this->fail('a taken name is an error');
        } catch (IOExceptionInterface $error) {
            $this->assertStringContainsString('already exists', $error->getMessage());
        }
        $files->rename($project->path('x.txt'), $project->path('new.txt'), overwrite: true);
        $this->assertSame('x', \file_get_contents($project->path('new.txt')));
    }

    public function testTheOpenProjectsFilesystemIsTheServersOnlyForARemoteProject(): void
    {
        $server = new TempProject(['a.txt' => 'served']);
        $cache = new TempProject();
        $transport = new FakeRemoteTransport($server->dir);
        $remote = static fn (?RemoteSpec $spec): RemoteFilesystem => new RemoteFilesystem(
            new ProjectLocation($spec),
            $cache->dir,
            new readonly class($transport) implements RemoteTransportSource {
                public function __construct(
                    private RemoteTransport $transport,
                ) {}

                public function remoteTransport(RemoteSpec $spec): RemoteTransport
                {
                    return $this->transport;
                }
            },
            new Notifier(new StatusBarWidget(), TestTui::create()),
            new Translator('en'),
            new BufferManager(),
        );
        $spec = new RemoteSpec('ssh', 'h', '/srv');
        $files = new ProjectFiles(new ProjectLocation($spec), $remote($spec), new LocalFilesystem());

        $this->assertFalse($files->listed($cache->dir));
        $this->assertFalse($files->available($cache->path('a.txt'), static fn (): null => null), 'a placeholder: fetched first');
        $this->assertSame('served', \file_get_contents($cache->path('a.txt')));
        $transport->hold = true;
        $files->dumpFile($cache->path('b.txt'), 'b');
        $files->mkdir($cache->path('d'));
        $files->copy($cache->path('b.txt'), $cache->path('c.txt'));
        $files->rename($cache->path('c.txt'), $cache->path('e.txt'));
        $files->remove($cache->path('e.txt'));
        $this->assertSame(5, $files->pending());
        $transport->release();
        $this->assertSame(['list ', 'fetch a.txt', 'put b.txt', 'mkdir d', 'put c.txt', 'rename c.txt e.txt', 'remove e.txt'], $transport->calls);
        $this->assertEquals(new RemoteStamp(1, (int) \filemtime($server->path('b.txt'))), $transport->stamp('b.txt'));

        $local = new ProjectFiles(new ProjectLocation(), $remote(null), new LocalFilesystem());
        $this->assertTrue($local->listed('/x'));
        $this->assertSame(0, $local->pending());
    }
}
