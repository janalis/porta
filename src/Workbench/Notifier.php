<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Container\AsIdeService;
use Porta\Overlay\State\ToastCenter;
use Porta\Overlay\State\ToastSeverity;
use Porta\Support\FlashSink;
use Porta\Ui\Markup\Markup;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Tui;

/**
 * The single notification path: {@see notify()} shows a corner toast in its
 * severity's dress and appends to the {@see NotificationLog}, so expired
 * messages stay reviewable; {@see hint()} is the status-bar-only whisper for
 * transient state a toast would over-announce. {@see flash()} keeps its
 * historic signature as the Info-severity notify — the app-wide call sites
 * (and the plugin API riding them) never had to move.
 *
 * Without a {@see ToastCenter} (direct test construction) every notify falls
 * back to the status-bar flash, preserving the pre-toast behavior seam.
 */
#[AsIdeService]
final readonly class Notifier implements FlashSink
{
    public function __construct(
        private StatusBarWidget $statusBar,
        private Tui $tui,
        private ?NotificationLog $log = null,
        private ?ToastCenter $toasts = null,
    ) {}

    /** Notify at Info severity — the historic entry point, signature unchanged. */
    #[\Override]
    public function flash(string|Markup $message, float $seconds = 2.5, ?string $slot = null): void
    {
        $this->notify($message, ToastSeverity::Info, $seconds, $slot);
    }

    /**
     * Show a severity-dressed toast (error red, warning orange, info yellow, success green)
     * and log it. A $slot names the one message of its kind: a later notify in
     * the same slot replaces the card still showing, so a long-lived
     * "Fetching…" is answered in place by its outcome.
     *
     * A string is shown exactly as written; a {@see Markup} is styled in the
     * toast, and read as plain text by the log and the status-bar fallback.
     */
    public function notify(string|Markup $message, ToastSeverity $severity, float $seconds = 2.5, ?string $slot = null): void
    {
        $markup = Markup::from($message);
        $this->log?->append($markup->plain(), $severity);
        // @mago-expect lint:no-else-clause -- genuine two-way choice with a shared tail below; an early return would duplicate it.
        if (null !== $this->toasts) {
            $this->toasts->push($markup, $severity, $seconds, $slot);
        } else {
            $this->statusBar->flash($markup->plain(), $seconds);
        }
        $this->tui->requestRender();
    }

    public function success(string|Markup $message, float $seconds = 2.5, ?string $slot = null): void
    {
        $this->notify($message, ToastSeverity::Success, $seconds, $slot);
    }

    public function warn(string|Markup $message, float $seconds = 4.0, ?string $slot = null): void
    {
        $this->notify($message, ToastSeverity::Warn, $seconds, $slot);
    }

    /** Errors linger longer — reading time for a message that needs acting on. */
    public function error(string|Markup $message, float $seconds = 5.0, ?string $slot = null): void
    {
        $this->notify($message, ToastSeverity::Error, $seconds, $slot);
    }

    /**
     * A status-bar-only transient hint (mode switches, scroll positions …):
     * no toast, no log entry — state whispering, not an event worth keeping.
     */
    public function hint(string $message, float $seconds = 2.5): void
    {
        $this->statusBar->flash($message, $seconds);
        $this->tui->requestRender();
    }

    /** Teardown: cancel every pending toast timer so nothing outlives the loop. */
    public function dismissAll(): void
    {
        $this->toasts?->dismissAll();
    }
}
