<?php

declare(strict_types=1);

namespace Porta\Ui;

use Symfony\Component\Tui\Style\Color;

/**
 * A colored {@see Palette}. Each role maps to an SGR sequence built from
 * symfony/tui {@see Color} objects (256-color palette), so the codes are
 * declared once here instead of scattered as raw escapes across the widgets.
 * {@see dark()} reproduces the historical hardcoded colors exactly.
 */
final class ColorPalette implements Palette
{
    /** @param array<string, string> $codes role name → SGR sequence */
    private function __construct(
        private readonly array $codes,
    ) {}

    public static function dark(): self
    {
        return new self([
            Role::Gutter->name => Color::from(240)->toForegroundCode(),
            Role::Added->name => Color::from(114)->toForegroundCode(),
            Role::Deleted->name => Color::from(203)->toForegroundCode(),
            Role::Hunk->name => Color::from(75)->toForegroundCode(),
            Role::AddEmphasisBg->name => Color::from(22)->toBackgroundCode(),
            Role::DelEmphasisBg->name => Color::from(88)->toBackgroundCode(),
            Role::SelectedRow->name => Color::from(31)->toBackgroundCode().Color::from(231)->toForegroundCode(),
            Role::RowHighlightBg->name => Color::from(236)->toBackgroundCode(),
            Role::PopupRow->name => Color::from(236)->toBackgroundCode().Color::from(250)->toForegroundCode(),
            Role::HeaderBar->name => Color::from(237)->toBackgroundCode(),
            Role::Toolbar->name => Color::from(235)->toBackgroundCode(),
            Role::TagOffBg->name => Color::from(238)->toBackgroundCode(),
            Role::Modified->name => Color::from(179)->toForegroundCode(),
            Role::DangerBg->name => Color::from(88)->toBackgroundCode(),
            Role::Dim->name => Color::from(245)->toForegroundCode(),
            Role::Muted->name => Color::from(250)->toForegroundCode(),
            Role::Bright->name => Color::from(231)->toForegroundCode(),
        ]);
    }

    public function of(Role $role): string
    {
        return $this->codes[$role->name] ?? '';
    }

    public function fgReset(): string
    {
        return "\x1b[39m";
    }

    public function reset(): string
    {
        return "\x1b[0m";
    }
}
