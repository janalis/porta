<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * The one Support-owned slice of the notification path: flash a transient
 * message. Consumed by Support utilities so they never hold a Ui service —
 * the consuming namespace owns the interface (the LanguageServerProvider
 * precedent), and the alias pass binds the single implementation
 * ({@see \Porta\Workbench\Notifier}) with zero config.
 */
interface FlashSink
{
    public function flash(string $message, float $seconds = 2.5): void;
}
