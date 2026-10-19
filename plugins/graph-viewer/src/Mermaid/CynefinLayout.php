<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/**
 * Expanding quadrants meet in one field around a central confusion ellipse, as pinned, with
 * its seeded folds and cliff; no authored item is hidden.
 */
final readonly class CynefinLayout
{
    /** Pinned domain models and practices, the subtitles `showDomainDescriptions` adds. */
    private const array DESCRIPTIONS = [
        'complex' => "Probe → Sense → Respond\nEmergent Practices",
        'complicated' => "Sense → Analyse → Respond\nGood Practices",
        'chaotic' => "Act → Sense → Respond\nNovel Practices",
        'clear' => "Sense → Categorise → Respond\nBest Practices",
        'confusion' => 'Disorder',
    ];

    private MermaidOptions $options;

    public function __construct(?MermaidOptions $options = null)
    {
        $this->options = $options ?? MermaidOptions::defaults();
    }

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(CynefinDiagram $source): \Generator
    {
        $nodes = [];
        $shapes = [];
        $origins = [];
        $legend = [];
        $top = 2.0;
        foreach ($source->metadata as $name => $item) {
            $caption = self::caption($nodes, $shapes, $origins, $item['label'], x: 110.0, top: $top, origin: $item['origin']);
            yield from $caption;
            $top += $caption->getReturn()->h + 2;
            $legend[] = ['text' => $name.': '.$item['label'], 'origin' => $item['origin']];
        }
        $measure = self::measure($source);
        yield from $measure;
        [$items, $heights] = $measure->getReturn();
        $positions = self::positions($heights, $top);
        $headers = [];
        $elements = [];
        foreach (CynefinDiagram::DOMAINS as $domain) {
            $position = $positions[$domain];
            $id = \count($nodes);
            $origin = $source->domains[$domain]['origin'] ?? $source->origin;
            $nodes[$id] = new GraphNode(
                $id,
                $domain,
                \ucfirst($domain),
                $position['x'],
                $position['content'] + 3,
                80.0,
                6.0,
                null,
                null,
                dashed: false,
                textColor: '@cynefin.labelColor',
                font: GraphFont::Bold,
            );
            $shapes[$id] = 'text';
            $headers[$domain] = $nodes[$id];
            $origins[$id] = $origin;
            $elements[$id] = $origin;
            $legend[] = ['text' => \ucfirst($domain), 'origin' => $origin];
            yield null;
        }
        $domains = self::domains($source, $positions, $items, $nodes, $shapes, $origins, $elements, $legend, $this->options->flag('cynefin', 'showDomainDescriptions'));
        yield from $domains;
        $bottom = $positions['clear']['top'] + $positions['clear']['h'];
        $footer = $bottom + 6;
        $edges = CynefinBoundaries::edges(
            2.0,
            $positions['complex']['top'],
            218.0,
            $bottom,
            110.0,
            $positions['confusion']['top'] + ($positions['confusion']['h'] / 2),
            CynefinBoundaries::seed($this->options->number('cynefin', 'seed', fallback: 0.0)),
            $this->options->number('cynefin', 'boundaryAmplitude', fallback: 8.0),
        );
        foreach ($source->transitions as $transition) {
            $summary = \ucfirst($transition['from']).' → '.\ucfirst($transition['to']).('' === $transition['label'] ? '' : ': '.$transition['label']);
            $caption = self::caption($nodes, $shapes, $origins, $summary, x: 110.0, top: $footer, origin: $transition['origin']);
            yield from $caption;
            $footer += $caption->getReturn()->h + 2;
            $edges[] = CynefinRoutes::edge($headers[$transition['from']], $headers[$transition['to']], $footer);
            $legend[] = ['text' => $summary, 'origin' => $transition['origin']];
            $footer += 4;
            yield null;
        }
        $rows = self::rows($nodes, $origins, $source->origin, $footer);
        yield from $rows;

        return new MermaidScene(new GraphLayout(220.0, $footer + 2, $nodes, $edges, clusters: []), $rows->getReturn(), $legend, $elements, $shapes);
    }

    /** @return \Generator<int, null, mixed, array{array<string, list<array{label: string, draw: string, h: float, origin: int}>>, array<string, float>}> */
    private static function measure(CynefinDiagram $source): \Generator
    {
        $items = [];
        $heights = [];
        foreach (CynefinDiagram::DOMAINS as $domain) {
            $items[$domain] = [];
            $heights[$domain] = 24.0;
            foreach ($source->domains[$domain]['items'] ?? [] as $item) {
                $wrap = MermaidWrap::text($item['label'], width: 32);
                yield from $wrap;
                $draw = $wrap->getReturn();
                [, $h] = MermaidText::box($draw);
                $items[$domain][] = [...$item, 'draw' => $draw, 'h' => $h];
                $heights[$domain] += $h + 2;
                yield null;
            }
        }

        return [$items, $heights];
    }

    /** @param array<string, float> $heights
     * @return array<string, array{x: float, top: float, h: float, content: float}>
     */
    private static function positions(array $heights, float $top): array
    {
        $upper = \max($heights['complex'], $heights['complicated']);
        $lower = \max($heights['chaotic'], $heights['clear']);
        $center = 2 * ($heights['confusion'] + 8);
        $middle = $top + $upper + 4;
        $bottom = $middle + $center + 4;

        return [
            'complex' => ['x' => 56.0, 'top' => $top, 'h' => $upper, 'content' => $top + 2],
            'complicated' => ['x' => 164.0, 'top' => $top, 'h' => $upper, 'content' => $top + 2],
            'chaotic' => ['x' => 56.0, 'top' => $bottom, 'h' => $lower, 'content' => $bottom + 2],
            'clear' => ['x' => 164.0, 'top' => $bottom, 'h' => $lower, 'content' => $bottom + 2],
            'confusion' => ['x' => 110.0, 'top' => $middle, 'h' => $center, 'content' => $middle + (($center - $heights['confusion']) / 2)],
        ];
    }

    /** @param array<string, array{x: float, top: float, h: float, content: float}> $positions
     * @param array<string, list<array{label: string, draw: string, h: float, origin: int}>> $items
     * @param array<int, GraphNode> $nodes
     * @param array<int, string> $shapes
     * @param array<int, int> $origins
     * @param array<int, int> $elements
     * @param list<array{text: string, origin: int}> $legend
     * @return \Generator<int, null, mixed, null>
     */
    // @mago-expect lint:no-boolean-flag-parameter -- Pinned `showDomainDescriptions` only adds or omits the subtitles.
    private static function domains(
        CynefinDiagram $source,
        array $positions,
        array $items,
        array &$nodes,
        array &$shapes,
        array &$origins,
        array &$elements,
        array &$legend,
        bool $descriptions,
    ): \Generator {
        $cross = $positions['confusion']['top'] + ($positions['confusion']['h'] / 2);
        $bottom = $positions['clear']['top'] + $positions['clear']['h'];
        foreach (CynefinDiagram::DOMAINS as $domain) {
            $position = $positions[$domain];
            $origin = $source->domains[$domain]['origin'] ?? $source->origin;
            $id = \count($nodes);
            // Pinned quadrants meet at the fold, unstroked at 40%; confusion is a dashed ellipse at 50%.
            [$y0, $y1] = match ($domain) {
                'complex', 'complicated' => [$position['top'], $cross],
                'chaotic', 'clear' => [$cross, $bottom],
                default => [$position['top'], $position['top'] + $position['h']],
            };
            $confusion = 'confusion' === $domain;
            $nodes[$id] = new GraphNode(
                $id,
                'region-'.$domain,
                '',
                $position['x'],
                ($y0 + $y1) / 2,
                $confusion ? 104.0 : 108.0,
                $y1 - $y0,
                $confusion ? '@cynefin.boundaryColor' : 'none',
                '@cynefin.'.$domain.'Bg@'.($confusion ? 50 : 40),
                dashed: $confusion,
                background: true,
                strokeWidth: 0.15,
                dashes: $confusion ? [0.4, 0.2] : null,
            );
            $shapes[$id] = $confusion ? 'ellipse' : 'rect';
            $origins[$id] = $origin;
            if ($descriptions) {
                $caption = self::caption($nodes, $shapes, $origins, self::DESCRIPTIONS[$domain], $position['x'], $position['content'] + 6, $origin, GraphFont::Italic);
                yield from $caption;
            }
            $top = $position['content'] + 20;
            foreach ($items[$domain] as $item) {
                $id = \count($nodes);
                $nodes[$id] = new GraphNode(
                    $id,
                    $domain.'-'.$id,
                    \ucfirst($domain).': '.$item['label'],
                    $position['x'],
                    $top + ($item['h'] / 2),
                    80.0,
                    $item['h'],
                    '@cynefin.boundaryColor',
                    '@cynefin.'.$domain.'Bg@95',
                    dashed: false,
                    textColor: '@cynefin.textColor',
                    drawLabel: $item['draw'],
                );
                $shapes[$id] = 'rounded';
                $origins[$id] = $item['origin'];
                $elements[$id] = $item['origin'];
                $legend[] = ['text' => \ucfirst($domain).': '.$item['label'], 'origin' => $item['origin']];
                $top += $item['h'] + 2;
                yield null;
            }
        }

        return null;
    }

    /** @param array<int, GraphNode> $nodes
     * @param array<int, string> $shapes
     * @param array<int, int> $origins
     * @return \Generator<int, null, mixed, GraphNode>
     */
    private static function caption(array &$nodes, array &$shapes, array &$origins, string $text, float $x, float $top, int $origin, GraphFont $font = GraphFont::Regular): \Generator
    {
        $wrap = MermaidWrap::text($text, width: 60);
        yield from $wrap;
        $draw = $wrap->getReturn();
        [$w, $h] = MermaidText::box($draw);
        $id = \count($nodes);
        $nodes[$id] = new GraphNode(
            $id,
            'caption-'.$id,
            $text,
            $x,
            $top + ($h / 2),
            $w,
            $h,
            null,
            null,
            dashed: false,
            background: true,
            textColor: '@cynefin.textColor',
            drawLabel: $draw,
            font: $font,
        );
        $shapes[$id] = 'text';
        $origins[$id] = $origin;

        return $nodes[$id];
    }

    /** @param array<int, GraphNode> $nodes
     * @param array<int, int> $origins
     * @return \Generator<int, null, mixed, list<int>>
     */
    private static function rows(array $nodes, array $origins, int $origin, float $height): \Generator
    {
        $rows = MermaidOrigins::rows($height + 4, $origin);
        foreach ($nodes as $id => $node) {
            for ($row = \max(0, (int) \floor(($node->y - ($node->h / 2)) / 2)); $row <= (int) \ceil(($node->y + ($node->h / 2)) / 2); ++$row) {
                $rows[$row] = $origins[$id];
                yield null;
            }
        }

        return $rows;
    }
}
