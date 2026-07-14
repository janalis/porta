<?php

declare(strict_types=1);

namespace Porta\Ui;

use Symfony\Component\Tui\Terminal\TerminalInterface;

/**
 * Periodically re-asserts terminal state that external events can silently
 * break: a synchronized-output update (`?2026h`) latched open by an
 * interrupted frame write freezes the display, and an outside stop/resume or
 * terminal reset drops the SGR mouse modes. Both are idempotent to re-emit,
 * so every ~30s the unlatch + mouse enables are written blind — invisible
 * when the state is healthy, self-healing when it is not. The alternate
 * screen (1049) is deliberately NOT re-entered: that would be disruptive.
 */
final class TerminalStateRefresher
{
    public const string REASSERT = "\x1b[?2026l\x1b[?1000h\x1b[?1003h\x1b[?1006h";

    private int $ticks = 0;

    public function __construct(
        private readonly TerminalInterface $terminal,
        private readonly int $every = 30,
    ) {}

    /** Called once per filesystem-poll tick (~1s); emits every $every ticks. */
    public function tick(): void
    {
        ++$this->ticks;
        if (0 !== ($this->ticks % $this->every) || $this->terminal->isVirtual()) {
            return;
        }
        $this->terminal->write(self::REASSERT);
    }
}
