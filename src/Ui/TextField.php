<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Input\TextInput;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\CursorShape;

/**
 * A single-line text field: the value plus the append/backspace/cursor
 * mechanics that the find bar, go-to-file, results query, and overlay prompt
 * each hand-rolled. Owns no styling beyond the cursor marker — widgets keep
 * their own labels and colors around {@see render()}.
 */
final class TextField
{
    public function __construct(
        private string $value = '',
    ) {}

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

    /** Delete the last byte; returns whether the value changed. */
    public function backspace(): bool
    {
        if ('' === $this->value) {
            return false;
        }
        $this->value = substr($this->value, 0, -1);

        return true;
    }

    /** The value, followed by a bar cursor marker when $cursor is true. */
    public function render(bool $cursor): string
    {
        return $this->value.($cursor ? AnsiUtils::cursorMarker(CursorShape::Bar) : '');
    }
}
