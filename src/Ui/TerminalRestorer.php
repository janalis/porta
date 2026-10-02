<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Input\DragDropProtocol;
use Symfony\Component\Tui\Terminal\TerminalInterface;

/**
 * The last-resort terminal restore. `Ide::run()`'s finally is the ordinary
 * teardown; it cannot run when the engine dies underneath it (a fatal error,
 * a signal nothing handled), which strands the user's shell in the alternate
 * screen with SGR mouse reporting still on. Arming registers a shutdown
 * function that re-emits the teardown sequence; the finally disarms it, so
 * the net only fires when the ordinary path never ran. Re-emitting blind is
 * safe: each code in the sequence is an idempotent "off".
 */
final class TerminalRestorer
{
    /**
     * Mouse reporting off (1000/1003/1006), kitty's drag-and-drop opt-out
     * (OSC 72 `t=A`, {@see DragDropProtocol}), then leave the
     * alternate screen.
     */
    public const string RESTORE = "\x1b[?1000l\x1b[?1003l\x1b[?1006l".DragDropProtocol::OPT_OUT."\x1b[?1049l";

    private ?TerminalInterface $terminal = null;

    /** Arm the net: from now until {@see disarm()}, process shutdown restores $terminal. */
    public function arm(TerminalInterface $terminal): void
    {
        if (null === $this->terminal) {
            \register_shutdown_function($this->restore(...));
        }
        $this->terminal = $terminal;
    }

    /** The ordinary teardown ran — the net must not write a second restore. */
    public function disarm(): void
    {
        $this->terminal = null;
    }

    /** Shutdown entry point — a no-op unless armed. */
    public function restore(): void
    {
        $this->terminal?->write(self::RESTORE);
        $this->terminal = null;
    }
}
