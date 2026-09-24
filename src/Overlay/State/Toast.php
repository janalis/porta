<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

/**
 * One live corner notification: identity for dismissal (the auto-expiry timer
 * and the × both address the toast, not a position — the stack reorders as
 * neighbours expire). A toast in a $slot is the one message of its kind: a
 * later push to the same slot replaces it in place (a "Fetching…" becomes its
 * outcome instead of lingering beside it).
 */
final class Toast
{
    public function __construct(
        public readonly int $id,
        public readonly string $message,
        public readonly ToastSeverity $severity,
        public string $timerId = '',
        public readonly ?string $slot = null,
        /** When it expires, on the center's clock; while paused, the time it has left. */
        public float $expiresAt = 0.0,
        /** Under the pointer: the timer is off and $expiresAt holds the time left. */
        public bool $paused = false,
    ) {}
}
