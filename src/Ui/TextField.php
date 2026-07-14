<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Editor\TextMetrics;
use Porta\Input\TextInput;
use Porta\Support\AnsiText;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A single-line text field: the value plus the append/backspace/cursor
 * mechanics that the find bar, go-to-file, results query, and overlay prompt
 * each hand-rolled. Owns no styling beyond the cursor marker — widgets keep
 * their own labels and colors around {@see render()}. The marker's shape comes
 * from the shared {@see CursorStyle} holder (widgets pass theirs through).
 */
final class TextField
{
    private readonly CursorStyle $style;

    public function __construct(
        private string $value = '',
        ?CursorStyle $style = null,
    ) {
        $this->style = $style ?? new CursorStyle();
    }

    public function value(): string
    {
        return $this->value;
    }

    public function setValue(string $value): void
    {
        $this->value = $value;
    }

    public function clear(): void
    {
        $this->value = '';
    }

    /** Append $keys when it is insertable text; returns whether the value changed. */
    public function insert(string $keys): bool
    {
        if (!TextInput::isPrintable($keys)) {
            return false;
        }
        $this->value .= $keys;

        return true;
    }

    /** Append a literal newline (multiline query/replace); rendered as a visible ⏎. */
    public function insertNewline(): void
    {
        $this->value .= "\n";
    }

    /** Delete the last UTF-8 character; returns whether the value changed. */
    public function backspace(): bool
    {
        if ('' === $this->value) {
            return false;
        }
        $this->value = substr($this->value, 0, TextMetrics::prevCharBoundary($this->value, \strlen($this->value)));

        return true;
    }

    /**
     * The value, followed by the cursor marker when $cursor is true. When
     * $width is given and the focused value overflows it, the value is scrolled
     * to its tail so the end caret (and its marker) stays visible.
     */
    public function render(bool $cursor, ?int $width = null): string
    {
        // Newlines (multiline query/replace) show as a visible ⏎ so the row stays one line.
        $display = str_replace("\n", '⏎', $this->value);
        if ($cursor && null !== $width) {
            return AnsiText::caretWindow($display, AnsiUtils::visibleWidth($display), $width, $this->style->marker());
        }

        return $display.($cursor ? $this->style->marker() : '');
    }
}
