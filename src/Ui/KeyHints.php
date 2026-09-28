<?php

declare(strict_types=1);

namespace Porta\Ui;

use Symfony\Component\Tui\Input\Keybindings;

/**
 * Key hints written from the LIVE keymap, never spelled out: a hint that
 * names a chord goes stale the moment the user rebinds it in `.porta.json`
 * or Settings → Keybindings, and a stale hint teaches the wrong key. Only
 * actions the keymap can rebind need this; a widget's own fixed keys may
 * still be written into its strings.
 */
final class KeyHints
{
    /**
     * The display label ({@see ShortcutFormatter}) of the first key bound to
     * $action in the first map that binds it — the editor's before the IDE's,
     * as the menu bar looks them up — or '' when none does.
     */
    public static function key(string $action, Keybindings ...$maps): string
    {
        foreach ($maps as $map) {
            $binding = $map->getBindings($action)[0] ?? null;
            if (null !== $binding) {
                return ShortcutFormatter::format($binding);
            }
        }

        return '';
    }

    /**
     * A strip of hints, `Ctrl+S save  Ctrl+Q quit`: for each action its
     * label, given the key ($label is the translator, the id carrying
     * `%key%`), joined by $separator. An action nothing binds is left out —
     * there is no key to tell of.
     *
     * @param array<string, string>           $hints  action id => message id
     * @param \Closure(string, string): string $label  (message id, key) => the hint
     */
    public static function legend(array $hints, \Closure $label, string $separator, Keybindings ...$maps): string
    {
        $parts = [];
        foreach ($hints as $action => $message) {
            $key = self::key($action, ...$maps);
            if ('' !== $key) {
                $parts[] = $label($message, $key);
            }
        }

        return \implode($separator, $parts);
    }
}
