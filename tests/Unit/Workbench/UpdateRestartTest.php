<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\History\RestartSnapshot;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\StoreFixture;
use Porta\Tests\Support\TestTui;
use Porta\Workbench\UpdateRestart;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final class UpdateRestartTest extends TestCase
{
    public function testRequestsCoalesceAndWaitUntilSafe(): void
    {
        $store = new StoreFixture();
        $buffers = new BufferManager();
        $buffers->open($store->project->path('new.php'), 'unsaved')->document->insert('!');
        $restart = new UpdateRestart($store->project->dir, $buffers, new RestartSnapshot(), TestTui::create(), $store->notifier, $store->translator);
        $restart->attempt(static fn (): bool => false);
        $this->assertNull($restart->directory());
        $restart->plugins();
        $restart->core();
        $restart->plugins();
        $restart->start(static fn (): bool => true);
        $timer = Probe::get($restart, 'timer');
        $restart->start(static fn (): bool => false);
        $this->assertSame($timer, Probe::get($restart, 'timer'));
        try {
            $restart->attempt(static fn (): bool => true);
            $this->assertNull($restart->directory());
            $restart->attempt(static fn (): bool => false);
            $directory = $restart->directory();
            $this->assertNotNull($directory);
            $this->assertFileExists($directory.'/session.json');
            $this->assertTrue($restart->updatesCore());
            $restart->core();
            $restart->attempt(static fn (): bool => false);
            $this->assertSame($directory, $restart->directory());
        } finally {
            $restart->stop();
            $restart->stop();
            $directory = $restart->directory();
            if (null !== $directory) {
                new Filesystem()->remove($directory);
            }
        }
    }

    public function testFailedSnapshotKeepsTheIdeRunningWithoutAnAutomaticRetryLoop(): void
    {
        $store = new StoreFixture();
        $filesystem = new class extends Filesystem {
            public function mkdir(string|iterable $dirs, int $mode = 0o777): void
            {
                throw new IOException('full disk');
            }
        };
        $restart = new UpdateRestart($store->project->dir, new BufferManager(), new RestartSnapshot($filesystem), TestTui::create(), $store->notifier, $store->translator);
        $restart->plugins();
        $restart->attempt(static fn (): bool => false);
        $this->assertNull($restart->directory());
        $this->assertStringContainsString('restart postponed', $store->toast());
        $restart->attempt(static fn (): bool => false);
        $this->assertFalse(Probe::get($restart, 'pending'));
    }

    public function testAnUnwritableSnapshotNeverStopsTheSession(): void
    {
        $store = new StoreFixture();
        $filesystem = new class extends Filesystem {
            public ?string $directory = null;

            public function dumpFile(string $filename, mixed $content): void
            {
                $this->directory = \dirname($filename);
                throw new IOException('not writable');
            }
        };
        $restart = new UpdateRestart($store->project->dir, new BufferManager(), new RestartSnapshot($filesystem), TestTui::create(), $store->notifier, $store->translator);
        try {
            $restart->plugins();
            $restart->attempt(static fn (): bool => false);
            $this->assertNull($restart->directory());
            $this->assertStringContainsString('restart postponed', $store->toast());
        } finally {
            if (null !== $filesystem->directory) {
                new Filesystem()->remove($filesystem->directory);
            }
        }
    }
}
