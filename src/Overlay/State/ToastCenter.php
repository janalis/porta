<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

use Porta\Overlay\Region\ModalBox;
use Porta\Support\Scheduler;
use Porta\Ui\Markup\Markup;
use Symfony\Component\Tui\Tui;

/**
 * The live toast stack: push, auto-expiry (one {@see Scheduler} delay per
 * toast, cancelled on any dismissal), the × / body hit-tests against the
 * screen-space boxes the presenter records each frame, and the ×-hover tint.
 * Rendering belongs to {@see \Porta\Widget\Render\ToastCard}; delivery to
 * {@see \Porta\Overlay\Presenter\ToastPresenter}.
 */
final class ToastCenter
{
    private const int MAX_TOASTS = 5;

    /** @var list<Toast> oldest first — the stack renders top-down newest-first */
    private array $toasts = [];

    private int $nextId = 1;

    /** @var list<array{box: ModalBox, id: int}> screen-space card boxes, recorded by the presenter each frame */
    private array $boxes = [];

    /** The toast whose × is under the pointer, or null. */
    private ?int $closeHoveredId = null;

    /** @var \Closure(): float seconds, monotonic — the clock the hover pause measures against */
    private readonly \Closure $now;

    /**
     * @param (\Closure(): float)|null $now seconds, monotonic; the system's by default, a test's own otherwise
     */
    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly Tui $tui,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): float => \hrtime(true) / 1e9;
    }

    /**
     * Show a toast for $seconds; the oldest is evicted beyond the stack cap. A
     * toast in a $slot that is still showing is replaced where it stands, with
     * a fresh timer, rather than stacked beside — progress becomes its outcome.
     */
    public function push(Markup $message, ToastSeverity $severity, float $seconds = 2.5, ?string $slot = null): void
    {
        $at = null === $slot ? null : \array_find_key($this->toasts, static fn (Toast $live): bool => $live->slot === $slot);
        $toast = new Toast(null === $at ? $this->nextId++ : $this->toasts[$at]->id, $message, $severity, slot: $slot);
        if (null !== $at) {
            $this->scheduler->cancel($this->toasts[$at]->timerId);
            // A card replaced under a resting pointer stays held: no motion
            // event will come to pause the new one, and it is being read.
            $held = $this->toasts[$at]->paused;
            $this->toasts[$at] = $toast;
            if ($held) {
                $toast->paused = true;
                $toast->expiresAt = $seconds;
                $this->tui->requestRender();

                return;
            }
            $this->arm($toast, $seconds);
            $this->tui->requestRender();

            return;
        }
        $this->arm($toast, $seconds);
        $this->toasts[] = $toast;
        while (\count($this->toasts) > self::MAX_TOASTS) {
            $evicted = \array_shift($this->toasts);
            if (null !== $evicted) {
                $this->scheduler->cancel($evicted->timerId);
            }
        }
        $this->tui->requestRender();
    }

    /** Start $toast's expiry $seconds from now. */
    private function arm(Toast $toast, float $seconds): void
    {
        $toast->expiresAt = ($this->now)() + $seconds;
        $toast->paused = false;
        $toast->timerId = $this->scheduler->delay($seconds, function () use ($toast): void {
            $this->remove($toast->id);
            $this->tui->requestRender();
        });
    }

    /** @return list<Toast> newest first — the render order, top card first */
    public function toasts(): array
    {
        return \array_reverse($this->toasts);
    }

    public function isEmpty(): bool
    {
        return [] === $this->toasts;
    }

    /** Cancel every timer and drop the stack — teardown, so nothing outlives the loop. */
    public function dismissAll(): void
    {
        foreach ($this->toasts as $toast) {
            $this->scheduler->cancel($toast->timerId);
        }
        $this->toasts = [];
        $this->boxes = [];
        $this->closeHoveredId = null;
    }

    /**
     * The per-frame geometry hand-off from the presenter, one box per painted
     * card in the same order as {@see toasts()}.
     *
     * @param list<array{box: ModalBox, id: int}> $boxes
     */
    public function setBoxes(array $boxes): void
    {
        $this->boxes = $boxes;
    }

    /**
     * A left-click against the stack: the × dismisses its toast, a card body
     * swallows the click (a toast is not a control surface), anywhere else
     * falls through to the panes. Returns whether the click was consumed.
     */
    public function clickAt(int $row, int $col): bool
    {
        foreach ($this->boxes as $entry) {
            if ($entry['box']->closeHit($row, $col)) {
                $this->dismiss($entry['id']);

                return true;
            }
            if ($entry['box']->contains($row, $col)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Track whether a toast's × is under the pointer (brightened like every
     * close affordance), and hold still the card being read: its timer stops
     * while the pointer is on it and resumes, with the time it had left, when
     * the pointer goes — a message does not vanish from under the reader.
     * Returns whether the pointer is over any card, so the hover never leaks
     * to the pane beneath.
     */
    public function hoverAt(int $row, int $col): bool
    {
        $hovered = null;
        $over = false;
        $under = null;
        foreach ($this->boxes as $entry) {
            if ($entry['box']->closeHit($row, $col)) {
                $hovered = $entry['id'];
            }
            if ($entry['box']->contains($row, $col)) {
                $over = true;
                $under = $entry['id'];
            }
        }
        foreach ($this->toasts as $toast) {
            $this->holdStill($toast, $toast->id === $under);
        }
        if ($hovered !== $this->closeHoveredId) {
            $this->closeHoveredId = $hovered;
            $this->tui->requestRender();
        }

        return $over;
    }

    /** Pause $toast's timer, keeping the time it had left — or resume it with that time. */
    // @mago-expect lint:no-boolean-flag-parameter -- whether the pointer rests on the card IS the state being applied.
    private function holdStill(Toast $toast, bool $under): void
    {
        if ($under && !$toast->paused) {
            $this->scheduler->cancel($toast->timerId);
            $toast->expiresAt = \max(0.0, $toast->expiresAt - ($this->now)());
            $toast->paused = true;

            return;
        }
        if (!$under && $toast->paused) {
            // At least a second back: a card the pointer just left should not
            // vanish in the same breath it was being read.
            $this->arm($toast, \max(1.0, $toast->expiresAt));
        }
    }

    /** Dismiss the newest toast — the one on top — from the keyboard; false when there is none. */
    public function dismissNewest(): bool
    {
        $newest = \array_last($this->toasts);
        if (null === $newest) {
            return false;
        }
        $this->dismiss($newest->id);

        return true;
    }

    /** The toast whose × should render brightened, or null. */
    public function closeHoveredId(): ?int
    {
        return $this->closeHoveredId;
    }

    /** Dismiss one toast (its ×), cancelling the pending expiry. */
    public function dismiss(int $id): void
    {
        $this->remove($id);
        $this->tui->requestRender();
    }

    private function remove(int $id): void
    {
        foreach ($this->toasts as $i => $toast) {
            if ($toast->id !== $id) {
                continue;
            }
            $this->scheduler->cancel($toast->timerId);
            \array_splice($this->toasts, $i, length: 1);

            return;
        }
    }
}
