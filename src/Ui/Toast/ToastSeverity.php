<?php

declare(strict_types=1);

namespace Porta\Ui\Toast;

use Porta\Ui\Glyphs;
use Porta\Ui\Role;

/**
 * A toast's severity: the accent role its card border and glyph carry, so an
 * error reads differently from a success at a glance. Reuses the diagnostics
 * palette (plus {@see Role::Added} for success) — the severities and the
 * diagnostic ranks are the same visual vocabulary, and reusing the themed
 * roles keeps the four theme maps untouched.
 */
enum ToastSeverity
{
    case Info;
    case Success;
    case Warn;
    case Error;

    public function role(): Role
    {
        return match ($this) {
            self::Info => Role::DiagInfo,
            self::Success => Role::Added,
            self::Warn => Role::DiagWarn,
            self::Error => Role::DiagError,
        };
    }

    public function glyph(): string
    {
        return match ($this) {
            self::Info => Glyphs::TOAST_INFO,
            self::Success => Glyphs::TOAST_SUCCESS,
            self::Warn => Glyphs::TOAST_WARN,
            self::Error => Glyphs::TOAST_ERROR,
        };
    }
}
