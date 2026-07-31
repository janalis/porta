<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\ActiveBufferTracker;
use Porta\Editor\Buffer;
use Porta\Editor\Document;
use Porta\Event\ActiveBufferChanged;
use Porta\Event\BufferSwitched;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** One event per real change, out of a stream that fires on every show. */
final class ActiveBufferTrackerTest extends TestCase
{
    /** @var list<string> */
    private array $changes = [];

    private function tracker(): ActiveBufferTracker
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ActiveBufferChanged::class, function (ActiveBufferChanged $event): void {
            $this->changes[] = $event->path;
        });

        return new ActiveBufferTracker($dispatcher);
    }

    private function switched(string $path): BufferSwitched
    {
        return new BufferSwitched(new Buffer($path, new Document('')));
    }

    public function testOnlyARealChangeIsDerived(): void
    {
        $tracker = $this->tracker();
        foreach (['/a', '/a', '/b', '/b', '/a'] as $path) {
            $tracker->onBufferSwitched($this->switched($path));
        }

        $this->assertSame(['/a', '/b', '/a'], $this->changes, 'the re-shows in between are silent');
    }

    public function testAListenerThatReEntersTerminates(): void
    {
        // The reason the remembered path is assigned BEFORE the dispatch: a
        // listener that opens a file causes another BufferSwitched from inside
        // this one, and the memo is what ends the recursion rather than the
        // stack.
        $dispatcher = new EventDispatcher();
        $tracker = new ActiveBufferTracker($dispatcher);
        $seen = [];
        $dispatcher->addListener(ActiveBufferChanged::class, function (ActiveBufferChanged $event) use (&$seen, $tracker): void {
            $seen[] = $event->path;
            $tracker->onBufferSwitched($this->switched($event->path)); // "re-show what I just opened"
        });

        $tracker->onBufferSwitched($this->switched('/a'));

        $this->assertSame(['/a'], $seen);
    }
}
