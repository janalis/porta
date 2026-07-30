<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Graph;

/**
 * Normalizes Graphviz color attribute values to `#rrggbb` for direct SGR
 * truecolor rendering. Unknown or exotic values resolve to null so the theme
 * default applies — a wrong guess would recolor someone's graph, a null never
 * does.
 */
final class DotColor
{
    /** The X11 names dot documents actually use; anything absent falls back to the theme. */
    private const array NAMES = [
        'black' => '#000000',
        'white' => '#ffffff',
        'red' => '#ff0000',
        'green' => '#00ff00',
        'blue' => '#0000ff',
        'yellow' => '#ffff00',
        'cyan' => '#00ffff',
        'magenta' => '#ff00ff',
        'gray' => '#bebebe',
        'grey' => '#bebebe',
        'lightgray' => '#d3d3d3',
        'lightgrey' => '#d3d3d3',
        'darkgray' => '#a9a9a9',
        'darkgrey' => '#a9a9a9',
        'lightblue' => '#add8e6',
        'lightgreen' => '#90ee90',
        'lightyellow' => '#ffffe0',
        'orange' => '#ffa500',
        'purple' => '#a020f0',
        'brown' => '#a52a2a',
        'pink' => '#ffc0cb',
        'navy' => '#000080',
        'teal' => '#008080',
        'maroon' => '#b03060',
        'olive' => '#808000',
        'gold' => '#ffd700',
        'salmon' => '#fa8072',
        'crimson' => '#dc143c',
    ];

    public static function normalize(?string $value): ?string
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }
        // A color list ("c1;0.5:c2") means gradient — take the first entry, weight stripped.
        $first = explode(';', explode(':', trim($value))[0])[0];
        $color = strtolower(trim($first));
        if (str_starts_with($color, '#')) {
            return self::hex(substr($color, offset: 1));
        }
        $hsv = [];
        if (1 === preg_match('/^([0-9]*+\.?[0-9]+)[,\s]+([0-9]*+\.?[0-9]+)[,\s]+([0-9]*+\.?[0-9]+)$/', $color, $hsv) && is_numeric($hsv[1]) && is_numeric($hsv[2]) && is_numeric($hsv[3])) {
            return self::fromHsv((float) $hsv[1], (float) $hsv[2], (float) $hsv[3]);
        }

        return self::NAMES[$color] ?? null;
    }

    private static function hex(string $digits): ?string
    {
        if (1 !== preg_match('/^[0-9a-f]+$/', $digits)) {
            return null;
        }

        return match (\strlen($digits)) {
            3 => \sprintf('#%1$s%1$s%2$s%2$s%3$s%3$s', $digits[0], $digits[1], $digits[2]),
            6 => '#'.$digits,
            8 => '#'.substr($digits, offset: 0, length: 6), // alpha stripped
            default => null,
        };
    }

    /** Graphviz HSV triples are 0..1 floats. */
    private static function fromHsv(float $h, float $s, float $v): string
    {
        $i = (int) floor($h * 6.0) % 6;
        $f = ($h * 6.0) - floor($h * 6.0);
        $p = $v * (1.0 - $s);
        $q = $v * (1.0 - ($f * $s));
        $t = $v * (1.0 - ((1.0 - $f) * $s));
        [$r, $g, $b] = match ($i) {
            0 => [$v, $t, $p],
            1 => [$q, $v, $p],
            2 => [$p, $v, $t],
            3 => [$p, $q, $v],
            4 => [$t, $p, $v],
            default => [$v, $p, $q],
        };

        return \sprintf('#%02x%02x%02x', (int) round($r * 255), (int) round($g * 255), (int) round($b * 255));
    }
}
