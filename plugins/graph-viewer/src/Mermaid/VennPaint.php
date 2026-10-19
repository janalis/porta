<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Typed paint keeps CSS alpha separate from terminal-safe six-digit colors. */
final readonly class VennPaint
{
    public function __construct(
        public string $fill = 'none',
        public ?string $stroke = 'none',
        public string $color = '#263238',
        public float $opacity = 1.0,
        public float $width = 0.25,
        public float $fillAlpha = 1.0,
        public float $strokeAlpha = 1.0,
        public float $colorAlpha = 1.0,
        public float $strokeOpacity = 1.0,
    ) {}

    public static function circle(string $color): self
    {
        return new self($color, stroke: null, opacity: 0.1, strokeOpacity: 0.95);
    }

    public function strokeColor(): string
    {
        return $this->stroke ?? $this->fill;
    }

    public function strokeAlpha(): float
    {
        return $this->strokeOpacity * (null === $this->stroke ? $this->fillAlpha : $this->strokeAlpha);
    }

    public function property(string $key, string $value, MermaidStatement $statement): self
    {
        return match ($key) {
            'fill', 'stroke', 'color' => $this->color($key, $value, $statement),
            'stroke-width' => clone($this, ['width' => self::width($value, $statement)]),
            'fill-opacity' => clone($this, ['opacity' => self::opacity($value, $statement)]),
            default => throw $statement->error('Unsupported Venn style property '.$key),
        };
    }

    private function color(string $key, string $value, MermaidStatement $statement): self
    {
        $color = VennColor::parse($key, $value, $statement);

        return match ($key) {
            'fill' => clone($this, ['fill' => $color['color'], 'fillAlpha' => $color['alpha']]),
            'stroke' => clone($this, ['stroke' => $color['color'], 'strokeAlpha' => $color['alpha']]),
            default => clone($this, ['color' => $color['color'], 'colorAlpha' => $color['alpha']]),
        };
    }

    private static function width(string $value, MermaidStatement $statement): float
    {
        $value = \preg_replace('/\s+(px|pt)$/i', replacement: '$1', subject: $value) ?? $value;
        $css = new MermaidCss('Venn')->parse('stroke-width:'.$value, $statement);

        /** @var float $width Closed CSS normalization produces a numeric width. */
        // @mago-expect lint:inline-variable-return -- local annotation narrows the CSS union.
        // @noinspection OneTimeUseVariablesInspection -- local annotation narrows the CSS union.
        $width = $css['stroke-width'];

        return $width;
    }

    private static function opacity(string $value, MermaidStatement $statement): float
    {
        if (!\is_numeric($value) || (float) $value < 0 || (float) $value > 1 || !\is_finite((float) $value)) {
            throw $statement->error('Venn fill opacity must be a finite number from zero to one');
        }

        return (float) $value;
    }
}
