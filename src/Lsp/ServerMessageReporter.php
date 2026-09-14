<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Container\AsIdeService;
use Porta\Workbench\Notifier;

/**
 * Delivers `window/showMessage` — the channel a server uses to tell the USER
 * something, as opposed to `window/logMessage` which talks to the log.
 *
 * Porta registered two notification handlers in the whole tree and this was not
 * one of them, so "Composer autoload not found" or "index corrupt, reindexing"
 * went nowhere, while a complete Notifier and a notification log sat unused.
 *
 * The severity mapping is LSP's MessageType: 1 Error, 2 Warning, 3 Info,
 * 4 Log. Log is the one that does NOT become a toast — it is the server
 * thinking aloud, and it goes to the same place `window/logMessage` goes, which
 * is where someone looking for it would look.
 */
#[AsIdeService]
final readonly class ServerMessageReporter
{
    public function __construct(
        private Notifier $notifier,
        private LspLog $log,
    ) {}

    /**
     * @param array<string, mixed> $params `window/showMessage` params
     */
    public function report(array $params): void
    {
        $message = \is_string($params['message'] ?? null) ? \trim($params['message']) : '';
        // A server that sends an empty body has told the user nothing; a blank
        // toast is worse than silence.
        if ('' === $message) {
            return;
        }

        $type = \is_int($params['type'] ?? null) ? $params['type'] : 3;
        match ($type) {
            1 => $this->notifier->error($message),
            2 => $this->notifier->warn($message),
            // Log: the server thinking aloud. Not worth interrupting for, but
            // not worth losing either.
            4 => $this->log->append($message),
            // Info, and any type outside the enum — shown rather than dropped,
            // because a server we do not fully understand is still talking to
            // the user.
            default => $this->notifier->flash($message),
        };
    }
}
