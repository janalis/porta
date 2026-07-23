<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

/**
 * One live corner notification: identity for dismissal (the auto-expiry timer
 * and the × both address the toast, not a position — the stack reorders as
 * neighbours expire).
 */
final class Toast
{
    public function __construct(
        public readonly int $id,
        public readonly string $message,
        public readonly ToastSeverity $severity,
        public string $timerId = '',
    ) {}
}
