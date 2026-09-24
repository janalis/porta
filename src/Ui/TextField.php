<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Editor\TextMetrics;
use Porta\Input\TextInput;
use Porta\Support\AnsiText;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A single-line text field: the value plus the insert/delete/cursor mechanics
 * that the find bar, go-to-file, results query, and overlay prompt each
 * hand-rolled. Owns no styling beyond the cursor marker — widgets keep their
 * own labels and colors around {@see render()}. The marker's shape comes from
 * the shared {@see CursorStyle} holder (widgets pass theirs through).
 *
 * The caret is a byte offset into the value, and it MOVES. It used to be
 * implicit at the tail, so every consumer was append-and-backspace only: fixing
 * a typo at the start of a long value — a JSON debug launch config, say — meant
 * retyping the whole thing. {@see \Porta\Ui\MultilineInput} already proved the
 * model out; this is the same one without the line cases.
 *
 * A caret that has never moved behaves exactly as the append-only field did,
 * which is what makes the change additive for all nine surfaces —
 * `TextFieldTailBehaviourTest` is that contract, written before the caret
 * existed.
 */
final class TextField
{
    private readonly CursorStyle $style;

    /** Byte offset of the caret within {@see $value}; always on a character boundary. */
    private int $col;

    public function __construct(
        private string $value = '',
        ?CursorStyle $style = null,
    ) {
        $this->style = $style ?? new CursorStyle();
        $this->col = \strlen($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function setValue(string $value): void
    {
        $this->value = $value;
        $this->col = \strlen($value); // seeded values are there to be appended to
    }

    public function clear(): void
    {
        $this->value = '';
        $this->col = 0;
    }

    /** Insert $keys at the caret when it is insertable text; returns whether the value changed. */
    public function insert(string $keys): bool
    {
        if (!TextInput::isPrintable($keys)) {
            return false;
        }
        $this->write($keys);

        return true;
    }

    /** Insert a literal newline (multiline query/replace); rendered as a visible ⏎. */
    public function insertNewline(): void
    {
        $this->write("\n");
    }

    /** Delete the UTF-8 character before the caret; returns whether the value changed. */
    public function backspace(): bool
    {
        if (0 === $this->col) {
            return false;
        }
        $from = TextMetrics::prevCharBoundary($this->value, $this->col);
        $this->value = \substr($this->value, offset: 0, length: $from).\substr($this->value, $this->col);
        $this->col = $from;

        return true;
    }

    /** Delete the character AFTER the caret (Delete); returns whether the value changed. */
    public function deleteForward(): bool
    {
        if ($this->col >= \strlen($this->value)) {
            return false;
        }
        $to = TextMetrics::nextCharBoundary($this->value, $this->col);
        $this->value = \substr($this->value, offset: 0, length: $this->col).\substr($this->value, $to);

        return true;
    }

    /** Delete back to the start of the word before the caret; returns whether it changed. */
    public function deleteWordBackward(): bool
    {
        if (0 === $this->col) {
            return false;
        }
        $from = $this->col;
        // Trailing spaces first, then the word itself — the readline gesture.
        while ($from > 0 && ' ' === $this->value[$from - 1]) {
            --$from;
        }
        while ($from > 0 && ' ' !== $this->value[$from - 1]) {
            $from = TextMetrics::prevCharBoundary($this->value, $from);
        }
        $this->value = \substr($this->value, offset: 0, length: $from).\substr($this->value, $this->col);
        $this->col = $from;

        return true;
    }

    /** Move the caret one character left; returns whether it moved. */
    public function moveLeft(): bool
    {
        if (0 === $this->col) {
            return false;
        }
        $this->col = TextMetrics::prevCharBoundary($this->value, $this->col);

        return true;
    }

    /** Move the caret one character right; returns whether it moved. */
    public function moveRight(): bool
    {
        if ($this->col >= \strlen($this->value)) {
            return false;
        }
        $this->col = TextMetrics::nextCharBoundary($this->value, $this->col);

        return true;
    }

    /** Caret to the start; returns whether it moved. */
    public function moveHome(): bool
    {
        $moved = 0 !== $this->col;
        $this->col = 0;

        return $moved;
    }

    /** Caret past the last character; returns whether it moved. */
    public function moveEnd(): bool
    {
        $moved = \strlen($this->value) !== $this->col;
        $this->col = \strlen($this->value);

        return $moved;
    }

    /**
     * Run one of the field's own caret keys — ←/→, Home/End, Delete, Ctrl+W —
     * and return whether it changed anything (false for any other key, and for
     * a key with nothing to do, such as ← at the start).
     *
     * Raw sequences rather than keybinding ids: these are the field's editing
     * keys, not rebindable IDE actions, and every text field in the IDE spells
     * them the same way — the settings form, the pickers' filters, the prompts.
     */
    public function editKey(string $data): bool
    {
        return match ($data) {
            "\x1b[D" => $this->moveLeft(),
            "\x1b[C" => $this->moveRight(),
            "\x1b[H", "\x1bOH", "\x1b[1~" => $this->moveHome(),
            "\x1b[F", "\x1bOF", "\x1b[4~" => $this->moveEnd(),
            "\x1b[3~" => $this->deleteForward(),
            "\x17" => $this->deleteWordBackward(),
            default => false,
        };
    }

    /** Write $text at the caret and leave the caret after it. */
    private function write(string $text): void
    {
        $this->value = \substr($this->value, offset: 0, length: $this->col).$text.\substr($this->value, $this->col);
        $this->col += \strlen($text);
    }

    /**
     * The value, followed by the cursor marker when $cursor is {@see
     * CaretVisibility::Shown}. When $width is given and the focused value
     * overflows it, the value is scrolled to its tail so the end caret (and its
     * marker) stays visible.
     */
    public function render(CaretVisibility $cursor, ?int $width = null): string
    {
        $shown = CaretVisibility::Shown === $cursor;
        // Newlines (multiline query/replace) show as a visible ⏎ so the row stays one line.
        $display = \str_replace("\n", replace: '⏎', subject: $this->value);
        if (!$shown) {
            return $display;
        }
        // The marker goes AT the caret, which is why the caret had to become a
        // real offset: pinning it to the tail is what made every consumer
        // append-only.
        $before = \str_replace("\n", replace: '⏎', subject: \substr($this->value, offset: 0, length: $this->col));
        $caret = AnsiUtils::visibleWidth($before);
        if (null !== $width) {
            return AnsiText::caretWindow($display, $caret, $width, $this->style->marker());
        }

        return $before.$this->style->marker().\substr($display, \strlen($before));
    }
}
