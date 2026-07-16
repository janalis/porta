<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Event\NotificationLogged;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * A bounded history of status-bar flashes, so a message that expired after its
 * 2.5 s window can still be read (screen-reader users in particular have no
 * chance to catch a transient flash). {@see Notifier} appends every flash; the
 * `ide_notifications` command shows the entries newest-first in the bottom
 * dock, kept live through {@see NotificationLogged}.
 */
final class NotificationLog
{
    private const int MAX_ENTRIES = 100;

    /** @var list<array{time: \DateTimeImmutable, text: string}> oldest first */
    private array $entries = [];

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly EventDispatcherInterface $dispatcher,
    ) {}

    public function append(string $message): void
    {
        $this->entries[] = ['time' => $this->clock->now(), 'text' => $message];
        if (\count($this->entries) > self::MAX_ENTRIES) {
            array_shift($this->entries);
        }
        $this->dispatcher->dispatch(new NotificationLogged());
    }

    /** @return list<array{time: \DateTimeImmutable, text: string}> oldest first */
    public function entries(): array
    {
        return $this->entries;
    }
}
