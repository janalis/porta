<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * Formats a raw keybinding token string (as stored in the framework's
 * Keybindings — e.g. `ctrl+shift+n`, `f12`, `alt+shift+up`, `shift+f6`,
 * `` ctrl+` ``) into a human display label (`Ctrl+Shift+N`, `F12`,
 * `Alt+Shift+↑`) for the menu bar and, later, the command palette.
 */
final class ShortcutFormatter
{
    private const MODIFIERS = [
        'ctrl' => 'Ctrl',
        'alt' => 'Alt',
        'shift' => 'Shift',
        'cmd' => 'Cmd',
        'meta' => 'Cmd',
        'super' => 'Cmd',
    ];

    private const KEYS = [
        'up' => '↑',
        'down' => '↓',
        'left' => '←',
        'right' => '→',
        'enter' => '⏎',
        'escape' => 'Esc',
        'backspace' => '⌫',
        'tab' => 'Tab',
        'space' => 'Space',
        'delete' => 'Del',
        'insert' => 'Ins',
        'home' => 'Home',
        'end' => 'End',
        'page_up' => 'PgUp',
        'page_down' => 'PgDn',
    ];

    public static function format(string $binding): string
    {
        if ('' === $binding) {
            return '';
        }
        // The literal '+' key is the only token that contains a '+'.
        if ('+' === $binding) {
            return '+';
        }

        $parts = array_map(self::formatToken(...), explode('+', $binding));

        return implode('+', $parts);
    }

    private static function formatToken(string $token): string
    {
        if (isset(self::MODIFIERS[$token])) {
            return self::MODIFIERS[$token];
        }
        if (isset(self::KEYS[$token])) {
            return self::KEYS[$token];
        }
        // Function keys: f1..f12 → F1..F12.
        if (1 === preg_match('/^f([1-9]|1[0-2])$/', $token)) {
            return strtoupper($token);
        }
        // Single letters → uppercase; symbols and anything else kept verbatim.
        if (1 === preg_match('/^[a-z]$/', $token)) {
            return strtoupper($token);
        }

        return $token;
    }
}
