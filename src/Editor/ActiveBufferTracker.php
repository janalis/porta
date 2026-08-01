<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\ListenerPriority;
use Porta\Event\ActiveBufferChanged;
use Porta\Event\BufferSwitched;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Turns the every-show {@see BufferSwitched} stream into the one-per-change
 * {@see ActiveBufferChanged} stream.
 *
 * A derived event rather than a filter, because a listener cannot filter an
 * event for the listeners behind it: the only propagation control the dispatcher
 * offers is stopping it entirely, which would also cut the core listeners at
 * lower priorities. So "core sees every show, observers see the changes" stops
 * being a priority trick and becomes two facts on two events.
 *
 * At -100 it runs after every priority-0 core listener, so anything observing
 * the derived event is necessarily behind them too.
 */
final class ActiveBufferTracker
{
    /**
     * Never reset, deliberately: closing a file and reopening it produces a
     * `BufferSwitched` with the path already remembered and therefore NO change
     * event. That is the behaviour this replaced, kept rather than quietly
     * improved.
     */
    private ?string $lastShownPath = null;

    public function __construct(
        private readonly EventDispatcherInterface $events,
    ) {}

    #[AsEventListener(priority: ListenerPriority::CHROME_SWEEP)]
    public function onBufferSwitched(BufferSwitched $event): void
    {
        if ($event->buffer->path === $this->lastShownPath) {
            return;
        }
        // BEFORE the dispatch, and load-bearing: a listener that opens a file
        // re-enters this method, and the remembered path is what ends it.
        $this->lastShownPath = $event->buffer->path;
        $this->events->dispatch(new ActiveBufferChanged($event->buffer->path));
    }
}
