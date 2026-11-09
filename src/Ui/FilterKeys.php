<?php

declare(strict_types=1);

namespace Porta\Ui;

use Symfony\Component\Tui\Input\Keybindings;

/**
 * A list's type-to-filter field while it is open: printable characters
 * narrow the list, Backspace widens it, Escape leaves the query behind.
 * Everything else — the arrows, Enter — still drives the list, so two letters
 * and Enter need no mode change.
 *
 * Shared by the lists that filter in place (the keymap page, the plugin
 * store), so a key cannot edit the query on one and act on the list on the
 * other.
 */
enum FilterKeys
{
    /** Not the field's key: the list handles it. */
    case Ignored;
    /** The field's key, which left the query as it was. */
    case Kept;
    /** The query changed: the list filters again. */
    case Changed;
    /** Escape: the field closed, its query cleared. */
    case Left;

    /** What $data does to $field, the change already made. */
    public static function edit(TextField $field, string $data, Keybindings $kb): self
    {
        if ($kb->matches($data, 'exit_view')) {
            $field->clear();

            return self::Left;
        }
        $backspace = $kb->matches($data, 'delete_char_backward');
        $changed = $backspace ? $field->backspace() : $field->editKey($data, $kb) || $field->insert($data);

        return match (true) {
            $changed => self::Changed,
            $backspace => self::Kept,
            default => self::Ignored,
        };
    }

    /** Whether the list's rows are now stale: the query changed, or went with the field. */
    public function refilters(): bool
    {
        return self::Changed === $this || self::Left === $this;
    }
}
