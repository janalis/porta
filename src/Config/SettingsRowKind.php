<?php

declare(strict_types=1);

namespace Porta\Config;

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
}
