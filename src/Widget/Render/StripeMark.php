<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Editor\ChangeMarkKind;
use Porta\Editor\DiagnosticSeverity;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;

/**
 * The kinds of mark the marker stripe draws, in PRECEDENCE order: when
 * several land on one row, the first case wins it. Every kind draws a
 * distinct glyph, so the stripe reads under `--no-ansi` where the roles
 * collapse to nothing.
 */
enum StripeMark
{
    case Paused;
    case Diagnostic;
    case Breakpoint;
    case CurrentHit;
    case Change;
    case Bookmark;
    case Hit;

    public function glyph(?DiagnosticSeverity $severity, ?ChangeMarkKind $change): string
    {
        return match ($this) {
            self::Paused => Glyphs::STOPPED_LINE,
            self::Diagnostic => $severity?->mark() ?? Glyphs::STRIPE_ERROR,
            self::Breakpoint => Glyphs::BREAKPOINT,
            self::CurrentHit => Glyphs::STRIPE_SEARCH_CURRENT,
            self::Change => $change?->glyph() ?? Glyphs::VCS_MODIFIED,
            self::Bookmark => Glyphs::BOOKMARK,
            self::Hit => Glyphs::STRIPE_SEARCH,
        };
    }

    public function role(?DiagnosticSeverity $severity, ?ChangeMarkKind $change): Role
    {
        return match ($this) {
            self::Paused, self::Breakpoint => Role::Breakpoint,
            self::Diagnostic => $severity?->role() ?? Role::DiagError,
            self::CurrentHit => Role::SearchCurrentBg,
            self::Change => $change?->role() ?? Role::Modified,
            self::Bookmark => Role::TabAccent,
            self::Hit => Role::SearchBg,
        };
    }

    /** The English word for the kind, for the pointer's hint; the caller translates it. */
    public function label(): string
    {
        return match ($this) {
            self::Paused => 'Paused here',
            self::Diagnostic => 'Problem',
            self::Breakpoint => 'Breakpoint',
            self::CurrentHit => 'Current match',
            self::Change => 'Change',
            self::Bookmark => 'Bookmark',
            self::Hit => 'Match',
        };
    }
}
