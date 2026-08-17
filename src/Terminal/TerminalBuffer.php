<?php

declare(strict_types=1);

namespace Porta\Terminal;

use Porta\Support\AnsiText;
use Porta\Ui\Sgr;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A line-oriented scrollback emulator: folds a raw PTY byte stream into a
 * bounded list of ANSI-styled lines.
 *
 * It interprets the control codes that matter for line-oriented tools —
 * newline, carriage return (progress bars), backspace, tab, and erase-line —
 * keeps SGR (colour) runs inline, and strips other cursor-addressing
 * sequences. It is deliberately NOT a full cursor-addressing VT emulator:
 * full-screen TUIs (vim/htop) are out of scope.
 */
final class TerminalBuffer
{
    /** @var string[] committed + current line (last element is the line under construction) */
    public private(set) array $lines = [''];
    private int $col = 0;
    private string $pending = ''; // incomplete escape sequence across chunk boundaries
    private string $pendingSgr = ''; // SGR emitted since the last text write, applied forward

    public function __construct(
        private readonly int $maxLines = 5000,
    ) {}

    public function lineCount(): int
    {
        return \count($this->lines);
    }

    /**
     * The cursor's display column on the last line (this buffer is line
     * oriented, so the cursor is always on the last line). Lets the terminal
     * panel place the hardware cursor for screen readers.
     */
    public function cursorColumn(): int
    {
        return $this->col;
    }

    public function clear(): void
    {
        $this->lines = [''];
        $this->col = 0;
        $this->pending = '';
        $this->pendingSgr = '';
    }

    /**
     * Feed a chunk of raw output.
     */
    public function feed(string $data): void
    {
        $data = $this->pending.$data;
        $this->pending = '';
        $len = \strlen($data);
        $i = 0;

        while ($i < $len) {
            $ch = $data[$i];

            if ("\x1b" === $ch) {
                $seq = AnsiUtils::extractAnsiCode($data, $i);
                if (null === $seq) {
                    // Possibly an escape split across chunks; stash and wait.
                    $this->pending = substr($data, $i);

                    return;
                }
                $this->handleEscape($seq['code']);
                $i += $seq['length'];
                continue;
            }

            if (\ord($ch) < 0x20) {
                match ($ch) {
                    "\n" => $this->newline(),
                    "\r" => $this->col = 0,
                    "\x08" => $this->col = max(0, $this->col - 1),
                    "\t" => $this->writeText(str_repeat(' ', max(0, 8 - ($this->col % 8)))),
                    default => null, // bell and other C0 controls: ignore
                };
                ++$i;
                continue;
            }

            // A run of printable bytes up to the next control byte / ESC.
            $end = $i + 1;
            while ($end < $len && "\x1b" !== $data[$end] && \ord($data[$end]) >= 0x20) {
                ++$end;
            }
            $this->writeText(substr($data, $i, $end - $i));
            $i = $end;
        }
    }

    private function handleEscape(string $code): void
    {
        // SGR (colour) — defer and prepend to the next text so it styles what
        // follows even across a carriage-return overwrite. A full reset
        // collapses the accumulated state to keep it bounded.
        if (str_ends_with($code, 'm') && str_starts_with($code, "\x1b[")) {
            $this->pendingSgr = Sgr::RESET === $code || "\x1b[m" === $code ? $code : $this->pendingSgr.$code;

            return;
        }
        // Erase in line: ESC[K / ESC[0K truncates from the cursor to EOL.
        if (preg_match('/^\x1b\[[0-2]?K$/', $code)) {
            $this->eraseToLineEnd();

            return;
        }
        // Erase entire display / scrollback — reset the buffer (e.g. `clear`).
        if ("\x1b[2J" === $code || "\x1b[3J" === $code) {
            $this->clear();

            return;
        }
        // Erase from the cursor to the end of the display. This buffer is line
        // oriented and the cursor is always on the last line, so it only trims
        // that line's tail — it must NOT wipe the scrollback. Shells emit ESC[J
        // when redrawing the prompt after a command; treating it as a full clear
        // is what made command output vanish.
        if ("\x1b[J" === $code || "\x1b[0J" === $code) {
            $this->eraseToLineEnd();

            return;
        }

        // Everything else (cursor moves incl. home, erase-above, mode sets, OSC) is dropped.
    }

    private function writeText(string $text): void
    {
        // Callers only pass non-empty printable runs.
        // Apply any colour emitted since the last text (survives \r overwrite).
        $text = $this->pendingSgr.$text;
        $this->pendingSgr = '';
        $cur = $this->lines[\count($this->lines) - 1];
        $width = AnsiUtils::visibleWidth($text);

        $append = $this->col >= AnsiUtils::visibleWidth($cur);
        if ($append) {
            // Append (common case), padding any gap left by \t or cursor moves.
            $this->lines[\count($this->lines) - 1] = AnsiText::padRight($cur, $this->col).$text;
        }
        if (!$append) {
            // Overwrite from the cursor column (e.g. after \r).
            $head = AnsiUtils::sliceByColumn($cur, 0, $this->col);
            $tail = AnsiUtils::sliceByColumn($cur, $this->col + $width, AnsiUtils::visibleWidth($cur));
            $this->lines[\count($this->lines) - 1] = $head.$text.$tail;
        }
        $this->col += $width;
    }

    private function eraseToLineEnd(): void
    {
        $cur = $this->lines[\count($this->lines) - 1];
        $this->lines[\count($this->lines) - 1] = AnsiUtils::sliceByColumn($cur, 0, $this->col);
    }

    private function newline(): void
    {
        $this->lines[] = '';
        $this->col = 0;
        if (\count($this->lines) > $this->maxLines) {
            array_shift($this->lines);
        }
    }
}
