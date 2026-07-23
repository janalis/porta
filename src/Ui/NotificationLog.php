<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Event\NotificationLogged;
use Porta\Ui\Toast\ToastSeverity;
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

    public function append(string $message, ToastSeverity $severity = ToastSeverity::Info): void
    {
        // Non-info entries carry their severity glyph into the text, so the
        // Notifications dock tool shows the rank with zero changes to the
        // shared LogRows shape (and E2e greps still find the message).
        $text = ToastSeverity::Info === $severity ? $message : $severity->glyph().' '.$message;
        $this->entries[] = ['time' => $this->clock->now(), 'text' => $text];
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
