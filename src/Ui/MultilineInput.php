<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Editor\TextMetrics;
use Porta\Input\TextInput;
use Porta\Support\AnsiText;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A small multi-line text input (the commit description box): a list of
 * lines with a caret, printable insertion, Enter split, Backspace join and
 * arrow movement. Deliberately minimal — no selection, no undo, no
 * horizontal scrolling; long lines are clipped by the caller's width.
 */
final class MultilineInput
{
    /** @var list<string> */
    private array $lines = [''];
    private int $line = 0;
    private int $col = 0;
    private readonly CursorStyle $style;

    public function __construct(?CursorStyle $style = null)
    {
        $this->style = $style ?? new CursorStyle();
    }

    public function value(): string
    {
        return \implode("\n", $this->lines);
    }

    public function isEmpty(): bool
    {
        return [''] === $this->lines;
    }

    public function setValue(string $text): void
    {
        $this->lines = \explode("\n", $text);
        $this->line = \count($this->lines) - 1;
        $this->col = \strlen($this->lines[$this->line]);
    }

    public function clear(): void
    {
        $this->lines = [''];
        $this->line = 0;
        $this->col = 0;
    }

    /**
     * Handle one keystroke; returns whether it was consumed (content or caret
     * changed). A flat dispatch over mutually exclusive key literals — the one
     * ordering constraint is that the printable fallback stays last.
     */
    public function handleKey(string $keys): bool
    {
        switch ($keys) {
            case "\r":
            case "\n":
                return $this->splitLineAtCaret();
            case "\x7f":
            case "\x08":
                return $this->deleteBackward();
            case "\x1b[A":
                return $this->moveUp();
            case "\x1b[B":
                return $this->moveDown();
            case "\x1b[D":
                return $this->moveLeft();
            case "\x1b[C":
                return $this->moveRight();
        }

        return TextInput::isPrintable($keys) && $this->insertPrintable($keys);
    }

    // --- Editing ------------------------------------------------------------

    /** Enter: split the caret line, caret to the start of the new tail line. */
    private function splitLineAtCaret(): bool
    {
        $current = $this->lines[$this->line];
        $this->lines[$this->line] = \substr($current, offset: 0, length: $this->col);
        \array_splice($this->lines, $this->line + 1, length: 0, replacement: [\substr($current, $this->col)]);
        ++$this->line;
        $this->col = 0;

        return true;
    }

    /** Backspace: delete the char before the caret, or join onto the previous line at its end. */
    private function deleteBackward(): bool
    {
        if ($this->col > 0) {
            $line = $this->lines[$this->line];
            $prev = TextMetrics::prevCharBoundary($line, $this->col);
            $this->lines[$this->line] = \substr($line, offset: 0, length: $prev).\substr($line, $this->col);
            $this->col = $prev;

            return true;
        }
        if ($this->line > 0) {
            $tail = $this->lines[$this->line];
            \array_splice($this->lines, $this->line, length: 1);
            --$this->line;
            $this->col = \strlen($this->lines[$this->line]);
            $this->lines[$this->line] .= $tail;
        }

        return true;
    }

    private function insertPrintable(string $keys): bool
    {
        $line = $this->lines[$this->line];
        $this->lines[$this->line] = \substr($line, offset: 0, length: $this->col).$keys.\substr($line, $this->col);
        $this->col += \strlen($keys);

        return true;
    }

    // --- Caret motion (columns snap to the nearest UTF-8 boundary) ----------

    private function moveUp(): bool
    {
        if ($this->line > 0) {
            --$this->line;
            $this->col = TextMetrics::charBoundaryAtOrBefore($this->lines[$this->line], $this->col);
        }

        return true;
    }

    private function moveDown(): bool
    {
        if ($this->line < (\count($this->lines) - 1)) {
            ++$this->line;
            $this->col = TextMetrics::charBoundaryAtOrBefore($this->lines[$this->line], $this->col);
        }

        return true;
    }

    /** Left: previous char, or wrap to the previous line's end. */
    private function moveLeft(): bool
    {
        if ($this->col > 0) {
            $this->col = TextMetrics::prevCharBoundary($this->lines[$this->line], $this->col);

            return true;
        }
        if ($this->line > 0) {
            --$this->line;
            $this->col = \strlen($this->lines[$this->line]);
        }

        return true;
    }

    /** Right: next char, or wrap to the next line's start. */
    private function moveRight(): bool
    {
        if ($this->col < \strlen($this->lines[$this->line])) {
            $this->col = TextMetrics::nextCharBoundary($this->lines[$this->line], $this->col);

            return true;
        }
        if ($this->line < (\count($this->lines) - 1)) {
            ++$this->line;
            $this->col = 0;
        }

        return true;
    }

    /**
     * The visible window of at most $rows lines clipped to $width, scrolled so
     * the caret line is always shown, with the shape-configurable cursor marker at
     * the caret when $cursor is {@see CaretVisibility::Shown}. Fewer than $rows lines when the content is shorter; always
     * at least one line. Scrolling keeps the caret (and its marker) inside the
     * window, so a caller that renders a fixed row count never drops the cursor.
     *
     * @return list<string>
     */
    public function render(int $width, int $rows, CaretVisibility $cursor): array
    {
        $shown = CaretVisibility::Shown === $cursor;
        $top = $this->line < $rows ? 0 : $this->line - $rows + 1;
        $out = [];
        for ($i = $top; $i < \min($top + $rows, \count($this->lines)); ++$i) {
            $line = $this->lines[$i];
            if ($shown && $i === $this->line) {
                // Horizontally window the caret line so the marker stays visible.
                $caretCol = AnsiUtils::visibleWidth(\substr($line, offset: 0, length: $this->col));
                $out[] = AnsiText::caretWindow($line, $caretCol, $width, $this->style->marker());

                continue;
            }
            $out[] = AnsiText::truncate($line, $width, pad: false);
        }

        return $out;
    }
}
