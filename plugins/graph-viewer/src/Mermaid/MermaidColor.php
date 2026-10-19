<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Mermaid\Theme\Khroma;

/** CSS and C4 color slots share CSS names and bounded native color validation. */
final class MermaidColor
{
    public static function parse(string $key, string $value, MermaidStatement $statement, string $family): string
    {
        if ('color' !== $key && 'none' === \strtolower($value)) {
            return 'none';
        }

        return self::css($value) ?? throw $statement->error('Invalid '.$family.' style color');
    }

    /** `#rrggbbaa` a share of the way from one `#rrggbbaa` color to another, channel by channel. */
    public static function mixed(string $from, string $to, float $share): string
    {
        $channel = static fn (int $offset): int => (int) \round(((1 - $share) * \hexdec(\substr($from, 1 + $offset, length: 2))) + ($share * \hexdec(\substr($to, 1 + $offset, length: 2))));

        return \sprintf('#%02x%02x%02x%02x', $channel(0), $channel(2), $channel(4), $channel(6));
    }

    /** A CSS color by name or notation, its alpha kept, or null where a browser would drop the declaration. */
    public static function css(string $value): ?string
    {
        $named = CssColorNames::NAMES[\strtolower(\trim($value))] ?? null;
        try {
            // A derived `hsl(240, 100%, NaN%)` fails here, as a browser rejects it.
            $rgba = Khroma::rgbaOf($named ?? \trim($value));
        } catch (\InvalidArgumentException) {
            $rgba = null;
        }

        return null === $rgba ? null : self::format(...$rgba);
    }

    private static function format(float $r, float $g, float $b, float $a): string
    {
        $rgb = \sprintf('#%02x%02x%02x', (int) Khroma::round($r), (int) Khroma::round($g), (int) Khroma::round($b));

        return $a < 1 ? \sprintf('%s%02x', $rgb, (int) Khroma::round($a * 255)) : $rgb;
    }

    /**
     * A color under an opacity: a literal's own alpha scaled by the factor, a theme token
     * faded as the palette resolves it, and none or an unset color as they are.
     */
    public static function faded(?string $color, float $factor): ?string
    {
        $alpha = null !== $color && 9 === \strlen($color) ? \hexdec(\substr($color, offset: 7)) / 255 : 1.0;

        return match (true) {
            null === $color || 'none' === $color || $factor >= 1.0 => $color,
            \str_starts_with($color, '@') => $color.'@'.(int) \round($factor * 100),
            default => \sprintf('%s%02x', \substr($color, offset: 0, length: 7), (int) Khroma::round($alpha * $factor * 255)),
        };
    }
}
