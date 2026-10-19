<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Bounded native CSS values shared by node and edge styling. */
final readonly class MermaidCss
{
    public function __construct(
        private string $family,
    ) {}

    /** @return array<string, float|string|bool> */
    public function parse(string $text, MermaidStatement $statement): array
    {
        $styles = [];
        $properties = \preg_split('/(?<!\\\\),/', \rtrim(\trim($text), characters: ';'));
        \assert(false !== $properties, description: 'the CSS delimiter pattern is valid');
        foreach ($properties as $property) {
            $match = [];
            if (1 !== \preg_match('/^\s*([\w-]+)\s*:\s*(.+?)\s*$/', $property, $match)) {
                throw $statement->error('Expected '.$this->family.' style property: value');
            }
            [$all, $key, $value] = $match;
            $key = match (\strtolower($key)) {
                'background', 'background-color' => 'fill',
                'border-color' => 'stroke',
                'border-width' => 'stroke-width',
                default => \strtolower($key),
            };
            if ('stroke-dasharray' === $key) {
                $styles['dash-pattern'] = \preg_replace('/[\s,\\\\]+/', replacement: ' ', subject: \trim($value)) ?? $value;
            }
            if ('border' === $key) {
                $styles = \array_replace($styles, $this->border($value, $statement));
                continue;
            }
            $styles[$key] = match ($key) {
                'fill', 'stroke', 'color' => $this->color($key, $value, $statement),
                'font-size' => $this->fontSize($value, $statement),
                'stroke-width' => $this->width($value, $statement),
                'stroke-dasharray' => $this->dash($value, $statement),
                'font-style' => match (\strtolower($value)) {
                    'normal' => false,
                    'italic', 'oblique' => true,
                    default => throw $statement->error('Invalid '.$this->family.' font style'),
                },
                'font-weight' => $this->weight($value, $statement),
                'opacity', 'fill-opacity', 'stroke-opacity' => $this->opacity($value, $statement),
                default => throw $statement->error('Unsupported '.$this->family.' CSS property '.$key),
            };
        }

        return $styles;
    }

    /**
     * A style's authored dash lengths in units, ten pixels each: an odd list repeats, as SVG
     * does, a style without a pattern keeps `$current`, and `none` or a malformed one is null.
     *
     * @param array<string, float|string|bool> $style
     * @param ?list<float> $current
     * @return ?list<float>
     */
    public static function dashes(array $style, ?array $current): ?array
    {
        $pattern = $style['dash-pattern'] ?? null;
        if (!\is_string($pattern)) {
            return $current;
        }
        // @mago-expect analysis:invalid-type-cast -- the pattern admits only decimal lengths
        $lengths = 1 === \preg_match('/^\d+(?:\.\d+)?(?: \d+(?:\.\d+)?)*$/', $pattern) ? \array_map(static fn (string $length): float => (float) $length / 10, \explode(' ', $pattern)) : [];

        return [] === $lengths ? null : [...$lengths, ...(0 === (\count($lengths) % 2) ? [] : $lengths)];
    }

    /** @return array<string, float|string|bool> */
    private function border(string $value, MermaidStatement $statement): array
    {
        if ('none' === \strtolower($value)) {
            return ['stroke' => 'none'];
        }
        $match = [];
        if (1 !== \preg_match('/^(\d+(?:\.\d+)?(?:px)?)\s+(solid|dashed|dotted)\s+(.+)$/i', $value, $match)) {
            throw $statement->error('Invalid '.$this->family.' border shorthand');
        }

        return [
            'stroke-width' => $this->width($match[1], $statement),
            'stroke-dasharray' => 'solid' !== \strtolower($match[2]),
            'dash-pattern' => '',
            'stroke' => $this->color('stroke', $match[3], $statement),
        ];
    }

    /** A browser clamps an opacity, a number or a percentage, to the unit range. */
    private function opacity(string $value, MermaidStatement $statement): float
    {
        $match = [];
        if (1 !== \preg_match('/^(\d*\.?\d+)(%?)$/', $value, $match)) {
            throw $statement->error('Invalid '.$this->family.' opacity');
        }

        // @mago-expect analysis:invalid-type-cast -- the preceding pattern accepts only a decimal number
        return \min(1.0, (float) $match[1] / ('%' === $match[2] ? 100 : 1));
    }

    /**
     * A style's colors under its opacities: the fill under `fill-opacity`, the stroke under
     * `stroke-opacity`, and every one under `opacity`.
     *
     * @param array<string, float|string|bool> $style
     * @return array{?string, ?string, ?string} the fill, stroke and text colors
     */
    public static function faded(array $style, ?string $fill, ?string $stroke, ?string $text): array
    {
        $share = static fn (string $key): float => \is_float($style[$key] ?? null) ? $style[$key] : 1.0;
        $opacity = $share('opacity');

        return [
            MermaidColor::faded($fill, $share('fill-opacity') * $opacity),
            MermaidColor::faded($stroke, $share('stroke-opacity') * $opacity),
            MermaidColor::faded($text, $opacity),
        ];
    }

    private function weight(string $value, MermaidStatement $statement): bool
    {
        if (1 === \preg_match('/^[1-9]00$/', $value)) {
            return (int) $value >= 600;
        }

        return match (\strtolower($value)) {
            'normal' => false,
            'bold' => true,
            default => throw $statement->error('Invalid '.$this->family.' font weight'),
        };
    }

    private function color(string $key, string $value, MermaidStatement $statement): string
    {
        // A functional color escapes its commas, as list values do.
        return MermaidColor::parse($key, \str_replace('\\,', replace: ',', subject: $value), $statement, $this->family);
    }

    private function fontSize(string $value, MermaidStatement $statement): float
    {
        $match = [];
        if (1 !== \preg_match('/^(\d+(?:\.\d+)?)(?:px)?$/', $value, $match)) {
            throw $statement->error('Font size must be 1 to 1000 pixels');
        }
        // @mago-expect analysis:invalid-type-cast -- the preceding pattern accepts only a decimal number
        $size = (float) $match[1];
        if ($size < 1 || $size > 1000) {
            throw $statement->error('Font size must be 1 to 1000 pixels');
        }

        return $size;
    }

    private function width(string $value, MermaidStatement $statement): float
    {
        $match = [];
        if (1 !== \preg_match('/^(\d+(?:\.\d+)?)(?:px)?$/', $value, $match)) {
            throw $statement->error(\ucfirst($this->family).' stroke width must be 0 to 1000 pixels');
        }
        // @mago-expect analysis:invalid-type-cast -- the preceding regex accepts only a decimal number
        $number = (float) $match[1];
        if ($number > 1000) {
            throw $statement->error(\ucfirst($this->family).' stroke width must be 0 to 1000 pixels');
        }

        return $number / 10;
    }

    private function dash(string $value, MermaidStatement $statement): bool
    {
        if ('none' === \strtolower($value)) {
            return false;
        }
        $value = \str_replace('\\,', replace: ',', subject: $value);
        if (1 !== \preg_match('/^\d+(?:\.\d+)?(?:(?:\s*,\s*|\s+)\d+(?:\.\d+)?)*$/', $value)) {
            throw $statement->error(\ucfirst($this->family).' dash lengths must be non-negative');
        }

        return 1 === \preg_match('/[1-9]/', $value);
    }
}
