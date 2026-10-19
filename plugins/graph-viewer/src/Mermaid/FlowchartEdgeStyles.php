<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;

/** Declaration-indexed styles override defaults; repeated declarations replace prior properties. */
final class FlowchartEdgeStyles
{
    /** @var array<array-key, array<string, float|string|bool>> */
    private array $styles = [];
    /** @var array<int, string> */
    private array $names = [];
    /** @var array<array-key, string> */
    private array $curves = [];
    private int $count = 0;
    private int $assignments = 0;

    public function __construct(
        private readonly MermaidNodeStyles $classes = new MermaidNodeStyles('flowchart'),
    ) {}

    public function identify(int $index, string $id): void
    {
        $this->names[$index] = $id;
    }

    /** @return array<string, float|string|bool> */
    private function style(int $index): array
    {
        $named = \array_key_exists($index, $this->names) ? $this->classes->forElement($this->names[$index]) : [];

        return \array_replace($this->styles['default'] ?? [], $named, $this->styles[$index] ?? []);
    }

    public function accept(MermaidStatement $statement, int $edges): void
    {
        if (++$this->count > MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the flowchart edge style limit');
        }
        $match = [];
        if (1 !== \preg_match('/^linkStyle\s+((?:default|\d+)(?:\s*,\s*(?:default|\d+))*)\s+(?:interpolate\s+([\w-]+)(?:\s+(.+))?|(.+))$/s', $statement->text, $match)) {
            throw $statement->error('Expected flowchart linkStyle indices or default followed by CSS');
        }
        $css = ($match[3] ?? '').($match[4] ?? '');
        $style = '' === $css ? [] : new MermaidCss('flowchart edge')->parse($css, $statement);
        if (\array_key_exists('fill', $style) && 'none' !== $style['fill']) {
            throw $statement->error('Flowchart edge fill requires the Mermaid CLI');
        }
        $targets = \array_map(\trim(...), \explode(',', $match[1]));
        $this->assignments += \count($targets);
        if ($this->assignments > 65_536) {
            throw $statement->error('Diagram exceeds the flowchart edge style-assignment limit');
        }
        foreach ($targets as $target) {
            $index = 'default' === $target ? 'default' : (int) $target;
            if ('default' !== $index && $index >= $edges) {
                throw $statement->error('Flowchart linkStyle index is out of bounds');
            }
            if ('' !== $css) {
                $this->styles[$index] = $style;
            }
            if ('' !== $match[2]) {
                $this->curves[$index] = $match[2];
            }
        }
    }

    /** Edge metadata `id@{curve: …}` replaces the edge's interpolation like an indexed linkStyle. */
    public function interpolate(int $index, string $curve): void
    {
        if ('' !== $curve) {
            $this->curves[$index] = $curve;
        }
    }

    /** Pinned order: the edge's own interpolation, then linkStyle default, then `flowchart.curve`. */
    public function curve(int $index, ?string $configured): MermaidCurve
    {
        return MermaidCurve::resolve($this->curves[$index] ?? $this->curves['default'] ?? $configured);
    }

    /** @return array{float, float} */
    public function measure(int $index, string $label): array
    {
        $style = $this->style($index);
        $size = $style['font-size'] ?? 16.0;

        return MermaidText::box($label, \is_float($size) ? $size / 16 : 1.0);
    }

    public function decorate(GraphEdge $edge, int $index): GraphEdge
    {
        $style = $this->style($index);
        if ([] === $style) {
            return $edge;
        }
        $stroke = $style['stroke'] ?? $edge->color;
        $color = $style['color'] ?? $edge->textColor;
        $width = $style['stroke-width'] ?? $edge->strokeWidth;
        $size = $style['font-size'] ?? null;
        [, $stroke, $color] = MermaidCss::faded($style, null, \is_string($stroke) ? $stroke : $edge->color, \is_string($color) ? $color : $edge->textColor);

        return new GraphEdge(
            $edge->tail,
            $edge->head,
            $edge->points,
            $edge->arrowTip,
            $edge->minX,
            $edge->minY,
            $edge->maxX,
            $edge->maxY,
            $stroke,
            $edge->label,
            $edge->labelPos,
            dashed: true === ($style['stroke-dasharray'] ?? $edge->dashed),
            ribbon: $edge->ribbon,
            strokeWidth: \is_float($width) ? $width : $edge->strokeWidth,
            textColor: $color,
            decoration: $edge->decoration,
            font: $edge->font->overlay($style),
            fontScale: \is_float($size) ? $size / 16 : $edge->fontScale,
            gradient: $edge->gradient,
            dashes: MermaidCss::dashes($style, $edge->dashes),
        );
    }
}
