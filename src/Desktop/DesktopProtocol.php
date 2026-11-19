<?php

declare(strict_types=1);

namespace Porta\Desktop;

use Porta\Config\LiveKeybindings;
use Porta\Container\AsIdeService;
use Porta\Workbench\WorkbenchActions;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Tui;

/**
 * The conversation with the native window around Porta, in-band so it crosses
 * a PTY and WSL unchanged (`desktop/contract/protocol.json` is its definition).
 *
 * The host asks to `close`; Porta answers `closing` when it is going, or
 * `accepted` when unsaved work keeps the window open and the user has been told
 * why. `ready` says the IDE is up: before it, nothing can be unsaved and stdin
 * may belong to the trust prompt, so the host closes without asking.
 */
#[AsIdeService]
final readonly class DesktopProtocol
{
    private const string PREFIX = "\x1b]7777;porta-desktop;";

    public function __construct(
        private DesktopSession $session,
        private Tui $tui,
        private WorkbenchActions $actions,
        private LiveKeybindings $keybindings,
    ) {}

    /** Starts listening, in a desktop launch only: in a terminal these bytes mean nothing. */
    public function attach(): void
    {
        if (!$this->session->active) {
            return;
        }
        // Ahead of everything that reads input.
        $this->tui->addListener($this->onInput(...), priority: 2200);
        $this->keybindings->assumeCmdChords();
    }

    /** The input listener: swallows the whole namespace, and acts only on the session's own token. */
    public function onInput(InputEvent $event): void
    {
        $data = $event->getData();
        if (!\str_starts_with($data, self::PREFIX)) {
            return;
        }
        $event->stopPropagation();
        if (!\hash_equals(self::PREFIX.$this->session->token.";close\x1b\\", $data)) {
            return;
        }
        if (!$this->actions->closeWindow(fn () => $this->send('closing'))) {
            $this->send('accepted');
        }
    }

    /** The IDE is up on $root: the window takes its name, and may start asking. */
    public function announceReady(string $root): void
    {
        if (!$this->session->active) {
            return;
        }
        $this->tui->getTerminal()->setTitle(\basename($root).' — Porta');
        $this->send('ready');
    }

    private function send(string $message): void
    {
        $this->tui->getTerminal()->write(self::PREFIX.$this->session->token.';'.$message."\x1b\\");
    }
}
