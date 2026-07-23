<?php

declare(strict_types=1);

namespace Porta\Ui\Toast;

use Porta\Overlay\ModalBox;
use Porta\Support\Scheduler;
use Symfony\Component\Tui\Tui;

/**
 * The live toast stack: push, auto-expiry (one {@see Scheduler} delay per
 * toast, cancelled on any dismissal), the × / body hit-tests against the
 * screen-space boxes the presenter records each frame, and the ×-hover tint.
 * Rendering belongs to {@see \Porta\Widget\Render\ToastCard}; delivery to
 * {@see \Porta\Overlay\ToastPresenter}.
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

    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly Tui $tui,
    ) {}

    /** Show a toast for $seconds; the oldest is evicted beyond the stack cap. */
    public function push(string $message, ToastSeverity $severity, float $seconds = 2.5): void
    {
        $toast = new Toast($this->nextId++, $message, $severity);
        $toast->timerId = $this->scheduler->delay($seconds, function () use ($toast): void {
            $this->remove($toast->id);
            $this->tui->requestRender();
        });
        $this->toasts[] = $toast;
        while (\count($this->toasts) > self::MAX_TOASTS) {
            $evicted = array_shift($this->toasts);
            if (null !== $evicted) {
                $this->scheduler->cancel($evicted->timerId);
            }
        }
        $this->tui->requestRender();
    }

    /** @return list<Toast> newest first — the render order, top card first */
    public function toasts(): array
    {
        return array_reverse($this->toasts);
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
     * close affordance); returns whether the pointer is over any card, so the
     * hover never leaks to the pane beneath.
     */
    public function hoverAt(int $row, int $col): bool
    {
        $hovered = null;
        $over = false;
        foreach ($this->boxes as $entry) {
            if ($entry['box']->closeHit($row, $col)) {
                $hovered = $entry['id'];
            }
            $over = $over || $entry['box']->contains($row, $col);
        }
        if ($hovered !== $this->closeHoveredId) {
            $this->closeHoveredId = $hovered;
            $this->tui->requestRender();
        }

        return $over;
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
            array_splice($this->toasts, $i, 1);

            return;
        }
    }
}
