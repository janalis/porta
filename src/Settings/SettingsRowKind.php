<?php

declare(strict_types=1);

namespace Porta\Settings;

/**
 * How a {@see SettingsRow} renders and cycles: a checkbox (bool), a value cycled
 * through `options` (choice), an inline text editor (text), a button (action) or
 * a stepped integer bounded by `min`/`max` (int). Backed by its wire string so
 * the plugin settings API — which registers kinds as strings — can map to it via
 * {@see self::from()}.
 */
enum SettingsRowKind: string
{
    case Bool = 'bool';
    case Choice = 'choice';
    case Text = 'text';
    case Action = 'action';
    case Int = 'int';

    /**
     * Coerce a raw `.porta.json` scalar to this kind. A choice falls back to
     * $default when the value is not one of $options; every other kind is a
     * plain cast.
     *
     * Spelled once here because it was written three times — twice in the
     * plugin layer against the raw kind string, once in the git settings page
     * against this enum. The match is exhaustive over the cases, so a new kind
     * cannot silently fall through to a string cast.
     *
     * @param list<string> $options
     */
    public function coerce(bool|int|string $raw, bool|int|string $default = '', array $options = []): bool|int|string
    {
        return match ($this) {
            self::Bool => (bool) $raw,
            self::Int => (int) $raw,
            self::Choice => \in_array((string) $raw, $options, strict: true) ? (string) $raw : $default,
            self::Text, self::Action => (string) $raw,
        };
    }
}
