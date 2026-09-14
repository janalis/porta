<?php

declare(strict_types=1);

namespace Porta\Widget;

use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Base for the dismissable overlay/panel widgets (find bar, go-to-file,
 * results, debug, terminal). Centralizes the shared lifecycle: an
 * {@see $open} "alive" flag with {@see onOpen()}/{@see onClose()} hooks, a
 * {@see close()} that flips the flag, lets the subclass reset its transient
 * state, repaints, and fires the close hooks exactly once — and, for the
 * dock-managed bottom panels, a separate visibility axis: {@see conceal()}
 * parks an open panel behind another dock tab without touching its state,
 * {@see reveal()} brings it back, and {@see isVisible()} (open AND revealed)
 * is what render guards check. {@see dismiss()} is the soft Esc gesture: it
 * runs the {@see onDismiss()} hook when one is wired (the dock hides the
 * whole panel area) and falls back to a real {@see close()} otherwise, so
 * widgets driven in isolation keep their old behavior. Subclasses keep their
 * own `open(...)` (whose signature and setup differ) and call
 * {@see markOpen()} from it, override {@see resetState()} to clear their own
 * scrollback/query/results, and stay responsible for `implements
 * FocusableInterface` + the focus/keybindings traits (whose private
 * `$onInput` must live on the concrete widget, not this base).
 */
abstract class OverlayWidget extends AbstractWidget
{
    protected bool $open = false;

    /** Whether the panel currently owns its dock slot; meaningless while closed. */
    private bool $revealed = true;

    /** @var list<callable(): void> called when the overlay opens (or re-opens) */
    private array $onOpen = [];

    /** @var list<callable(): void> called when the overlay closes */
    private array $onClose = [];

    /** @var (callable(): void)|null replaces close() as the Esc gesture when wired */
    private $onDismiss;

    public function isOpen(): bool
    {
        return $this->open;
    }

    /** Open AND revealed — the render-guard truth for dock-managed panels. */
    public function isVisible(): bool
    {
        return $this->open && $this->revealed;
    }

    public function onOpen(callable $callback): static
    {
        $this->onOpen[] = $callback;

        return $this;
    }

    public function onClose(callable $callback): static
    {
        $this->onClose[] = $callback;

        return $this;
    }

    public function onDismiss(callable $callback): static
    {
        $this->onDismiss = $callback;

        return $this;
    }

    /** Bring a concealed (but open) panel back into its dock slot. */
    public function reveal(): void
    {
        if (!$this->open || $this->revealed) {
            return;
        }
        $this->revealed = true;
        $this->invalidate();
    }

    /** Park an open panel behind another dock tab; its state stays intact. */
    public function conceal(): void
    {
        if (!$this->revealed) {
            return;
        }
        $this->revealed = false;
        $this->invalidate();
    }

    /**
     * The soft Esc gesture: hand off to the onDismiss hook when wired (the
     * dock conceals the panel area), else a real close().
     */
    public function dismiss(): void
    {
        if (!$this->open) {
            return;
        }
        if (null !== $this->onDismiss) {
            ($this->onDismiss)();

            return;
        }
        $this->close();
    }

    public function close(): void
    {
        if (!$this->open) {
            return;
        }
        $this->open = false;
        $this->revealed = true;
        $this->resetState();
        $this->invalidate();
        foreach ($this->onClose as $callback) {
            $callback();
        }
    }

    /** Flip to open+revealed and fire the open hooks; subclasses call this from their open(...). */
    protected function markOpen(): void
    {
        $this->open = true;
        $this->revealed = true;
        $this->invalidate();
        foreach ($this->onOpen as $callback) {
            $callback();
        }
    }

    /** Reset transient subclass state when the overlay closes (no-op by default). */
    protected function resetState(): void {}
}
