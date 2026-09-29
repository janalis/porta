<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Input\TextInput;
use Porta\Support\AnsiText;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Widget\Util\Line;

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
final readonly class TextField
{
    /** The field's own editing keys, by key id — see {@see editKey()}. */
    private const array KEYS = [
        'left' => [Key::LEFT],
        'right' => [Key::RIGHT],
        'word_left' => ['ctrl+left'],
        'word_right' => ['ctrl+right'],
        'home' => [Key::HOME],
        'end' => [Key::END],
        'delete' => [Key::DELETE],
        'delete_word' => ['ctrl+w'],
        'delete_word_part' => ['alt+backspace'],
    ];

    private CursorStyle $style;

    /**
     * The value and its caret. The vendor line steps by GRAPHEME — an accented
     * letter written as a base plus a combining mark, a flag, a ZWJ family —
     * where this field used to step by code point and leave half of one behind.
     * Its caret is a byte offset like ours was, so rendering is unchanged.
     */
    private Line $line;

    public function __construct(
        string $value = '',
        ?CursorStyle $style = null,
        private PastedNewlines $pastedNewlines = PastedNewlines::Join,
    ) {
        $this->style = $style ?? new CursorStyle();
        $this->line = new Line($value, \strlen($value));
    }

    public function value(): string
    {
        return $this->line->getText();
    }

    public function setValue(string $value): void
    {
        $this->line->setText($value);
        $this->line->setCursor(\strlen($value)); // seeded values are there to be appended to
    }

    public function clear(): void
    {
        $this->setValue('');
    }

    /** Insert $keys at the caret when it is insertable text; returns whether the value changed. */
    public function insert(string $keys): bool
    {
        if (!TextInput::isPrintable($keys)) {
            return false;
        }
        $this->line->insert($keys);

        return true;
    }

    /** Insert a literal newline (multiline query/replace); rendered as a visible ⏎. */
    public function insertNewline(): void
    {
        $this->line->insert("\n");
    }

    /** Delete the character before the caret; returns whether the value changed. */
    public function backspace(): bool
    {
        return $this->line->deleteCharBackward();
    }

    /** Delete the character AFTER the caret (Delete); returns whether the value changed. */
    public function deleteForward(): bool
    {
        return $this->line->deleteCharForward();
    }

    /**
     * Delete back to the start of the word before the caret (Ctrl+W); returns
     * whether it changed. Readline's unix-word-rubout: the spaces before the
     * caret, then everything back to the previous space, so `-d memory_limit=1G`
     * goes as one argument. Alt+Backspace is the finer gesture.
     */
    public function deleteWordBackward(): bool
    {
        $text = $this->line->getText();
        $caret = $this->line->getCursor();
        if (0 === $caret) {
            return false;
        }
        // Spaces are one byte, so the cut never lands inside a character.
        $word = \rtrim(\substr($text, offset: 0, length: $caret), characters: ' ');
        $space = \strrpos($word, needle: ' ');
        $from = false === $space ? 0 : $space + 1;
        $this->line->setText(\substr($text, offset: 0, length: $from).\substr($text, $caret));
        $this->line->setCursor($from);

        return true;
    }

    /**
     * Delete back over one run of a single character class (Alt+Backspace) —
     * letters and digits, punctuation, or spaces — so `src/Foo/Bar.php` loses
     * `php`, then `.`, then `Bar`: readline's backward-kill-word, and what
     * Ctrl+Backspace does in a GUI field. Returns whether it changed.
     */
    public function deleteWordPartBackward(): bool
    {
        return '' !== $this->line->deleteWordBackward();
    }

    /** Move the caret one character left; returns whether it moved. */
    public function moveLeft(): bool
    {
        return $this->line->moveCursorLeft();
    }

    /** Move the caret one character right; returns whether it moved. */
    public function moveRight(): bool
    {
        return $this->line->moveCursorRight();
    }

    /** Caret to the start; returns whether it moved. */
    public function moveHome(): bool
    {
        return $this->line->moveCursorToStart();
    }

    /** Caret past the last character; returns whether it moved. */
    public function moveEnd(): bool
    {
        return $this->line->moveCursorToEnd();
    }

    /**
     * Run one of the field's own editing keys — ←/→ and Ctrl+←/→, Home/End,
     * Delete, Ctrl+W and Alt+Backspace, or a bracketed paste — and return whether it changed
     * anything (false for any other key, and for a key with nothing to do,
     * such as ← at the start).
     *
     * Fixed key ids rather than the IDE's keymap: these are the field's editing
     * keys, not rebindable IDE actions, and every text field in the IDE spells
     * them the same way — the settings form, the pickers' filters, the prompts.
     * They go through the caller's parser, never raw bytes: that parser knows
     * whether the kitty keyboard protocol is on, and under it Ctrl+W arrives as
     * `CSI 119;5u`, not `\x17`.
     */
    public function editKey(string $data, Keybindings $kb): bool
    {
        $pasted = TextInput::pasted($data);
        if (null !== $pasted) {
            return $this->paste($pasted);
        }

        $keys = new Keybindings(self::KEYS, $kb->getParser());

        return match (true) {
            $keys->matches($data, 'left') => $this->moveLeft(),
            $keys->matches($data, 'right') => $this->moveRight(),
            $keys->matches($data, 'word_left') => $this->line->moveWordBackward(),
            $keys->matches($data, 'word_right') => $this->line->moveWordForward(),
            $keys->matches($data, 'home') => $this->moveHome(),
            $keys->matches($data, 'end') => $this->moveEnd(),
            $keys->matches($data, 'delete') => $this->deleteForward(),
            $keys->matches($data, 'delete_word') => $this->deleteWordBackward(),
            $keys->matches($data, 'delete_word_part') => $this->deleteWordPartBackward(),
            default => false,
        };
    }

    /** Insert a bracketed paste's text, its line breaks per {@see PastedNewlines}. */
    private function paste(string $text): bool
    {
        // A copied line carries its terminator, and a query ending in ⏎ matches
        // nothing mid-line: trailing breaks go whatever the field keeps.
        $text = \rtrim($text, characters: "\n");
        if (PastedNewlines::Join === $this->pastedNewlines) {
            $text = (string) \preg_replace('/\n+/', replacement: ' ', subject: $text);
        }
        if ('' === $text) {
            return false;
        }
        $this->line->insert($text);

        return true;
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
        $display = \str_replace("\n", replace: '⏎', subject: $this->line->getText());
        if (!$shown) {
            return $display;
        }
        // The marker goes AT the caret, which is why the caret had to become a
        // real offset: pinning it to the tail is what made every consumer
        // append-only.
        $before = \str_replace("\n", replace: '⏎', subject: \substr($this->line->getText(), offset: 0, length: $this->line->getCursor()));
        $caret = AnsiUtils::visibleWidth($before);
        if (null !== $width) {
            return AnsiText::caretWindow($display, $caret, $width, $this->style->marker());
        }

        return $before.$this->style->marker().\substr($display, \strlen($before));
    }
}
