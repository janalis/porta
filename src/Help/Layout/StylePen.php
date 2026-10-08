<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\Styles;

/**
 * Draws a styled run as one self-contained SGR region: everything it needs
 * opened at its start and all of it reset at its end, so the page can splice
 * a highlight over any run with plain column slicing. With colours off it
 * draws the bare text.
 */
final readonly class StylePen
{
    public function __construct(
        public Styles $styles,
    ) {}

    public function paint(string $text, InlineStyle $style): string
    {
        if ('' === $text || $style->plain() || !$this->styles->hasColor()) {
            return $text;
        }

        return $this->open($style).$text.Sgr::RESET;
    }

    /** A whole-line role (a rule, a bar), self-contained like any run. */
    public function role(string $text, Role $role): string
    {
        return $this->paint($text, new InlineStyle(0, $role));
    }

    private function open(InlineStyle $style): string
    {
        $codes = $this->foreground($style);
        if ($style->has(InlineStyle::CODE)) {
            $codes .= $this->styles->of(Role::Toolbar)->getAnsiRestore();
        }
        $codes .= $style->has(InlineStyle::BOLD) ? Sgr::BOLD : '';
        $codes .= $style->has(InlineStyle::ITALIC) ? Sgr::ITALIC : '';
        $codes .= $style->has(InlineStyle::STRIKE) ? Sgr::STRIKETHROUGH : '';
        $codes .= $style->has(InlineStyle::LINK) ? Sgr::UNDERLINE : '';

        return $codes.($style->has(InlineStyle::KBD) ? Sgr::REVERSE : '');
    }

    private function foreground(InlineStyle $style): string
    {
        $role = match (true) {
            $style->has(InlineStyle::LINK) => Role::TabAccent,
            $style->has(InlineStyle::CODE) => Role::Emphasis,
            $style->has(InlineStyle::MUTED) => Role::Muted,
            default => $style->role,
        };

        return null === $role ? '' : $this->styles->of($role)->getAnsiRestore();
    }
}
