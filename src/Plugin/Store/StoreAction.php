<?php

declare(strict_types=1);

namespace Porta\Plugin\Store;

/**
 * What a store entry's one button does, which is also what it says: the App
 * Store's GET / OPEN / UPDATE, plus the states a terminal IDE's plugins have
 * that a phone's apps do not — bundled with Porta, switched off, or built for
 * another Porta.
 */
enum StoreAction
{
    /** Not installed: install it. */
    case Get;
    /** Installed and current: go to its Settings page. */
    case Open;
    /** Installed from the store, and a newer release runs here. */
    case Update;
    /** Bundled with Porta and on: go to its Settings page. */
    case BuiltIn;
    /** Bundled or installed, and switched off: switch it on. */
    case Enable;
    /** An install or update of it is running. */
    case Working;
    /** Not installed, and no release of it runs on this Porta. */
    case Incompatible;

    /** The button's caption, a translation id. */
    public function caption(): string
    {
        return match ($this) {
            self::Get => 'Get',
            self::Open => 'Open',
            self::Update => 'Update',
            self::BuiltIn => 'Built-in',
            self::Enable => 'Enable',
            self::Working => 'Working…',
            self::Incompatible => 'Incompatible',
        };
    }

    /** Whether pressing it does anything. */
    public function pressable(): bool
    {
        return self::Working !== $this && self::Incompatible !== $this;
    }
}
