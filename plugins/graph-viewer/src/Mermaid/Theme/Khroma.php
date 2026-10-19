<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid\Theme;

use Porta\GraphViewer\Mermaid\CssColorNames;

/**
 * The khroma 2.1.0 functions Mermaid's themes call (MIT; resources/licenses/khroma.txt).
 * Non-string inputs keep their JavaScript failures: writing a channel of a primitive throws.
 */
final class Khroma
{
    /** khroma 2.1.0 spells `cyan` as `cyanaqua` and lacks two CSS names, which then throw upstream too. */
    private const array MISSING = ['cyan', 'steelblue', 'tomato'];
    private const array EXTRA = ['cyanaqua' => '#00ffff', 'transparent' => '#00000000'];

    private const string HEX = '/^#((?:[a-f0-9]{2}){2,4}|[a-f0-9]{3})$/i';
    private const string RGB = '/^rgba?\(\s*?(-?(?:\d+(?:\.\d+)?|(?:\.\d+))(?:e\d+)?(%?))\s*?(?:,|\s)\s*?(-?(?:\d+(?:\.\d+)?|(?:\.\d+))(?:e\d+)?(%?))\s*?(?:,|\s)\s*?(-?(?:\d+(?:\.\d+)?|(?:\.\d+))(?:e\d+)?(%?))(?:\s*?(?:,|\/)\s*?\+?(-?(?:\d+(?:\.\d+)?|(?:\.\d+))(?:e\d+)?(%?)))?\s*?\)$/i';
    private const string HSL = '/^hsla?\(\s*?(-?(?:\d+(?:\.\d+)?|(?:\.\d+))(?:e-?\d+)?(?:deg|grad|rad|turn)?)\s*?(?:,|\s)\s*?(-?(?:\d+(?:\.\d+)?|(?:\.\d+))(?:e-?\d+)?%)\s*?(?:,|\s)\s*?(-?(?:\d+(?:\.\d+)?|(?:\.\d+))(?:e-?\d+)?%)(?:\s*?(?:,|\/)\s*?\+?(-?(?:\d+(?:\.\d+)?|(?:\.\d+))(?:e-?\d+)?(%)?))?\s*?\)$/i';

    public static function parse(string $color): KhromaChannels
    {
        return self::hex($color) ?? self::rgb($color) ?? self::hsl($color) ?? self::keyword($color) ?? throw new \InvalidArgumentException('Unsupported color format: "'.$color.'"');
    }

    public static function stringify(KhromaChannels $channels): string
    {
        $a = $channels->get('a');
        $unchanged = !$channels->changed && null !== $channels->color && '' !== $channels->color;
        $hsl = !$unchanged && $channels->hsl();
        $hex = !$unchanged && !$hsl && $a >= 1 && self::integer($channels->get('r')) && self::integer($channels->get('g')) && self::integer($channels->get('b'));

        return match (true) {
            $unchanged => $channels->color,
            $hsl => self::hslText($channels),
            $hex => \sprintf('#%02x%02x%02x', (int) self::round($channels->get('r')), (int) self::round($channels->get('g')), (int) self::round($channels->get('b'))),
            default => self::rgbText($channels),
        };
    }

    public static function toRgba(string $color): string
    {
        return self::rgbText(self::parse($color));
    }

    /**
     * Drawing channels: 0–255 color components and 0–1 opacity.
     *
     * @return array{float, float, float, float}
     */
    public static function rgbaOf(string $color): array
    {
        $channels = self::parse($color);

        return [$channels->get('r'), $channels->get('g'), $channels->get('b'), $channels->get('a')];
    }

    private static function hslText(KhromaChannels $channels): string
    {
        $hsl = self::number($channels->get('h')).', '.self::number($channels->get('s')).'%, '.self::number($channels->get('l')).'%';
        $a = $channels->get('a');

        return $a < 1 ? 'hsla('.$hsl.', '.JsNumber::format($a).')' : 'hsl('.$hsl.')';
    }

    private static function rgbText(KhromaChannels $channels): string
    {
        $rgb = self::number($channels->get('r')).', '.self::number($channels->get('g')).', '.self::number($channels->get('b'));
        $a = $channels->get('a');

        return $a < 1 ? 'rgba('.$rgb.', '.self::number($a).')' : 'rgb('.$rgb.')';
    }

    public static function adjust(mixed $color, \stdClass $channels): string
    {
        $parsed = self::channels($color);
        $changes = [];
        // @mago-expect analysis:mixed-assignment -- Theme literals pass numbers; others coerce as JavaScript does.
        foreach (\get_object_vars($channels) as $channel => $amount) {
            if (!ThemeProgram::truthy($amount)) {
                continue;
            }
            $channel = self::channel((string) $channel);
            $changes[$channel] = $parsed->get($channel) + ThemeProgram::number($amount);
        }

        return self::change($color, $changes);
    }

    /** @param array<'r'|'g'|'b'|'h'|'s'|'l'|'a', float> $changes */
    public static function change(mixed $color, array $changes): string
    {
        $parsed = self::channels($color);
        foreach ($changes as $channel => $value) {
            $parsed->set($channel, KhromaChannels::clamp($channel, $value));
        }

        return self::stringify($parsed);
    }

    public static function darken(mixed $color, float $amount): string
    {
        return self::lightness($color, -$amount);
    }

    public static function lighten(mixed $color, float $amount): string
    {
        return self::lightness($color, $amount);
    }

    /** khroma's mix(inverse, color, 100) gives the inverse the whole weight. */
    public static function invert(mixed $color): string
    {
        $inverse = self::channels($color);
        $inverse->set('r', 255 - $inverse->get('r'));
        $inverse->set('g', 255 - $inverse->get('g'));
        $inverse->set('b', 255 - $inverse->get('b'));

        return self::rgba($inverse->get('r'), $inverse->get('g'), $inverse->get('b'), $inverse->get('a'));
    }

    public static function rgba(float $r, float $g, float $b, float $a): string
    {
        return self::stringify(new KhromaChannels([
            'r' => KhromaChannels::clamp('r', $r),
            'g' => KhromaChannels::clamp('g', $g),
            'b' => KhromaChannels::clamp('b', $b),
            'a' => KhromaChannels::clamp('a', $a),
        ]));
    }

    public static function isDark(mixed $color): bool
    {
        if (null === $color) {
            throw new \InvalidArgumentException('Cannot read a color channel of null');
        }
        if (!\is_string($color)) {
            return true; // A primitive's channels are NaN, and NaN is never light.
        }
        $parsed = self::parse($color);
        $luminance = (.2126 * self::linear($parsed->get('r'))) + (.7152 * self::linear($parsed->get('g'))) + (.0722 * self::linear($parsed->get('b')));

        return self::tenth($luminance) < .5;
    }

    /** JavaScript's Math.round: halves round towards positive infinity. */
    public static function round(float $value): float
    {
        $floor = \floor($value);

        return ($value - $floor) >= .5 ? $floor + 1 : $floor;
    }

    private static function lightness(mixed $color, float $amount): string
    {
        $parsed = self::channels($color);
        $current = $parsed->get('l');
        $next = KhromaChannels::clamp('l', $current + $amount);
        if ($current !== $next) {
            $parsed->set('l', $next);
        }

        return self::stringify($parsed);
    }

    private static function channels(mixed $color): KhromaChannels
    {
        if (!\is_string($color)) {
            throw new \InvalidArgumentException('Cannot create a color channel on '.\get_debug_type($color));
        }

        return self::parse($color);
    }

    /** @return 'r'|'g'|'b'|'h'|'s'|'l'|'a' */
    private static function channel(string $channel): string
    {
        return match ($channel) {
            'r', 'g', 'b', 'h', 's', 'l', 'a' => $channel,
            default => throw new \InvalidArgumentException('Unsupported color channel '.$channel),
        };
    }

    private static function hex(string $color): ?KhromaChannels
    {
        $match = [];
        if (!\str_starts_with($color, '#') || 1 !== \preg_match(self::HEX, $color, $match)) {
            return null;
        }
        $hex = $match[1];
        $dec = (int) \hexdec($hex);
        $length = \strlen($hex);
        $alpha = 0 === ($length % 4);
        $full = $length > 4;
        $multiplier = $full ? 1 : 17;
        $bits = $full ? 8 : 4;
        $offset = $alpha ? 0 : -1;
        $mask = $full ? 255 : 15;

        return new KhromaChannels([
            'r' => (float) ((($dec >> ($bits * ($offset + 3))) & $mask) * $multiplier),
            'g' => (float) ((($dec >> ($bits * ($offset + 2))) & $mask) * $multiplier),
            'b' => (float) ((($dec >> ($bits * ($offset + 1))) & $mask) * $multiplier),
            'a' => $alpha ? (($dec & $mask) * $multiplier) / 255 : 1.0,
        ], $color);
    }

    private static function rgb(string $color): ?KhromaChannels
    {
        $match = [];
        if (1 !== \preg_match(self::RGB, $color, $match)) {
            return null;
        }

        return new KhromaChannels([
            'r' => KhromaChannels::clamp('r', self::percent($match[1], $match[2], scale: 2.55)),
            'g' => KhromaChannels::clamp('g', self::percent($match[3], $match[4], scale: 2.55)),
            'b' => KhromaChannels::clamp('b', self::percent($match[5], $match[6], scale: 2.55)),
            'a' => self::alpha($match[7] ?? '', $match[8] ?? ''),
        ], $color);
    }

    private static function hsl(string $color): ?KhromaChannels
    {
        $match = [];
        if (1 !== \preg_match(self::HSL, $color, $match)) {
            return null;
        }

        return new KhromaChannels([
            'h' => self::degrees($match[1]),
            's' => KhromaChannels::clamp('s', self::float($match[2])),
            'l' => KhromaChannels::clamp('l', self::float($match[3])),
            'a' => self::alpha($match[4] ?? '', $match[5] ?? ''),
        ], $color);
    }

    private static function percent(string $number, string $percent, float $scale): float
    {
        return '' === $percent ? self::float($number) : self::float($number) * $scale;
    }

    private static function alpha(string $number, string $percent): float
    {
        return '' === $number ? 1.0 : KhromaChannels::clamp('a', self::percent($number, $percent, scale: .01));
    }

    private static function degrees(string $hue): float
    {
        $match = [];
        if (1 === \preg_match('/^(.+?)(deg|grad|rad|turn)$/i', $hue, $match)) {
            // khroma converts only lower-case units; other spellings keep the bare number.
            $number = self::float($match[1]);
            $converted = match ($match[2]) {
                'grad' => $number * .9,
                'rad' => ($number * 180) / \M_PI,
                'turn' => $number * 360,
                default => null,
            };
            if (null !== $converted) {
                return KhromaChannels::clamp('h', $converted);
            }
        }

        return KhromaChannels::clamp('h', self::float($hue));
    }

    private static function keyword(string $color): ?KhromaChannels
    {
        $name = \strtolower($color);
        $hex = self::EXTRA[$name] ?? (\in_array($name, self::MISSING, strict: true) ? null : CssColorNames::NAMES[$name] ?? null);

        return null === $hex ? null : self::hex($hex);
    }

    private static function linear(float $channel): float
    {
        $n = $channel / 255;

        // khroma compares the 0–255 channel with the normalized threshold.
        return $channel > 0.039_28 ? (($n + .055) / 1.055) ** 2.4 : $n / 12.92;
    }

    private static function float(string $digits): float
    {
        // @mago-expect analysis:invalid-type-cast -- The color patterns matched a decimal literal here.
        return (float) $digits;
    }

    private static function integer(float $value): bool
    {
        return \is_finite($value) && \floor($value) === $value;
    }

    private static function tenth(float $value): float
    {
        return self::round($value * 10_000_000_000) / 10_000_000_000;
    }

    private static function number(float $value): string
    {
        return JsNumber::format(self::tenth($value));
    }
}
