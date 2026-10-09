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
 * shortcut reads the same wherever it appears. A bare key is written as its
 * own name (`S split`, `Y yes`) — never invented shorthand like `s:split`.
 *
 * The rest of the dialect, which only hand-authored strips can get wrong:
 *
 * - `⏎` names the Enter key in a terse hint strip, beside its action, exactly
 *   as the formatter emits it into a menu. In a prose sentence write the word
 *   ("Press Enter again to remove …"): the glyph reads badly mid-sentence and
 *   is already overloaded there as the newline mark (see {@see TextField}).
 * - `/` joins the alternative keys of ONE action (`⏎/F3 next`, `+/- zoom`).
 *   It is part of a single hint, so it never competes with the separators.
 * - `·` separates items generally — hints, and the clauses of a status or
 *   flash message (`Rebound X to Y · applies on restart`). It is the default.
 * - A double space separates hints in the two full-width chrome bars (the
 *   find bar and the status bar) and ONLY there. Not drift: those strips are
 *   already length-bound — the find bar's replace-mode legend overruns an
 *   80- and even a 120-column terminal today — and `·` costs a column per
 *   separator that they have nowhere to take from.
 *
 * {@see \Porta\Tests\Unit\Ui\HintDialectTest} ratchets the modifier spelling
 * over the translation catalog, which is where every user-visible hint lands.
 */
final class ShortcutFormatter
{
    /** @var array<string, string> */
    private const array MODIFIERS = [
        'ctrl' => 'Ctrl',
        'alt' => 'Alt',
        'shift' => 'Shift',
        'cmd' => 'Cmd',
        'meta' => 'Cmd',
        'super' => 'Cmd',
    ];

    /** @var array<string, string> */
    private const array KEYS = [
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
        return match ($binding) {
            '' => '',
            // The literal '+' key is the only token that contains a '+'.
            '+' => '+',
            default => \implode('+', \array_map(self::formatToken(...), \explode('+', $binding))),
        };
    }

    private static function formatToken(#[\SensitiveParameter] string $token): string
    {
        return match (true) {
            \array_key_exists($token, self::MODIFIERS) => self::MODIFIERS[$token],
            \array_key_exists($token, self::KEYS) => self::KEYS[$token],
            // Function keys: f1..f12 → F1..F12.
            1 === \preg_match('/^f([1-9]|1[0-2])$/', $token) => \strtoupper($token),
            // Single letters → uppercase; symbols and anything else kept verbatim.
            1 === \preg_match('/^[a-z]$/', $token) => \strtoupper($token),
            default => $token,
        };
    }
}
