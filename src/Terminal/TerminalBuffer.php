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
    /** The longest unfinished escape held across chunks; an OSC/DCS past it is malformed or hostile. */
    private const int MAX_PENDING = 65_536;

    private const int MAX_PENDING_SGR = 1024;

    /** CSI parameter and intermediate bytes (0x20-0x3F): what may precede its final byte. */
    private const string CSI_BODY = " !\"#$%&'()*+,-./0123456789:;<=>?";

    /** nF intermediate bytes (0x20-0x2F). */
    private const string NF_BODY = " !\"#$%&'()*+,-./";

    /** @var string[] committed + current line (last element is the line under construction) */
    public private(set) array $lines = [''];
    private int $col = 0;
    private string $pending = ''; // incomplete escape sequence across chunk boundaries
    private string $pendingSgr = ''; // SGR emitted since the last text write, applied forward
    private bool $discarding = false; // inside a string sequence past MAX_PENDING, dropped to its terminator

    /**
     * The current (last) line's visible width, tracked as it is built:
     * {@see writeText} needed a full ANSI width scan of the line per
     * printable run, which made assembling a line from k colour-separated
     * runs O(k·n) — the \r progress-bar path fires that many times a second.
     *
     * The asymmetry that matters: tracked too LARGE merely routes an append
     * through the overwrite path, which degenerates to the same output
     * (col ≤ real width always holds, so the head slice is whole and the tail
     * empty); tracked too SMALL mis-routes an overwrite to the append path
     * and garbles the line — the pinned case.
     */
    private int $curWidth = 0;

    /**
     * How far past the cap the buffer may run before one batch trim lands it
     * back at exactly {@see $maxLines}: array_shift per overflowing line
     * reindexed the whole array — ~500M element moves for a 100k-line burst.
     */
    private readonly int $trimSlack;

    public function __construct(
        private readonly int $maxLines = 5000,
    ) {
        $this->trimSlack = \max(1, \intdiv($maxLines, num2: 16));
    }

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
        $this->curWidth = 0;
        $this->pending = '';
        $this->pendingSgr = '';
        $this->discarding = false;
    }

    /**
     * Feed a chunk of raw output.
     */
    public function feed(string $data): void
    {
        if ($this->discarding) {
            // Inside a string sequence too long to hold: drop to its terminator.
            $data = $this->pending.$data;
            $this->pending = '';
            $end = self::terminatorEnd($data);
            if (null === $end) {
                // An ESC last may be half of the `ESC \` that ends it.
                $this->pending = \str_ends_with($data, "\x1b") ? "\x1b" : '';

                return;
            }
            $this->discarding = false;
            $data = \substr($data, $end);
        }
        $data = $this->pending.$data;
        $this->pending = '';
        $len = \strlen($data);
        $i = 0;

        while ($i < $len) {
            $ch = $data[$i];

            if ("\x1b" === $ch) {
                $seq = AnsiUtils::extractAnsiCode($data, $i);
                if (null === $seq && self::unfinished($data, $i)) {
                    // An escape split across chunks; stash and wait.
                    $this->pending = \substr($data, $i);
                    break;
                }
                if (null === $seq && self::isStringSequence($data, $i)) {
                    // Past the cap but still unterminated (an inline image, a
                    // long clipboard write): swallowed to its end, never printed.
                    $this->discarding = true;
                    break;
                }
                if (null === $seq) {
                    // Malformed for good (or past the cap): stashing it would
                    // swallow every later chunk and grow without bound. Drop the
                    // ESC and read on.
                    ++$i;
                    continue;
                }
                $this->handleEscape($seq['code']);
                $i += $seq['length'];
                continue;
            }

            if (\ord($ch[0]) < 0x20) {
                match ($ch) {
                    "\n" => $this->newline(),
                    "\r" => $this->col = 0,
                    "\x08" => $this->col = \max(0, $this->col - 1),
                    "\t" => $this->writeText(\str_repeat(' ', \max(0, 8 - ($this->col % 8)))),
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
            $this->writeText(\substr($data, $i, $end - $i));
            $i = $end;
        }
    }

    /**
     * Whether the bytes from the ESC at $at on (which did not parse) can still
     * become a sequence once more arrive — the only case worth stashing.
     * Offsets, not a substring: a run of bare ESCs would copy the chunk's
     * remainder once per byte.
     */
    private static function unfinished(string $data, int $at): bool
    {
        $len = \strlen($data) - $at;

        return match (true) {
            $len > self::MAX_PENDING => false,
            // A lone ESC, or a string sequence with no terminator yet.
            1 === $len || self::isStringSequence($data, $at) => true,
            '[' === $data[$at + 1] => (2 + \strspn($data, self::CSI_BODY, offset: $at + 2)) === $len,
            // nF: intermediates only so far. Any other byte after ESC is a
            // two-byte form the parser would have taken, or garbage.
            default => (1 + \strspn($data, self::NF_BODY, offset: $at + 1)) === $len,
        };
    }

    /** OSC, DCS, APC, PM or SOS: ended by BEL or `ESC \`, however long. */
    private static function isStringSequence(string $data, int $at): bool
    {
        return \in_array($data[$at + 1] ?? '', [']', 'P', '_', '^', 'X'], strict: true);
    }

    /**
     * Just past the first BEL or `ESC \` in $data, or null when there is none.
     * (Stopping AT the BEL instead is an equivalent mutant: a C0 byte left in
     * front is ignored by the parser either way.)
     */
    private static function terminatorEnd(string $data): ?int
    {
        $bel = \strpos($data, needle: "\x07");
        $st = \strpos($data, needle: "\x1b\\");
        $ends = \array_filter([false === $bel ? null : $bel + 1, false === $st ? null : $st + 2], static fn (?int $end): bool => null !== $end);

        return [] === $ends ? null : \min($ends);
    }

    private function handleEscape(string $code): void
    {
        match (true) {
            // SGR (colour) — defer and prepend to the next text so it styles what
            // follows even across a carriage-return overwrite.
            \str_ends_with($code, 'm') && \str_starts_with($code, "\x1b[") => $this->deferSgr($code),
            // Erase in line: ESC[K / ESC[0K truncates from the cursor to EOL.
            //
            // Erase from the cursor to the end of the display (ESC[J / ESC[0J)
            // does the same. This buffer is line oriented and the cursor is
            // always on the last line, so it only trims that line's tail — it
            // must NOT wipe the scrollback. Shells emit ESC[J when redrawing the
            // prompt after a command; treating it as a full clear is what made
            // command output vanish.
            1 === \preg_match('/^\x1b\[[0-2]?K$/', $code) || "\x1b[J" === $code || "\x1b[0J" === $code => $this->eraseToLineEnd(),
            // Erase entire display / scrollback — reset the buffer (e.g. `clear`).
            "\x1b[2J" === $code || "\x1b[3J" === $code => $this->clear(),
            // Everything else (cursor moves incl. home, erase-above, mode sets, OSC) is dropped.
            default => null,
        };
    }

    /**
     * Hold an SGR code for the next text. A full reset collapses the
     * accumulated state to keep it bounded.
     */
    private function deferSgr(string $code): void
    {
        // Colour with no text after it still accumulates; past the cap the
        // newest code stands alone rather than growing without bound.
        $reset = Sgr::RESET === $code || "\x1b[m" === $code || \strlen($this->pendingSgr) > self::MAX_PENDING_SGR;
        $this->pendingSgr = $reset ? $code : $this->pendingSgr.$code;
    }

    private function writeText(string $text): void
    {
        // Callers only pass non-empty printable runs.
        // Apply any colour emitted since the last text (survives \r overwrite).
        $text = $this->pendingSgr.$text;
        $this->pendingSgr = '';
        $cur = $this->lines[\count($this->lines) - 1];
        $width = AnsiUtils::visibleWidth($text);

        // $curWidth stands in for visibleWidth($cur) everywhere below — the
        // full ANSI scan per printable run is what this field removes.
        $append = $this->col >= $this->curWidth;
        if ($append) {
            // Append (common case), padding any gap left by \t or cursor moves.
            $this->lines[\count($this->lines) - 1] = AnsiText::padRight($cur, $this->col).$text;
            $this->curWidth = $this->col + $width;
        }
        if (!$append) {
            // Overwrite from the cursor column (e.g. after \r). A wide glyph the
            // write half-covers leaves its other half blank, as a terminal does:
            // both sides keep their columns rather than sliding.
            $head = AnsiText::padRight(AnsiUtils::sliceByColumn($cur, 0, $this->col), $this->col);
            $tail = AnsiText::columnsFrom($cur, $this->col + $width, $this->curWidth);
            $this->lines[\count($this->lines) - 1] = $head.$text.$tail;
            $this->curWidth = \max($this->curWidth, $this->col + $width);
        }
        $this->col += $width;
    }

    private function eraseToLineEnd(): void
    {
        $cur = $this->lines[\count($this->lines) - 1];
        $this->lines[\count($this->lines) - 1] = AnsiUtils::sliceByColumn($cur, 0, $this->col);
        $this->curWidth = \min($this->curWidth, $this->col);
    }

    private function newline(): void
    {
        $this->lines[] = '';
        $this->col = 0;
        $this->curWidth = 0;
        // Amortized: run up to trimSlack past the cap, then one array_slice
        // lands back at exactly maxLines — array_shift per line reindexes
        // the whole array every time.
        if (\count($this->lines) > ($this->maxLines + $this->trimSlack)) {
            $this->lines = \array_slice($this->lines, -$this->maxLines);
        }
    }
}
