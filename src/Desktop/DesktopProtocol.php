<?php

declare(strict_types=1);

namespace Porta\Desktop;

use Porta\Config\LiveKeybindings;
use Porta\Container\AsIdeService;
use Porta\Support\ControlChars;
use Porta\Widget\MenuBarWidget;
use Porta\Workbench\WorkbenchActions;
use Revolt\EventLoop;
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
final class DesktopProtocol
{
    private const string PREFIX = "\x1b]7777;porta-desktop;";

    /** Often enough that a menu opened after a keystroke is right, rarely enough to cost nothing. */
    private const float MENU_POLL_SECONDS = 0.25;

    private ?string $menuPoll = null;

    public function __construct(
        private readonly DesktopSession $session,
        private readonly Tui $tui,
        private readonly WorkbenchActions $actions,
        private readonly LiveKeybindings $keybindings,
        private readonly MenuBarWidget $menuBar,
        private readonly SystemMenu $systemMenu,
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
        if ($this->session->systemMenu) {
            $this->menuBar->useSystemMenu();
        }
    }

    /** The input listener: swallows the whole namespace, and acts only on the session's own token. */
    public function onInput(InputEvent $event): void
    {
        $data = $event->getData();
        if (!\str_starts_with($data, self::PREFIX)) {
            return;
        }
        $event->stopPropagation();
        $own = self::PREFIX.$this->session->token.';';
        if (!\hash_equals($own, \substr($data, offset: 0, length: \strlen($own)))) {
            return;
        }
        $this->handle(\substr($data, \strlen($own)));
    }

    /** What the window asked for, past the token that proves it was the window. */
    private function handle(string $request): void
    {
        $choice = [];
        if ("close\x1b\\" === $request && !$this->actions->closeWindow(fn () => $this->send('closing'))) {
            $this->send('accepted');
        }
        if ($this->session->systemMenu && 1 === \preg_match('/^menu;([0-9a-f]+);(\d{1,4});(\d{1,4})\x1b\\\\$/D', $request, $choice)) {
            $this->systemMenu->choose($choice[1], (int) $choice[2], (int) $choice[3]);
            $this->syncMenu();
        }
    }

    /** The IDE is up on $root: the window takes its name, and may start asking. */
    public function announceReady(string $root): void
    {
        if (!$this->session->active) {
            return;
        }
        $this->tui->getTerminal()->setTitle(ControlChars::line(\basename($root)).' — Porta');
        $this->send('ready;'.\base64_encode($root));
        if ($this->session->systemMenu) {
            $this->syncMenu();
            $this->menuPoll = EventLoop::unreference(EventLoop::repeat(self::MENU_POLL_SECONDS, $this->syncMenu(...)));
        }
    }

    /** This IDE is ending: the next project's brings its own menus. */
    public function detach(): void
    {
        if (null !== $this->menuPoll) {
            EventLoop::cancel($this->menuPoll);
            $this->menuPoll = null;
        }
    }

    private function syncMenu(): void
    {
        $menus = $this->systemMenu->changes();
        if (null !== $menus) {
            $this->send('menu;'.$menus);
        }
    }

    private function send(string $message): void
    {
        $this->tui->getTerminal()->write(self::PREFIX.$this->session->token.';'.$message."\x1b\\");
    }
}
