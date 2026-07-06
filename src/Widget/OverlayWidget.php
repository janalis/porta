<?php

declare(strict_types=1);

namespace Porta\Widget;

use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Base for the dismissable overlay/panel widgets (find bar, go-to-file,
 * results, diff, terminal). Centralizes the shared open/close lifecycle: an
 * {@see $open} flag, an {@see onClose()} callback, and a {@see close()} that
 * flips the flag, lets the subclass reset its transient state, repaints, and
 * fires the callback exactly once. Subclasses keep their own `open(...)` (whose
 * signature and setup differ), override {@see resetState()} to clear their own
 * scrollback/query/results, and stay responsible for `implements
 * FocusableInterface` + the focus/keybindings traits (whose private `$onInput`
 * must live on the concrete widget, not this base).
 */
abstract class OverlayWidget extends AbstractWidget
{
    protected bool $open = false;

    /** @var (callable(): void)|null called when the overlay closes */
    private $onClose = null;

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function onClose(callable $callback): static
    {
        $this->onClose = $callback;

        return $this;
    }

    public function close(): void
    {
        if (!$this->open) {
            return;
        }
        $this->open = false;
        $this->resetState();
        $this->invalidate();
        if (null !== $this->onClose) {
            ($this->onClose)();
        }
    }

    /** Reset transient subclass state when the overlay closes (no-op by default). */
    protected function resetState(): void {}
}
