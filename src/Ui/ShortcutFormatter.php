<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * Formats a raw keybinding token string (as stored in the framework's
 * Keybindings — e.g. `ctrl+shift+n`, `f12`, `alt+shift+up`, `shift+f6`,
 * `` ctrl+` ``) into a human display label (`Ctrl+Shift+N`, `F12`,
 * `Alt+Shift+↑`) for the menu bar and the command palette.
 *
 * This output IS the IDE's canonical key-hint dialect: named modifiers joined
 * with `+` (`Ctrl+`, `Alt+`, `Shift+` — never `^`/`⌥`/`⇧`) and the special
 * keys spelled as in KEYS (`⏎`, `Esc`, `Tab`, …). Hand-authored hint strips
 * (find bar, debug legend, status bar) are written in this dialect too, so a
 * shortcut reads the same wherever it appears.
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

    private static function formatToken(#[\SensitiveParameter] string $token): string
    {
        if (array_key_exists($token, self::MODIFIERS)) {
            return self::MODIFIERS[$token];
        }
        if (array_key_exists($token, self::KEYS)) {
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
