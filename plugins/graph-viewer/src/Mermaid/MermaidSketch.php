<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/**
 * The pinned hand-drawn look over drawing marks. Outlines and frames become rough double
 * strokes over hachure in their fill color, and edges become rough lines at the pinned edge
 * roughness of 0.3. Text, arrow tips and label boxes stay crisp, as they do upstream.
 */
final readonly class MermaidSketch
{
    /** Scene units are ten pinned pixels. */
    private const float PIXELS = 10.0;
    /** Pinned `userNodeOverrides`: 1.3px outlines over 1.5px hachure, at roughness 0.7. */
    private const float OUTLINE = 0.13;

    public function __construct(
        private int $seed,
    ) {}

    /**
     * A node outline or frame. A polygon is filled with hachure `$hachure` units wide; an open
     * outline is a rough line.
     *
     * @return list<MermaidMark>
     */
    public function shape(MermaidMark $mark, float $hachure = 0.15): array
    {
        if (!\in_array($mark->kind, ['polygon', 'polyline'], strict: true) || \count($mark->points) < 2) {
            return [$mark];
        }
        $rough = new MermaidRough($this->seed, roughness: 0.7);
        $points = self::pixels($mark->points);
        $closed = 'polygon' === $mark->kind && \count($points) > 2;
        [$fill, $outline] = $closed ? $rough->polygon($points) : [[], $rough->line($points)];
        $marks = self::visible($mark->fill) ? self::strokes($fill, $mark->fill, $hachure, dashed: false, opacity: $mark->fillOpacity * $mark->opacity) : [];
        if ($mark->strokeWidth > 0.0 && self::visible($mark->stroke)) {
            \array_push($marks, ...self::strokes($outline, $mark->stroke, self::OUTLINE, $mark->dashed, $mark->opacity, $mark->dashes));
        }

        return $marks;
    }

    /**
     * An edge, at its own color and width.
     *
     * @return list<MermaidMark>
     */
    public function edge(MermaidMark $mark): array
    {
        $line = new MermaidRough($this->seed, roughness: 0.3)->line(self::pixels($mark->points));

        return self::strokes($line, $mark->stroke, $mark->strokeWidth, $mark->dashed, $mark->opacity, $mark->dashes);
    }

    /**
     * @param list<list<float>> $operations
     * @param ?list<float> $dashes
     * @return list<MermaidMark>
     */
    private static function strokes(array $operations, string $color, float $width, bool $dashed, float $opacity, ?array $dashes = null): array
    {
        return \array_map(
            static fn (array $points): MermaidMark => new MermaidMark('polyline', $points, stroke: $color, dashed: $dashed, strokeWidth: $width, opacity: $opacity, dashes: $dashes),
            MermaidRough::polylines($operations, self::PIXELS),
        );
    }

    /**
     * @param list<array{float, float}> $points
     * @return list<array{float, float}>
     */
    private static function pixels(array $points): array
    {
        return \array_map(static fn (array $point): array => [$point[0] * self::PIXELS, $point[1] * self::PIXELS], $points);
    }

    private static function visible(string $color): bool
    {
        return 'none' !== $color && 'transparent' !== $color && 1 !== \preg_match('/^#[0-9a-f]{6}00$/i', $color);
    }
}
