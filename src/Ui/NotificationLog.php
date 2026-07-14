<?php

declare(strict_types=1);

namespace Porta\Ui;

use Psr\Clock\ClockInterface;

/**
 * A bounded history of status-bar flashes, so a message that expired after its
 * 2.5 s window can still be read (screen-reader users in particular have no
 * chance to catch a transient flash). {@see Notifier} appends every flash; the
 * `ide_notifications` command shows the entries newest-first in a list overlay.
 */
final class NotificationLog
{
    private const int MAX_ENTRIES = 100;

    /** @var list<array{time: \DateTimeImmutable, text: string}> oldest first */
    private array $entries = [];

    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    public function append(string $message): void
    {
        $this->entries[] = ['time' => $this->clock->now(), 'text' => $message];
        if (\count($this->entries) > self::MAX_ENTRIES) {
            array_shift($this->entries);
        }
    }

    /** @return list<array{time: \DateTimeImmutable, text: string}> oldest first */
    public function entries(): array
    {
        return $this->entries;
    }
}
