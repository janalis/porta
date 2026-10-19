<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** The category axis changes sides in horizontal charts; the value axis retains its numeric domain. */
final readonly class XyGeometry
{
    /** @var ?list<string> */
    public ?array $categories;
    /** @var array{float, float} */
    public array $xRange;
    /** @var array{float, float} */
    public array $yRange;

    public function __construct(
        public XyDiagram $source,
        public float $left,
        public float $top,
        public float $width = 140.0,
        public float $height = 90.0,
    ) {
        $this->categories = null === $source->categories ? null : \array_values(\array_unique($source->categories));
        $this->xRange = $source->xRange ?? [1.0, 1.0];
        $this->yRange = $source->yRange ?? [0.0, 1.0];
    }

    /** @return array{string, float} */
    public function category(int $index, int $count): array
    {
        $categories = $this->source->categories;
        if (null !== $categories && null !== $this->categories) {
            $label = $categories[$index];
            $slot = \array_search($label, $this->categories, strict: true);
            \assert(false !== $slot, description: 'every authored category has a band position');

            return [$label, ($slot + 0.5) / \count($this->categories)];
        }
        $fraction = $count > 1 ? $index / ($count - 1) : 0.0;
        $value = XyScale::value($fraction, $this->xRange);

        return [(string) $value, XyScale::fraction($value, $this->xRange)];
    }

    /** @return array{float, float} */
    public function position(float $category, float $value): array
    {
        if ('horizontal' === $this->source->orientation) {
            $category = null === $this->categories ? 1 - $category : $category;

            return [$this->left + ($value * $this->width), $this->top + ((0.05 + (0.9 * $category)) * $this->height)];
        }

        return [$this->left + ((0.05 + (0.9 * $category)) * $this->width), $this->top + ((1 - $value) * $this->height)];
    }

    public function thickness(int $count): float
    {
        $extent = 'horizontal' === $this->source->orientation ? $this->height : $this->width;
        $slots = null === $this->categories ? $count : \count($this->categories);

        return \min(36.0, (0.7 * $extent) / \max(1, $slots));
    }
}
