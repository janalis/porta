<?php

declare(strict_types=1);

namespace Porta\Support;

use Revolt\EventLoop;

/**
 * A background check that looks whether it is due once now and then once an
 * hour, for an IDE left open for days — on one timer, which never holds the
 * loop open and ends with {@see stop()}.
 *
 * Shared by the tool and the plugin update checks: both look hourly and act
 * only when their own cadence says a check is owed, so the look is cheap and
 * the cadence is theirs.
 */
final class HourlyLook
{
    private const float EVERY = 3600.0;

    private ?string $timer = null;

    /** @param \Closure(): void $look decides for itself whether anything is due */
    public function __construct(
        private readonly \Closure $look,
    ) {}

    /** Look now, and every hour from now; a second start adds no second timer. */
    public function start(): void
    {
        ($this->look)();
        if (null === $this->timer) {
            $this->timer = EventLoop::repeat(self::EVERY, $this->look);
            EventLoop::unreference($this->timer);
        }
    }

    /** The hourly look ends with the IDE, or it would outlive the loop that runs it. */
    public function stop(): void
    {
        if (null !== $this->timer) {
            EventLoop::cancel($this->timer);
            $this->timer = null;
        }
    }
}
