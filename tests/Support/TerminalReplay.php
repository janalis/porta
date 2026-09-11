<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A cell grid that replays the bytes Porta actually WROTE, the way a terminal
 * would.
 *
 * Every other rig in the suite grades a *composed* frame — the lines the
 * renderer produced. Nothing graded the bytes
 * {@see \Symfony\Component\Tui\Render\ScreenWriter} emits, and those are not
 * the same thing: after the first frame the writer switches to a DIFFERENTIAL
 * repaint that rewrites only the changed row range, positioning itself with
 * RELATIVE cursor moves (`CSI nA`/`CSI nB`) from its own row bookkeeping. A
 * writer like that is correct by construction on the first frame, which is the
 * only frame a full-render test ever exercises; a defect in it can only appear
 * from the second frame onward.
 *
 * So: feed it each frame's bytes in order, then compare {@see rows()} against
 * the frame that was intended. Divergence means the screen the user is looking
 * at is not the screen Porta composed.
 *
 * The emulation is deliberately small — the subset ScreenWriter actually emits
 * (absolute and relative cursor moves, erases, `\r`, `\n`) plus the one
 * behaviour that matters for width bugs: when a row overflows, the terminal
 * WRAPS, and everything after it is a row off.
 */
final class TerminalReplay
{
    /** @var list<list<string>> row-major cells; '' is the second half of a wide glyph */
    private array $grid;

    private int $row = 0;
    private int $col = 0;

    /**
     * @param positive-int $columns
     * @param positive-int $rows
     */
    public function __construct(
        private readonly int $columns,
        private readonly int $rows,
        /**
         * Columns this terminal spends on an East Asian Width *Ambiguous*
         * glyph. One is the near-universal convention and what Porta assumes;
         * two is what a CJK-configured terminal does, and passing it here is
         * how a test asks "what would the user with that terminal see?".
         */
        private readonly int $ambiguousWidth = 1,
    ) {
        $this->grid = array_fill(0, $rows, value: array_fill(0, $columns, value: ' '));
    }

    /** Feed one frame's bytes. State carries over: that is the point. */
    public function feed(string $bytes): void
    {
        $length = \strlen($bytes);
        for ($i = 0; $i < $length;) {
            $byte = $bytes[$i];
            if ("\x1b" === $byte) {
                $i += $this->escape($bytes, $i);

                continue;
            }
            if ("\n" === $byte) {
                ++$this->row;
                ++$i;

                continue;
            }
            if ("\r" === $byte) {
                $this->col = 0;
                ++$i;

                continue;
            }
            $length2 = $this->characterLength($bytes, $i);
            $this->put(substr($bytes, $i, $length2));
            $i += $length2;
        }
    }

    /** The visible screen, trailing blanks trimmed, one string per row. */
    public function rows(): array
    {
        return array_map(
            static fn (array $cells): string => rtrim(implode('', array_filter($cells, static fn (string $c): bool => '' !== $c))),
            $this->grid,
        );
    }

    /** How many bytes the escape sequence at $i occupies, having applied it. */
    private function escape(string $bytes, int $i): int
    {
        $m = [];
        if (preg_match('/\G\x1b\[([0-9;?]*)([@-~])/', $bytes, $m, offset: $i)) {
            $this->csi($m[1], $m[2]);

            return \strlen($m[0]);
        }
        // OSC/APC/DCS carry a payload and end with BEL or ST; they paint nothing.
        $m2 = [];
        if (preg_match('/\G\x1b[_\]P][^\x07\x1b]*(?:\x07|\x1b\\\\)/', $bytes, $m2, offset: $i)) {
            return \strlen($m2[0]);
        }

        return 2;
    }

    private function csi(string $params, string $final): void
    {
        $found = [];
        preg_match_all('/\d+/', $params, $found);
        $nums = array_values(array_map(intval(...), $found[0]));
        $n = $nums[0] ?? 1;

        match ($final) {
            'H' => $this->home($nums),
            'A' => $this->row = max(0, $this->row - $n),
            'B' => $this->row += $n,
            'C' => $this->col += $n,
            'D' => $this->col = max(0, $this->col - $n),
            'K' => $this->eraseLine($nums[0] ?? 0),
            'J' => $this->eraseDisplay($nums[0] ?? 0),
            default => null, // SGR and the private modes paint nothing.
        };
    }

    /** @param list<int> $nums */
    private function home(array $nums): void
    {
        $this->row = ($nums[0] ?? 1) - 1;
        $this->col = ($nums[1] ?? 1) - 1;
    }

    private function eraseLine(int $mode): void
    {
        if ($this->row < 0 || $this->row >= $this->rows) {
            return;
        }
        $from = 0 === $mode ? $this->col : 0;
        $to = 1 === $mode ? $this->col : $this->columns - 1;
        for ($c = $from; $c <= $to && $c < $this->columns; ++$c) {
            $this->grid[$this->row][$c] = ' ';
        }
    }

    private function eraseDisplay(int $mode): void
    {
        if (2 === $mode || 3 === $mode) {
            $this->grid = array_fill(0, $this->rows, value: array_fill(0, $this->columns, value: ' '));

            return;
        }
        for ($r = $this->row; $r < $this->rows; ++$r) {
            $from = $r === $this->row ? $this->col : 0;
            for ($c = $from; $c < $this->columns; ++$c) {
                $this->grid[$r][$c] = ' ';
            }
        }
    }

    private function put(string $character): void
    {
        $width = $this->widthOf($character);
        if (0 === $width) {
            return;
        }
        // The behaviour the whole rig exists for: an over-wide row wraps, and
        // every row after it is one off.
        if ($this->col >= $this->columns) {
            $this->col = 0;
            ++$this->row;
        }
        if ($this->row >= 0 && $this->row < $this->rows && $this->col < $this->columns) {
            $this->grid[$this->row][$this->col] = $character;
            for ($k = 1; $k < $width && ($this->col + $k) < $this->columns; ++$k) {
                $this->grid[$this->row][$this->col + $k] = '';
            }
        }
        $this->col += $width;
    }

    private function widthOf(string $character): int
    {
        $width = AnsiUtils::visibleWidth($character);
        if (1 !== $width || 1 === $this->ambiguousWidth) {
            return $width;
        }

        return self::isAmbiguous($character) ? 2 : 1;
    }

    /**
     * The East Asian Width *Ambiguous* ranges this UI actually draws from.
     *
     * Not the whole property — the vocabulary is {@see \Porta\Ui\Glyphs} plus
     * the separators the widgets compose with, and a table that covered more
     * would be claiming coverage no test here exercises.
     */
    private static function isAmbiguous(string $character): bool
    {
        $cp = mb_ord($character, encoding: 'UTF-8');

        return (
            false !== $cp
            && (
                0xA1 === $cp
                || 0xB7 === $cp
                || 0xD7 === $cp
                || 0xF7 === $cp
                || $cp >= 0x2010
                && $cp <= 0x2027
                || $cp >= 0x2190
                && $cp <= 0x21FF
                || $cp >= 0x2500
                && $cp <= 0x254B
                || $cp >= 0x2550
                && $cp <= 0x2573
                || $cp >= 0x2580
                && $cp <= 0x258F
                || $cp >= 0x2591
                && $cp <= 0x2595
                || $cp >= 0x25A0
                && $cp <= 0x25E5
                || $cp >= 0x25EF
                && $cp <= 0x25EF
                || $cp >= 0xE000
                && $cp <= 0xF8FF
            )
        );
    }

    private function characterLength(string $bytes, int $i): int
    {
        $byte = \ord($bytes[$i]);

        return match (true) {
            $byte >= 0xF0 => 4,
            $byte >= 0xE0 => 3,
            $byte >= 0xC0 => 2,
            default => 1,
        };
    }
}
