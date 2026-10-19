<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Bounded graphical class properties; unsupported CSS is rejected instead of silently executed. */
final class TreemapStyles
{
    /** @return array<string, float|string|bool> */
    public static function parse(string $text, MermaidStatement $statement): array
    {
        $style = [];
        foreach (\explode(',', \rtrim(\trim($text), characters: ';')) as $property) {
            if ('' === \trim($property)) {
                continue;
            }
            $match = [];
            if (1 !== \preg_match('/^\s*(fill|stroke|color|stroke-width|stroke-dasharray|opacity|fill-opacity|stroke-opacity)\s*:\s*(.+?)\s*$/', $property, $match)) {
                throw $statement->error('Unsupported treemap class property');
            }
            [1 => $key, 2 => $value] = $match;
            $style[$key] = match ($key) {
                'stroke-width' => self::width($value, $statement),
                'stroke-dasharray' => self::dash($value, $statement),
                'opacity', 'fill-opacity', 'stroke-opacity' => new MermaidCss('treemap')->parse($key.':'.$value, $statement)[$key] ?? 1.0,
                default => self::color($value, $key, $statement),
            };
            if ('stroke-dasharray' === $key) {
                $style['dash-pattern'] = $value;
            }
        }

        return $style;
    }

    private static function color(string $value, string $key, MermaidStatement $statement): string
    {
        $color = 'stroke' === $key && 'none' === $value ? 'none' : MermaidColor::css($value);
        if (null === $color) {
            throw $statement->error('Invalid treemap class color');
        }

        return $color;
    }

    private static function width(string $value, MermaidStatement $statement): float
    {
        $number = \rtrim($value, characters: 'px');
        if (!\is_numeric($number) || 1 !== \preg_match('/^\d+(?:\.\d+)?(?:px)?$/', $value)) {
            throw $statement->error('Treemap border width requires 0 to 1000 pixels');
        }

        $width = (float) $number;
        if ($width > 1000) {
            throw $statement->error('Treemap border width requires 0 to 1000 pixels');
        }

        return $width / 10;
    }

    private static function dash(string $value, MermaidStatement $statement): bool
    {
        if (1 !== \preg_match('/^\d+(?:\.\d+)?(?:\s+\d+(?:\.\d+)?)*$/', $value)) {
            throw $statement->error('Treemap dash array requires non-negative lengths');
        }

        return 1 === \preg_match('/[1-9]/', $value);
    }

    public static function contrast(string $fill): string
    {
        $red = \hexdec(\substr($fill, offset: 1, length: 2));
        $green = \hexdec(\substr($fill, offset: 3, length: 2));
        $blue = \hexdec(\substr($fill, offset: 5, length: 2));

        return ((299 * $red) + (587 * $green) + (114 * $blue)) >= 140_000 ? '#222222' : '#ffffff';
    }
}
