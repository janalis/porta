<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Baseline-aware expression boxes reserve separate tracks for branches and loops. */
final readonly class RailroadMeasure
{
    /** @param list<self> $children */
    public function __construct(
        public RailroadExpression $expression,
        public float $width,
        public float $height,
        public float $baseline,
        public array $children = [],
        public string $label = '',
    ) {}

    /** @return \Generator<int, null, mixed, self> */
    public static function expression(RailroadExpression $expression): \Generator
    {
        $children = [];
        foreach ($expression->children as $child) {
            $work = self::expression($child);
            yield from $work;
            $children[] = $work->getReturn();
        }
        if ([] === $children) {
            $wrap = MermaidWrap::text(MermaidText::decode($expression->label), width: 30);
            yield from $wrap;
            $label = $wrap->getReturn();
            [$width, $height] = MermaidText::box($label);

            return new self($expression, \max(6.0, $width), \max(4.0, $height), \max(4.0, $height) / 2, label: $label);
        }
        $widths = \array_map(static fn (self $child): float => $child->width, $children);
        $heights = \array_map(static fn (self $child): float => $child->height, $children);
        $baseline = \max(\array_map(static fn (self $child): float => $child->baseline, $children));
        $descent = \max(\array_map(static fn (self $child): float => $child->height - $child->baseline, $children));
        yield null;

        return match ($expression->type) {
            'sequence' => new self($expression, \array_sum($widths) + (4 * (\count($children) - 1)), $baseline + $descent, $baseline, $children),
            'choice' => new self($expression, \max($widths) + 12, \array_sum($heights) + (4 * (\count($children) - 1)), $children[0]->baseline, $children),
            'optional' => new self($expression, $widths[0] + 12, $heights[0] + 6, $children[0]->baseline + 6, $children),
            default => new self(
                $expression,
                \max($widths[0] + 12, self::countWidth($expression)),
                $heights[0] + (0 === $expression->min ? 12 : 6),
                $children[0]->baseline + (0 === $expression->min ? 6 : 0),
                $children,
            ),
        };
    }

    public static function countLabel(RailroadExpression $expression): string
    {
        return match (true) {
            null === $expression->max && $expression->min <= 1 => '',
            $expression->min === $expression->max => $expression->min.' times',
            default => $expression->min.'..'.($expression->max ?? 'many').' times',
        };
    }

    private static function countWidth(RailroadExpression $expression): float
    {
        return (float) \mb_strlen(self::countLabel($expression)) + 8;
    }
}
