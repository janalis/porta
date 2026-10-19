<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Proportional fields in configurable bit rows; continued fragments keep their authored source line. */
final readonly class PacketLayout
{
    public function __construct(
        private MermaidConfiguration $configuration = new MermaidConfiguration(),
    ) {}

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(PacketDiagram $source): \Generator
    {
        $nodes = [];
        $elements = [];
        $shapes = [];
        $legend = [];
        $top = 2.0;
        $span = $this->bitWidth() * $source->bitsPerRow;
        foreach ($source->metadata as $kind => $item) {
            $work = MermaidWrap::text($item['label'], width: \min(60, \max(2, (int) \floor($span / 2) - 4)));
            yield from $work;
            $label = $work->getReturn();
            [, $height] = MermaidText::box($label);
            $id = \count($nodes);
            $nodes[$id] = new GraphNode(
                $id,
                $kind,
                $label,
                6.0 + ($span / 2),
                $top + ($height / 2),
                $span,
                $height,
                null,
                null,
                dashed: false,
                background: true,
                textColor: '@packet.titleColor|=#000000',
            );
            $shapes[$id] = 'text';
            $legend[] = ['text' => $kind.': '.$item['label'], 'origin' => $item['origin']];
            $top += $height + 2;
            yield null;
        }
        $work = $this->words($source);
        yield from $work;
        foreach ($work->getReturn() as $word) {
            $height = \max(($this->configuration->packetRowHeight ?? 32.0) / 4, \max(\array_column($word, 'height')));
            [$ticks, $header] = false === $this->configuration->packetShowBits ? [[], 0.0] : $this->ticks($word, $source->bitsPerRow);
            foreach ($word as $field) {
                $id = \count($nodes);
                $start = $field['start'];
                $end = $field['end'];
                $width = $this->fieldWidth($start, $end);
                $left = $this->left($start, $end, $source->bitsPerRow);
                $summary = $start.($start === $end ? '' : '–'.$end).': '.\mb_strimwidth($field['label'], start: 0, width: 240, trim_marker: '…');
                $nodes[$id] = new GraphNode(
                    $id,
                    'packet-'.$start,
                    $summary,
                    $left + ($width / 2),
                    $top + $header + ($height / 2),
                    $width,
                    $height,
                    // Pinned `defaultPacketStyleOptions`, under a theme's `packet` overrides.
                    '@packet.blockStrokeColor|=#000000',
                    '@packet.blockFillColor|=#efefef',
                    dashed: false,
                    textColor: '@packet.labelColor|=#000000',
                    drawLabel: $field['draw'],
                );
                $elements[$id] = $field['origin'];
                $legend[] = ['text' => $summary, 'origin' => $field['origin']];
                yield null;
            }
            foreach ($ticks as $tick) {
                $this->tick($nodes, $shapes, $tick['text'], $tick['x'], $top + 1 + (2 * $tick['lane']));
                yield null;
            }
            $top += $header + (($this->configuration->packetPaddingY ?? 5.0) * 0.8) + $height;
            yield null;
        }
        $rows = MermaidOrigins::rows($top + 2, $source->origin);
        foreach ($nodes as $node) {
            if (!\array_key_exists($node->id, $elements)) {
                continue;
            }
            for ($row = (int) \floor(($node->y - ($node->h / 2)) / 2); $row <= (int) \ceil(($node->y + ($node->h / 2)) / 2); ++$row) {
                $rows[$row] = $elements[$node->id];
                yield null;
            }
        }

        return new MermaidScene(new GraphLayout($span + 12.0, \max(8.0, $top), $nodes, edges: [], clusters: []), $rows, $legend, $elements, $shapes);
    }

    /** @return \Generator<int, null, mixed, array<int, list<array{start: int, end: int, label: string, origin: int, draw: string, height: float}>>> */
    private function words(PacketDiagram $source): \Generator
    {
        $words = [];
        $cache = [];
        foreach ($source->fields as $field) {
            for ($start = $field['start']; $start <= $field['end']; $start = $end + 1) {
                $row = \intdiv($start, $source->bitsPerRow);
                $end = \min($field['end'], (($row + 1) * $source->bitsPerRow) - 1);
                $width = \max(2, (int) \floor($this->fieldWidth($start, $end)) - 2);
                $key = $width.':'.$field['label'];
                if (!\array_key_exists($key, $cache)) {
                    $short = \mb_substr($field['label'], start: 0, length: 256);
                    $short .= \mb_strlen($field['label']) > 256 ? '…' : '';
                    $wrap = MermaidWrap::text($short, $width);
                    yield from $wrap;
                    $cache[$key] = $wrap->getReturn();
                }
                $draw = $cache[$key];
                [, $height] = MermaidText::box($draw);
                $words[$row][] = ['start' => $start, 'end' => $end, 'label' => $field['label'], 'origin' => $field['origin'], 'draw' => $draw, 'height' => $height];
                yield null;
            }
        }

        return $words;
    }

    /**
     * @param list<array{start: int, end: int, label: string, origin: int, draw: string, height: float}> $word
     * @return array{list<array{text: string, x: float, lane: int}>, float}
     */
    private function ticks(array $word, int $bitsPerRow): array
    {
        $ticks = [];
        $lanes = [];
        $descending = 'descending' === $this->configuration->packetBitOrder;
        if ($descending) {
            $word = \array_reverse($word);
        }
        foreach ($word as $field) {
            $start = $field['start'];
            $end = $field['end'];
            $left = $this->left($start, $end, $bitsPerRow);
            $width = $this->fieldWidth($start, $end);
            foreach (\array_unique($descending ? [$end, $start] : [$start, $end]) as $bit) {
                $text = (string) $bit;
                $half = \strlen($text) / 2;
                $inset = \min($width / 2, $half + 1);
                $x = match (true) {
                    $start === $end => $left + ($width / 2),
                    ($start === $bit) !== $descending => $left + $inset,
                    default => $left + $width - $inset,
                };
                $lane = 0;
                while (($lanes[$lane] ?? -\INF) >= ($x - $half - 0.8)) {
                    ++$lane;
                }
                $lanes[$lane] = $x + $half;
                $ticks[] = ['text' => $text, 'x' => $x, 'lane' => $lane];
            }
        }

        return [$ticks, 4.0 + (2 * (\count($lanes) - 1))];
    }

    private function fieldWidth(int $start, int $end): float
    {
        $span = $this->bitWidth() * ($end - $start + 1);
        $padding = ($this->configuration->packetPaddingX ?? 5.0) / 8;

        // Reserve a wide glyph and its insets whenever the authored bit slot permits it.
        return \max(\min($span, 4.0), $span - $padding);
    }

    private function bitWidth(): float
    {
        return ($this->configuration->packetBitWidth ?? 32.0) / 8;
    }

    private function left(int $start, int $end, int $bitsPerRow): float
    {
        $column = 'descending' === $this->configuration->packetBitOrder
            ? $bitsPerRow - 1 - ($end % $bitsPerRow)
            : $start % $bitsPerRow;

        return 6.0 + ($this->bitWidth() * $column);
    }

    /** @param array<int, GraphNode> $nodes
     * @param array<int, string> $shapes
     */
    private function tick(array &$nodes, array &$shapes, string $text, float $x, float $y): void
    {
        $id = \count($nodes);
        $nodes[$id] = new GraphNode($id, 'bit-'.$id, $text, $x, $y, 2.0, 2.0, null, null, dashed: false, background: true, textColor: '@packet.startByteColor|=#000000');
        $shapes[$id] = 'text';
    }
}
