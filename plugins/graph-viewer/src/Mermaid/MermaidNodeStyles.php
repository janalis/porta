<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphNode;

/** Supported node CSS and forward class assignments, kept separate from diagram structure. */
final class MermaidNodeStyles
{
    public const string IDS = '[\p{L}\p{N}_*.-]+(?:[\t ]*,[\t ]*[\p{L}\p{N}_*.-]+)*';
    private const int MAX_ASSIGNMENTS = 65_536;

    public function __construct(
        private readonly string $family,
    ) {}

    /** @var array<array-key, array<string, float|string|bool>> */
    private array $definitions = [];
    /** @var array<array-key, list<string>> */
    private array $classes = [];
    /** @var array<array-key, array<string, float|string|bool>> */
    private array $inline = [];
    private int $count = 0;
    private int $assignments = 0;

    public function accept(MermaidStatement $statement): void
    {
        if (++$this->count > MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the '.$this->family.' style limit');
        }
        $text = \trim(\preg_replace('/%%[^\r\n]*/', replacement: '', subject: $statement->text) ?? $statement->text);
        $match = [];
        if (1 === \preg_match('~^(classDef|style)\s+('.self::IDS.')\s+(.+)$~isu', $text, $match)) {
            $styles = new MermaidCss($this->family)->parse($match[3], $statement);
            foreach (self::ids($match[2]) as $id) {
                if ('classdef' === \strtolower($match[1])) {
                    $this->definitions[$id] = \array_replace($this->definitions[$id] ?? [], $styles);
                    continue;
                }
                $this->inline[$id] = \array_replace($this->inline[$id] ?? [], $styles);
            }

            return;
        }
        if (1 !== \preg_match('~^(?:class\s+('.self::IDS.')\s+('.self::IDS.')|('.self::IDS.')\s*:::\s*('.self::IDS.'))\s*;?$~iu', $text, $match)) {
            throw $statement->error('Expected '.$this->family.' style or class assignment');
        }
        $ids = '' !== $match[1] ? $match[1] : $match[3];
        $classes = '' !== $match[2] ? $match[2] : $match[4];
        foreach (self::ids($ids) as $id) {
            $this->assign($id, $classes, $statement);
        }
    }

    /**
     * A family's configured text size, laid under the authored `default` class: `size` pixels
     * against the family's pinned `default`, as the 16-pixel CSS size native text scales from.
     */
    public function seed(MermaidOptions $options, string $family, float $default): void
    {
        $size = MermaidFonts::pixels($options->value($family, 'fontSize'));
        if (null !== $size && $size > 0) {
            $this->definitions['default'] = \array_replace(['font-size' => (16 * $size) / $default], $this->definitions['default'] ?? []);
        }
    }

    public function assign(string $id, string $classes, MermaidStatement $statement): void
    {
        $names = self::ids($classes);
        $this->assignments += \count($names);
        if ($this->assignments > self::MAX_ASSIGNMENTS) {
            throw $statement->error('Diagram exceeds the '.$this->family.' class-assignment limit');
        }
        $this->classes[$id] = [...($this->classes[$id] ?? []), ...$names];
    }

    /** @return array<string, float|string|bool> */
    public function forNode(string $id): array
    {
        return \array_replace($this->definitions['default'] ?? [], $this->forElement($id));
    }

    /** @return array<string, float|string|bool> */
    public function forElement(string $id): array
    {
        $style = [];
        foreach ($this->classes[$id] ?? [] as $class) {
            foreach ($this->definitions[$class] ?? [] as $key => $value) {
                $style[$key] = $value;
            }
        }

        return \array_replace($style, $this->inline[$id] ?? []);
    }

    /** @return list<string> */
    private static function ids(string $text): array
    {
        return \array_map(\trim(...), \explode(',', $text));
    }

    /** @return array{float, float} */
    public function measure(string $id, string $label): array
    {
        return self::box($label, $this->forNode($id));
    }

    /** @return array{float, float} */
    public function measureCluster(string $id, string $label): array
    {
        [$width, $height] = self::box($label, $this->forElement($id));

        return [$width, $height - 2];
    }

    /** @param array<string, float|string|bool> $style
     * @return array{float, float}
     */
    private static function box(string $label, array $style): array
    {
        $size = $style['font-size'] ?? 16.0;

        return MermaidText::box($label, \is_float($size) ? $size / 16 : 1.0);
    }

    /** @param array<string, float|string|bool> $defaults */
    public function decorate(GraphNode $node, array $defaults = []): GraphNode
    {
        $style = \array_replace($defaults, $this->forNode($node->name));
        $fill = $style['fill'] ?? $node->fillColor;
        $stroke = $style['stroke'] ?? $node->color;
        $text = $style['color'] ?? $node->textColor;
        $width = $style['stroke-width'] ?? $node->strokeWidth;
        $size = $style['font-size'] ?? null;
        [$fill, $stroke, $text] = MermaidCss::faded($style, \is_string($fill) ? $fill : $node->fillColor, \is_string($stroke) ? $stroke : $node->color, \is_string($text) ? $text : $node->textColor);

        return new GraphNode(
            $node->id,
            $node->name,
            $node->label,
            $node->x,
            $node->y,
            $node->w,
            $node->h,
            $stroke,
            $fill,
            dashed: true === ($style['stroke-dasharray'] ?? $node->dashed),
            background: $node->background,
            strokeWidth: \is_float($width) ? $width : $node->strokeWidth,
            drawLabel: $node->drawLabel,
            textColor: $text,
            font: $node->font->overlay($style),
            fontScale: \is_float($size) ? $size / 16 : $node->fontScale,
            opacity: $node->opacity,
            dashes: MermaidCss::dashes($style, $node->dashes),
        );
    }

    /** Subgraphs use authored classes and styles, without the implicit node default. */
    public function decorateCluster(GraphCluster $cluster): GraphCluster
    {
        $style = $this->forElement($cluster->name);
        $size = $style['font-size'] ?? null;
        [$fill, $stroke, $text] = MermaidCss::faded(
            $style,
            \is_string($style['fill'] ?? null) ? $style['fill'] : $cluster->fillColor,
            \is_string($style['stroke'] ?? null) ? $style['stroke'] : $cluster->color,
            \is_string($style['color'] ?? null) ? $style['color'] : $cluster->textColor,
        );

        return new GraphCluster(
            $cluster->name,
            $cluster->label,
            $cluster->x0,
            $cluster->y0,
            $cluster->x1,
            $cluster->y1,
            $stroke,
            $cluster->labelPos,
            fillColor: $fill,
            textColor: $text,
            strokeWidth: \is_float($style['stroke-width'] ?? null) ? $style['stroke-width'] : $cluster->strokeWidth,
            dashed: true === ($style['stroke-dasharray'] ?? $cluster->dashed),
            font: $cluster->font->overlay($style),
            fontScale: \is_float($size) ? $size / 16 : $cluster->fontScale,
            dashes: MermaidCss::dashes($style, $cluster->dashes),
        );
    }
}
