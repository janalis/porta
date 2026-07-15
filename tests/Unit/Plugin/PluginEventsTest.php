<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Event\BufferOpened;
use Porta\Event\BufferSaved;
use Porta\Event\BufferSwitched;
use Porta\Plugin\PluginEvents;
use Porta\Plugin\PluginException;

final class PluginEventsTest extends TestCase
{
    private function buffer(string $path): Buffer
    {
        return new BufferManager()->open($path, '');
    }

    public function testEmitsFanOutToEveryListenerPerEventType(): void
    {
        $events = new PluginEvents();
        $log = [];
        $events->subscribe('demo', 'fileOpened', static function (string $p) use (&$log): void {
            $log[] = "opened1:{$p}";
        });
        $events->subscribe('other', 'fileOpened', static function (string $p) use (&$log): void {
            $log[] = "opened2:{$p}";
        });
        $events->subscribe('demo', 'fileSaved', static function (string $p) use (&$log): void {
            $log[] = "saved:{$p}";
        });

        $events->onBufferOpened(new BufferOpened($this->buffer('/a')));
        $events->onBufferSaved(new BufferSaved($this->buffer('/a')));
        $events->onBufferSwitched(new BufferSwitched($this->buffer('/a'))); // no listener → no-op

        $this->assertSame(['opened1:/a', 'opened2:/a', 'saved:/a'], $log);
    }

    public function testBufferSwitchDeduplicatesConsecutiveIdenticalPaths(): void
    {
        $events = new PluginEvents();
        $log = [];
        $events->subscribe('demo', 'bufferSwitched', static function (string $p) use (&$log): void {
            $log[] = $p;
        });

        $events->onBufferSwitched(new BufferSwitched($this->buffer('/a')));
        $events->onBufferSwitched(new BufferSwitched($this->buffer('/a'))); // re-show of the same buffer → silent
        $events->onBufferSwitched(new BufferSwitched($this->buffer('/b')));
        $events->onBufferSwitched(new BufferSwitched($this->buffer('/a'))); // a real switch back → fires

        $this->assertSame(['/a', '/b', '/a'], $log);
    }

    public function testRemoveForSilencesOnlyThatPlugin(): void
    {
        $events = new PluginEvents();
        $log = [];
        $events->subscribe('gone', 'fileSaved', static function (string $p) use (&$log): void {
            $log[] = "gone:{$p}";
        });
        $events->subscribe('kept', 'fileSaved', static function (string $p) use (&$log): void {
            $log[] = "kept:{$p}";
        });

        $events->removeFor('gone');
        $events->removeFor('never-subscribed'); // a no-op
        $events->onBufferSaved(new BufferSaved($this->buffer('/a')));

        $this->assertSame(['kept:/a'], $log, 'the removed plugin no longer fires');
    }

    public function testSubscribingAnUnknownEventThrows(): void
    {
        $this->expectException(PluginException::class);
        $this->expectExceptionMessage('unknown event "fileClosed"');
        new PluginEvents()->subscribe('demo', 'fileClosed', static function (): void {});
    }
}
