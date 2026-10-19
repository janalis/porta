<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Mermaid\Theme\JsObject;
use Porta\GraphViewer\Mermaid\Theme\Khroma;

/**
 * Pinned `vennRenderer` colors: circles cycle `venn1`–`venn8` (else the primary color), their
 * labels darken that color by 30 (lighten on a dark background), and other text takes
 * `vennSetTextColor`, the primary text color or the text color.
 */
final readonly class VennTheme
{
    public function __construct(
        private MermaidPalette $palette,
    ) {}

    /** The authored base color of circle N, as the theme wrote it. */
    public function base(int $index): string
    {
        $colors = [];
        foreach (\range(1, end: 8) as $number) {
            $value = $this->authored('venn'.$number);
            if ('' !== $value) {
                $colors[] = $value;
            }
        }
        $primary = $this->authored('primaryColor');
        $fallback = '' === $primary ? '#ececff' : $primary;

        return [] === $colors ? $fallback : $colors[$index % \count($colors)];
    }

    public function circle(int $index): string
    {
        return MermaidColor::css($this->base($index)) ?? '#ececff';
    }

    public function label(int $index): string
    {
        $background = $this->authored('background');
        try {
            $dark = Khroma::isDark('' === $background ? '#f4f4f4' : $background);
            $color = $dark ? Khroma::lighten($this->base($index), 30) : Khroma::darken($this->base($index), 30);
        } catch (\InvalidArgumentException) {
            return $this->text();
        }

        return MermaidColor::css($color) ?? $this->text();
    }

    public function text(): string
    {
        return $this->palette->color('vennSetTextColor', 'primaryTextColor', 'textColor') ?? '#333333';
    }

    /** A theme variable as the theme wrote it, or empty when it is not text. */
    private function authored(string $name): string
    {
        return self::string(JsObject::get($this->palette->appearance->variables, $name));
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
