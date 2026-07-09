<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Input\TextInput;
use Porta\Support\AnsiText;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\CursorShape;

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

    public function value(): string
    {
        return implode("\n", $this->lines);
    }

    public function isEmpty(): bool
    {
        return [''] === $this->lines;
    }

    public function setValue(string $text): void
    {
        $this->lines = explode("\n", $text);
        $this->line = \count($this->lines) - 1;
        $this->col = \strlen($this->lines[$this->line]);
    }

    public function clear(): void
    {
        $this->lines = [''];
        $this->line = 0;
        $this->col = 0;
    }

    /** Handle one keystroke; returns whether it was consumed (content or caret changed). */
    public function handleKey(string $keys): bool
    {
        switch ($keys) {
            case "\r":
            case "\n":
                $current = $this->lines[$this->line];
                $this->lines[$this->line] = substr($current, 0, $this->col);
                array_splice($this->lines, $this->line + 1, 0, [substr($current, $this->col)]);
                ++$this->line;
                $this->col = 0;

                return true;
            case "\x7f":
            case "\x08":
                if ($this->col > 0) {
                    $line = $this->lines[$this->line];
                    $this->lines[$this->line] = substr($line, 0, $this->col - 1).substr($line, $this->col);
                    --$this->col;
                } elseif ($this->line > 0) {
                    $tail = $this->lines[$this->line];
                    array_splice($this->lines, $this->line, 1);
                    --$this->line;
                    $this->col = \strlen($this->lines[$this->line]);
                    $this->lines[$this->line] .= $tail;
                }

                return true;
            case "\x1b[A": // up
                if ($this->line > 0) {
                    --$this->line;
                    $this->col = min($this->col, \strlen($this->lines[$this->line]));
                }

                return true;
            case "\x1b[B": // down
                if ($this->line < (\count($this->lines) - 1)) {
                    ++$this->line;
                    $this->col = min($this->col, \strlen($this->lines[$this->line]));
                }

                return true;
            case "\x1b[D": // left
                if ($this->col > 0) {
                    --$this->col;
                } elseif ($this->line > 0) {
                    --$this->line;
                    $this->col = \strlen($this->lines[$this->line]);
                }

                return true;
            case "\x1b[C": // right
                if ($this->col < \strlen($this->lines[$this->line])) {
                    ++$this->col;
                } elseif ($this->line < (\count($this->lines) - 1)) {
                    ++$this->line;
                    $this->col = 0;
                }

                return true;
        }

        if (TextInput::isPrintable($keys)) {
            $line = $this->lines[$this->line];
            $this->lines[$this->line] = substr($line, 0, $this->col).$keys.substr($line, $this->col);
            $this->col += \strlen($keys);

            return true;
        }

        return false;
    }

    /**
     * The visible window of at most $rows lines clipped to $width, scrolled so
     * the caret line is always shown, with a bar cursor marker at the caret when
     * $cursor is true. Fewer than $rows lines when the content is shorter; always
     * at least one line. Scrolling keeps the caret (and its marker) inside the
     * window, so a caller that renders a fixed row count never drops the cursor.
     *
     * @return list<string>
     */
    public function render(int $width, int $rows, bool $cursor): array
    {
        $top = $this->line < $rows ? 0 : $this->line - $rows + 1;
        $out = [];
        for ($i = $top; $i < min($top + $rows, \count($this->lines)); ++$i) {
            $line = $this->lines[$i];
            if ($cursor && $i === $this->line) {
                // Horizontally window the caret line so the marker stays visible.
                $caretCol = AnsiUtils::visibleWidth(substr($line, 0, $this->col));
                $out[] = AnsiText::caretWindow($line, $caretCol, $width, AnsiUtils::cursorMarker(CursorShape::Bar));

                continue;
            }
            $out[] = AnsiUtils::truncateToWidth($line, $width, '…', pad: false);
        }

        return $out;
    }
}
