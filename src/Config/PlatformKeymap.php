<?php

declare(strict_types=1);

namespace Porta\Config;

use Symfony\Component\Tui\Input\KeyParser;

/**
 * What one platform's keymap changes over the shipped defaults, laid over them
 * in {@see KeybindingsFactory} below the user's own keymap.
 *
 * Curated, never derived: a rule like "Ctrl becomes Cmd on macOS" would bind
 * Cmd+Q, Cmd+W, Cmd+T, Cmd+N, Cmd+H, Cmd+M and Cmd+, — chords macOS or the
 * terminal keeps for itself, and Cmd+Q quits the terminal with the IDE in it.
 *
 * Every entry ADDS a key and keeps the one it is added to: a terminal that
 * does not forward the new chord still has the old one, and a user moving
 * between machines keeps the keys their fingers know.
 */
final class PlatformKeymap
{
    /**
     * macOS: each action's shipped keys with its Cmd chord added. Spelled out
     * whole rather than derived, so Porta\Config does not reach into the
     * widgets for the editor's keys; PlatformKeymapTest pins every non-Cmd
     * key here to the shipped default, so the two cannot drift.
     *
     * Cmd+V is left out on purpose: the terminal turns it into a bracketed
     * paste of the system clipboard, which is the paste a Mac user means.
     *
     * @var array<string, list<string>>
     */
    private const array MAC_CMD = [
        'copy' => ['super+c', 'ctrl+c'],
        'cut' => ['super+x', 'ctrl+x'],
        'select_all' => ['super+a', 'ctrl+a'],
        'undo' => ['super+z', 'ctrl+z'],
        'redo' => ['super+shift+z', 'ctrl+shift+z'],
        'save' => ['super+s', 'ctrl+s'],
        'save_all' => ['super+alt+s', 'ctrl+alt+s'],
        'find' => ['super+f', 'ctrl+f'],
        'replace' => ['super+r', 'ctrl+r'],
        'duplicate_line' => ['super+d', 'ctrl+d'],
        'toggle_comment' => ['super+/', 'ctrl+/', 'ctrl+-'],
        'goto_line' => ['super+l', 'ctrl+g'],
        'recent_files' => ['super+e', 'ctrl+e'],
        'copy_path' => ['super+shift+c', 'ctrl+shift+c'],
        'tree_copy' => ['super+c', 'ctrl+c'],
        'tree_cut' => ['super+x', 'ctrl+x'],
    ];

    /**
     * Whole lists, for keys that are not chords to add but a key the Mac
     * keyboard does not have: its "delete" key sends Backspace, and Delete is
     * fn+delete.
     *
     * @var array<string, list<string>>
     */
    private const array MAC_KEYS = [
        'reset_binding' => ['backspace', 'delete'],
    ];

    /**
     * Windows: a key for what Windows Terminal keeps for itself. It takes
     * Alt+Enter (full screen) and Ctrl+Shift+V (paste) and Ctrl+Shift+K
     * (clear buffer) before the IDE sees them, so each action gains a key it
     * does not — and leads with it, the key the hints name. The original stays
     * for a terminal that hands it over.
     *
     * @var array<string, list<string>>
     */
    private const array WINDOWS = [
        'code_action' => ['alt+i', 'alt+enter'],
        'paste_history' => ['alt+y', 'ctrl+shift+v'],
        'git_push' => ['alt+k', 'ctrl+shift+k'],
    ];

    /**
     * Linux: GNOME Terminal, GNOME Console and Konsole all keep Ctrl+Shift+V
     * (paste), and Konsole Ctrl+Shift+K. Without the Kitty keyboard protocol
     * neither chord could be told from its Ctrl-only form anyway — Ctrl+Shift+K
     * arrives as Ctrl+K — so the Alt key leads here too.
     *
     * @var array<string, list<string>>
     */
    private const array LINUX = [
        'paste_history' => ['alt+y', 'ctrl+shift+v'],
        'git_push' => ['alt+k', 'ctrl+shift+k'],
    ];

    /**
     * The overlay for $platform, Cmd chords included.
     *
     * Ctrl leads each list, so hints and menus name a key that reaches the
     * IDE: Terminal.app sends no Cmd chord at all, iTerm2 keeps nearly all of
     * them, and Ghostty, kitty and WezTerm each keep a different few. A Cmd
     * chord leads only once it has ARRIVED ($arrived) — proof that this
     * terminal hands that one over.
     *
     * @param list<string> $arrived the Cmd chords that have reached the IDE this session, as {@see KeyParser::parse()} names them
     *
     * @return array<string, list<string>>
     */
    public static function overrides(KeymapPlatform $platform, array $arrived = []): array
    {
        if (KeymapPlatform::MacOs !== $platform) {
            return self::withoutCmd($platform);
        }

        $overlay = self::MAC_KEYS;
        foreach (self::MAC_CMD as $action => $keys) {
            $cmd = \array_filter($keys, static fn (string $key): bool => \str_starts_with($key, 'super+'));
            $proven = \array_filter($cmd, static fn (string $key): bool => \in_array(self::keyId($key), $arrived, strict: true));
            $overlay[$action] = [...$proven, ...\array_diff($keys, $cmd), ...\array_diff($cmd, $proven)];
        }

        return $overlay;
    }

    /**
     * The overlay for $platform without a single Cmd chord — the whole of it
     * off a Mac, and on one for a parser that cannot tell a Cmd chord from the
     * bare key ({@see parsesSuper()}).
     *
     * @return array<string, list<string>>
     */
    public static function withoutCmd(KeymapPlatform $platform): array
    {
        return match ($platform) {
            KeymapPlatform::MacOs => self::MAC_KEYS,
            KeymapPlatform::Windows => self::WINDOWS,
            KeymapPlatform::Linux => self::LINUX,
        };
    }

    /**
     * $key spelled the way the parser names a key it read, so a binding can be
     * compared with a keypress: `super+shift+z` arrives as `shift+super+z`.
     */
    private static function keyId(string $key): string
    {
        $parts = \explode('+', $key);
        $name = \array_pop($parts);

        return \implode('+', [...\array_intersect(['shift', 'ctrl', 'alt', 'super', 'hyper', 'meta'], $parts), $name]);
    }

    /**
     * Whether $parser can tell a Cmd chord from the bare key at all.
     *
     * symfony/tui learned the super modifier late; before that, `super+c` was
     * read as `c` and would have fired on every c typed. So the Cmd chords
     * are only bound once the parser names one.
     */
    public static function parsesSuper(KeyParser $parser): bool
    {
        return 'super+c' === ($parser->parse("\x1b[99;9u")['key'] ?? null);
    }
}
