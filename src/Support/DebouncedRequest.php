<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * "Ask for this, once the asking settles." The bookkeeping both vector viewers
 * need to re-render at the resolution a zoom wants: coalesce on the WANTED
 * value (so a pan, or a zoom that lands on the same resolution, costs nothing),
 * cancel-and-re-arm when it changes (so a Ctrl+scroll glide costs one process
 * rather than one per notch), and forget the pending one when the tab goes away.
 *
 * That last part is why this is a class and not two copies of four lines: a
 * timer left armed on a document that is gone fires into a dead pane, and both
 * viewers would otherwise have to remember to cancel in their own clear().
 *
 * What it deliberately does NOT own is the policy — what the value means, how it
 * is computed, and when a request is worth making at all. Those differ between
 * the two viewers (dpi against a layout extent; a scale against a document size)
 * and stay with them.
 */
final class DebouncedRequest
{
    private ?string $timer = null;

    private ?float $wanted = null;

    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly float $seconds,
    ) {}

    /**
     * Ask for $value after the debounce. Asking again for the value already
     * pending (or already delivered through {@see settle()}) does nothing.
     *
     * @param \Closure(float): void $request
     */
    public function want(float $value, \Closure $request): void
    {
        if (null !== $this->wanted && \abs($value - $this->wanted) < 1e-9) {
            return;
        }
        $this->wanted = $value;
        $this->cancel();
        $this->timer = $this->scheduler->delay($this->seconds, function () use ($value, $request): void {
            $this->timer = null;
            $request($value);
        });
    }

    /**
     * Record a value that was requested by another path, so the next
     * {@see want()} for it coalesces — the viewers' first render, which goes out
     * immediately rather than through the debounce.
     */
    public function settle(float $value): void
    {
        $this->wanted = $value;
    }

    /** Drop the pending request without forgetting what was wanted. */
    public function cancel(): void
    {
        if (null !== $this->timer) {
            $this->scheduler->cancel($this->timer);
            $this->timer = null;
        }
    }

    /** A new document: nothing is pending and nothing has been asked for. */
    public function reset(): void
    {
        $this->cancel();
        $this->wanted = null;
    }
}
