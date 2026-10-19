<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/** Participant columns with chronological message rows and explicit source origins. */
final readonly class SequenceLayout
{
    private MermaidOptions $options;

    public function __construct(
        private MermaidConfiguration $configuration = new MermaidConfiguration(),
        ?MermaidOptions $options = null,
    ) {
        $this->options = $options ?? MermaidOptions::defaults();
    }

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(SequenceDiagram $source): \Generator
    {
        $visible = SequenceVisibility::apply($source, $this->configuration);
        yield from $visible;

        $scene = $this->scene($visible->getReturn());
        yield from $scene;

        return $scene->getReturn();
    }

    /** @return \Generator<int, null, mixed, MermaidScene> */
    private function scene(SequenceDiagram $source): \Generator
    {
        $measure = $this->measure($source);
        yield from $measure;
        [$spacing, $headerHeight] = $measure->getReturn();
        $depth = \max([0, ...\array_column($source->frames, 'depth')]);
        $width = ((\count($source->participants) + 2) * $spacing) + (4 * $depth);
        $metadata = $this->metadata($source, $width);
        yield from $metadata;
        [$nodes, $origins, $headerTop] = $metadata->getReturn();
        $headers = $this->headers($source, $spacing, $depth, $nodes, $headerTop, $headerHeight);
        yield from $headers;
        [$nodes, $positions, $participantsLegend] = $headers->getReturn();
        $legend = $participantsLegend;
        $edges = [];
        $y = $headerTop + $headerHeight + 4;
        // Several participants share a visual row; use the first declaration for that row.
        $origins += \array_fill(0, count: \max(0, (int) ($y / 2)), value: \array_first($source->participants)['origin'] ?? 0);
        if ([] !== $source->groups) {
            $origins[(int) (($headerTop - 2) / 2)] = $source->groups[0]['origin'];
        }
        $rows = [];
        $shapes = [];
        $runs = [];
        $align = $this->options->text('sequence', 'noteAlign');
        foreach ($source->events as $index => $event) {
            $top = $y;
            [$node, $lines, $y, $arrow, $received] = $this->place($event, self::sides($source, $index, $positions), $spacing, $width, $y, \count($nodes));
            if (null !== $node) {
                $nodes[$node->id] = $node;
                // Pinned message text has no box; notes and control captions keep theirs.
                $shapes += 'message' === $event['kind'] ? [$node->id => 'seq-message'] : [];
                $runs += 'note' === $event['kind'] && \in_array($align, ['left', 'right'], strict: true) ? ['node:'.$node->id => self::aligned($node, $align)] : [];
            }
            \array_push($edges, ...$lines);
            $y = $this->reserve($source, $index, $y, $received, $headerHeight);
            $rows[$index] = ['top' => $top, 'bottom' => $y, 'arrow' => $arrow, 'received' => $received];
            $originTop = $top - (\array_key_exists($index, $source->frames) ? 2 : 0);
            for ($row = (int) ($originTop / 2); $row < (int) \ceil($y / 2); ++$row) {
                $origins[$row] = $event['origin'];
                yield null;
            }
            $legend[] = ['text' => $this->caption($event), 'origin' => $event['origin']];
            yield null;
        }
        $origins[(int) ($y / 2)] = \array_last($source->events)['origin'] ?? \array_first($source->participants)['origin'] ?? 0;
        $timeline = new MermaidScene(new GraphLayout($width, $y + 2, $nodes, $edges, clusters: []), $origins, $legend, shapes: $shapes);
        $scene = $this->assemble($source, $timeline, $rows, $positions, [$spacing, $headerTop, $headerHeight, $y]);
        yield from $scene;

        return $scene->getReturn()->styled($runs);
    }

    /**
     * Pinned `noteAlign`: each line of a note at its left or right edge, padded across the
     * note's text width so that centering the line keeps it there.
     *
     * @return list<list<array{string, GraphFont}>>
     */
    private static function aligned(GraphNode $node, string $align): array
    {
        $columns = (int) \round(($node->w - 4) / $node->fontScale);
        $lines = [];
        foreach (\explode("\n", $node->label) as $line) {
            $pad = [\str_repeat(' ', \max(0, $columns - AnsiUtils::visibleWidth($line))), $node->font];
            $lines[] = 'left' === $align ? [[$line, $node->font], $pad] : [$pad, [$line, $node->font]];
        }

        return $lines;
    }

    /**
     * The timeline with its participants' headers and lifelines, activation bars, frames,
     * groups and menus.
     *
     * @param array<int, array{top: float, bottom: float, arrow: float, received: float}> $rows
     * @param array<array-key, float> $positions
     * @param array{float, float, float, float} $metrics column spacing, header top, header height and the timeline's end
     * @return \Generator<int, null, mixed, MermaidScene>
     */
    private function assemble(SequenceDiagram $source, MermaidScene $timeline, array $rows, array $positions, array $metrics): \Generator
    {
        [$spacing, $headerTop, $headerHeight, $y] = $metrics;
        $graph = $timeline->graph;
        $nodes = $graph->nodes;
        $edges = $graph->edges;
        $participants = new SequenceParticipants()->layout($source, $positions, $rows, $headerTop, $headerHeight, $y, \count($nodes));
        yield from $participants;
        [$headers, $lifelines] = $participants->getReturn();
        $nodes += $headers;
        \array_push($edges, ...$lifelines);

        $annotations = $this->annotations($source, $positions, $rows, $graph->width, $y, \count($nodes));
        yield from $annotations;
        [$bars, $clusters] = $annotations->getReturn();
        $groups = SequenceGroupLayout::frames($source, $positions, $spacing, $headerTop, $y);
        yield from $groups;
        $clusters = [...$groups->getReturn(), ...$clusters];

        $scene = new MermaidScene(new GraphLayout($graph->width, $graph->height, $bars + $nodes, $edges, $clusters), $timeline->origins, $timeline->legend, shapes: $timeline->shapes);
        $menus = $this->menus($source, $scene, $positions, $headerHeight);
        yield from $menus;

        return $menus->getReturn();
    }

    /**
     * @param array<array-key, float> $positions
     * @return \Generator<int, null, mixed, MermaidScene>
     */
    private function mirror(SequenceDiagram $source, MermaidScene $scene, array $positions, float $height): \Generator
    {
        $graph = $scene->graph;
        $nodes = $graph->nodes;
        $top = $graph->height - 2;
        $origin = null;
        foreach ($source->participants as $actor => $participant) {
            if (null !== ($source->lifetimes[$actor]['destroy'] ?? null)) {
                continue;
            }
            $id = \count($nodes);
            $nodes[$id] = SequenceParticipants::header($id, (string) $actor, $participant['label'], $positions[$actor], $top, $height);
            $origin ??= $participant['origin'];
            yield null;
        }
        if (null === $origin) {
            return $scene;
        }
        $origins = $scene->origins;
        for ($row = (int) ($top / 2); $row <= (int) (($top + $height) / 2); ++$row) {
            $origins[$row] = $origin;
            yield null;
        }

        // Pinned boxes enclose the footers too: a group reaching the timeline's end reaches theirs.
        $clusters = \array_map(static fn (GraphCluster $cluster): GraphCluster => $top === $cluster->y1 ? $cluster->reaching($top + $height + 1) : $cluster, $graph->clusters);

        return new MermaidScene(new GraphLayout($graph->width, $graph->height + $height, $nodes, $graph->edges, $clusters), $origins, $scene->legend, shapes: $scene->shapes);
    }

    /**
     * @param array<array-key, float> $positions
     * @return \Generator<int, null, mixed, MermaidScene>
     */
    private function menus(SequenceDiagram $source, MermaidScene $scene, array $positions, float $headerHeight): \Generator
    {
        // Pinned `mirrorActors` defaults on: surviving participants repeat beneath the timeline.
        if ($this->configuration->mirrorActors ?? $this->options->flag('sequence', 'mirrorActors')) {
            $mirror = $this->mirror($source, $scene, $positions, $headerHeight);
            yield from $mirror;
            $scene = $mirror->getReturn();
        }
        $graph = $scene->graph;
        $menus = SequenceMenuLayout::layout($source, $graph->width, $graph->height - 2, \count($graph->nodes));
        yield from $menus;
        [$panels, $origins, $legend, $width, $bottom] = $menus->getReturn();
        $nodes = $graph->nodes + $panels;
        $sourceRows = \array_values(\array_replace($scene->origins, $origins));
        $elements = [];
        $shapes = $scene->shapes;
        foreach ($nodes as $node) {
            if ($node->background || \array_key_exists($node->name, $source->metadata)) {
                continue;
            }
            // Message text keeps its own shape: `+=` never replaces one.
            $shape = self::shape($source, $node->name);
            $shapes += null === $shape ? [] : [$node->id => $shape];
            $elements[$node->id] = $source->participants[$node->name]['origin'] ?? $sourceRows[(int) \floor($node->y / 2)] ?? 0;
            yield null;
        }

        // Pinned `actorFontWeight` sets every participant's name; its size draws as pinned, unchanged.
        [, $face] = MermaidFonts::configured($this->options, 'sequence', 'actor', default: 14.0);
        $painted = \array_map(static fn (GraphNode $node): GraphNode => \array_key_exists($node->name, $source->participants) ? $node->faced($face) : $node, self::paint($source, $nodes));

        return new MermaidScene(
            new GraphLayout($width, $bottom + 2, $painted, $graph->edges, self::frames($graph->clusters)),
            $sourceRows,
            [...$scene->legend, ...$legend],
            $elements,
            $shapes,
        );
    }

    /** The pinned shape of a participant's header, or null for the plain box. */
    private static function shape(SequenceDiagram $source, int|string $actor): ?string
    {
        return SequenceSymbols::SHAPES[$source->participantTypes[$actor] ?? ''] ?? null;
    }

    /**
     * Pinned sequence styles: participants and activations take their actor's color theme slot,
     * else the actor and activation colors. Notes and loop labels are colored where they are built.
     *
     * @param array<int, GraphNode> $nodes
     * @return array<int, GraphNode>
     */
    private static function paint(SequenceDiagram $source, array $nodes): array
    {
        $slots = \array_flip(\array_map(\strval(...), \array_keys($source->participants)));
        $painted = [];
        foreach ($nodes as $id => $node) {
            $actor = $slots[$node->name] ?? null;
            $activation = \str_starts_with($node->name, 'activation') ? $source->activations[(int) \substr($node->name, offset: 10)]['actor'] ?? null : null;
            [$stroke, $fill, $text] = match (true) {
                null !== $actor => ['@slot:stroke:'.$actor.'|actorBorder', '@slot:fill:'.$actor.'|actorBkg', '@actorTextColor'],
                // Under color themes an activation copies its actor's paint, the actor fill included.
                null !== $activation => ['@slot:stroke:'.($slots[$activation] ?? 0).'|activationBorderColor', '@slot:fill:'.($slots[$activation] ?? 0).'~actorBkg|activationBkgColor', null],
                default => [null, null, null],
            };
            $painted[$id] = $node->painted($node->color ?? $stroke, $node->fillColor ?? $fill, $node->textColor ?? $text);
        }

        return $painted;
    }

    /**
     * Loop and alternative frames are outlined in the label box border, unfilled.
     *
     * @param list<GraphCluster> $clusters
     * @return list<GraphCluster>
     */
    private static function frames(array $clusters): array
    {
        return \array_map(static fn (GraphCluster $cluster): GraphCluster => \str_starts_with($cluster->name, 'control')
            ? $cluster->painted($cluster->color ?? '@labelBoxBorderColor', $cluster->fillColor ?? 'none', $cluster->textColor ?? '@loopTextColor')
            : $cluster, $clusters);
    }

    /**
     * @param array<int, GraphNode> $nodes
     * @return \Generator<int, null, mixed, array{array<int, GraphNode>, array<array-key, float>, list<array{text: string, origin: int}>}>
     */
    private function headers(SequenceDiagram $source, float $spacing, int $depth, array $nodes, float $headerTop, float $headerHeight): \Generator
    {
        $positions = [];
        $legend = [];
        foreach ($source->metadata as $kind => $item) {
            $legend[] = ['text' => $kind.': '.$item['label'], 'origin' => $item['origin']];
        }
        $ordered = SequenceGroupLayout::ordered($source);
        yield from $ordered;
        $x = $spacing + (2 * $depth);
        foreach ($ordered->getReturn() as $id => $participant) {
            $positions[$id] = $x;
            if (null === ($source->lifetimes[$id]['create'] ?? null)) {
                $number = \count($nodes);
                $nodes[$number] = SequenceParticipants::header($number, (string) $id, $participant['label'], $x, $headerTop, $headerHeight);
            }
            $type = $source->participantTypes[$id] ?? '';
            $legend[] = ['text' => (string) $id.': '.$participant['label'].('' === $type ? '' : ' ['.$type.']'), 'origin' => $participant['origin']];
            $x += $spacing;
            yield null;
        }
        foreach ($source->groups as $group) {
            $legend[] = ['text' => 'box '.$group['label'].': '.\implode(', ', $group['actors']), 'origin' => $group['origin']];
            yield null;
        }

        return [$nodes, $positions, $legend];
    }

    /** @return \Generator<int, null, mixed, array{array<int, GraphNode>, list<int>, float}> */
    private function metadata(SequenceDiagram $source, float $width): \Generator
    {
        $nodes = [];
        $origins = [];
        $top = 2.0;
        foreach ($source->metadata as $kind => $metadata) {
            $label = $kind.': '.$metadata['label'];
            [$w, $h] = MermaidText::box($label);
            $id = \count($nodes);
            $nodes[$id] = new GraphNode($id, $kind, $label, $width / 2, $top + ($h / 2), $w, $h, null, null, dashed: false);
            for ($row = (int) (($top - 2) / 2); $row < (int) (($top + $h + 4) / 2); ++$row) {
                $origins[$row] = $metadata['origin'];
                yield null;
            }
            $top += $h + 4;
            yield null;
        }

        return [$nodes, \array_values($origins), $top];
    }

    /**
     * Pinned messages that create or destroy a participant stop at the side of its header.
     *
     * @param array<array-key, float> $positions
     * @return array<array-key, float>
     */
    private static function sides(SequenceDiagram $source, int $index, array $positions): array
    {
        // Lifetimes point at messages only, and a self message has no side to move to.
        $event = $source->events[$index];
        foreach ([$event['to'] => $event['from'], $event['from'] => $event['to']] as $actor => $other) {
            $life = $source->lifetimes[$actor] ?? [];
            if (($life['create'] ?? null) === $index || ($life['destroy'] ?? null) === $index) {
                $half = MermaidText::box($source->participants[$actor]['label'])[0] / 2;
                $positions[$actor] -= ($positions[$actor] <=> $positions[$other]) * $half;
            }
        }

        return $positions;
    }

    private function reserve(SequenceDiagram $source, int $index, float $bottom, float $received, float $headerHeight): float
    {
        $life = $source->lifetimes[$source->events[$index]['to']] ?? [];
        $created = ($life['create'] ?? null) === $index;
        $dead = ($life['destroy'] ?? null) === $index;
        $sender = $source->lifetimes[$source->events[$index]['from']]['destroy'] ?? null;
        // A header centered on the message reaches half its height below it.
        $below = ($created ? ($headerHeight / 2) + 2 : 0) + ($dead || $sender === $index ? ($headerHeight / 2) + 2 : 0);

        return \max($bottom, $received + $below + ($created && $dead ? $headerHeight : 0));
    }

    /**
     * @param array<array-key, float> $positions
     * @param array<int, array{top: float, bottom: float, arrow: float, received: float}> $rows
     * @return \Generator<int, null, mixed, array{array<int, GraphNode>, list<GraphCluster>}>
     */
    private function annotations(SequenceDiagram $source, array $positions, array $rows, float $width, float $y, int $next): \Generator
    {
        $clusters = [];
        foreach ($source->frames as $frame) {
            $padding = 2.0 * ($frame['depth'] + 1);
            $clusters[] = new GraphCluster(
                'control'.$frame['start'],
                $frame['kind'],
                $padding,
                $rows[$frame['start']]['top'] - 2,
                $width - $padding,
                $rows[$frame['end']]['bottom'],
                null,
                null,
            );
            yield null;
        }
        $bars = [];
        foreach ($source->activations as $id => $activation) {
            $start = $activation['start'];
            $end = $activation['end'];
            $top = $rows[$start]['message' === $source->events[$start]['kind'] ? 'received' : 'top'];
            $bottom = null === $end ? $y : $rows[$end]['message' === $source->events[$end]['kind'] ? 'arrow' : 'top'];
            $death = $source->lifetimes[$activation['actor']]['destroy'] ?? null;
            if (null !== $death) {
                $bottom = \min($bottom, SequenceParticipants::endpoint($source, $rows, $death, $activation['actor']));
            }
            $height = \max(2.0, $bottom - $top);
            $number = $next + $id;
            $bars[$number] = new GraphNode(
                $number,
                'activation'.$id,
                '',
                $positions[$activation['actor']] + (2 * $activation['depth']),
                $top + ($height / 2),
                4,
                $height,
                null,
                null,
                dashed: false,
                background: true,
            );
            yield null;
        }

        return [$bars, $clusters];
    }

    /**
     * @param array{kind: string, from: string, to: string, label: string, arrow: string, placement: string, origin: int} $event
     * @param array<array-key, float> $positions
     * @return array{?GraphNode, list<GraphEdge>, float, float, float}
     */
    private function place(array $event, array $positions, float $spacing, float $width, float $y, int $number): array
    {
        return match ($event['kind']) {
            'control' => $this->control($event, $width, $y, $number),
            'create', 'destroy', 'activate', 'deactivate' => [null, [], $y + ('shortcut' === $event['placement'] ? 0 : 2), $y, $y],
            default => $this->exchange($event, $positions, $spacing, $y, $number),
        };
    }

    /**
     * @param array{kind: string, from: string, to: string, label: string, arrow: string, placement: string, origin: int} $event
     * @param array<array-key, float> $positions
     * @return array{GraphNode, list<GraphEdge>, float, float, float}
     */
    private function exchange(array $event, array $positions, float $spacing, float $y, int $number): array
    {
        $note = 'note' === $event['kind'];
        // Pinned `noteFontSize`/`messageFontSize` scale from 14 and 16 pixels; weights set the face.
        [$scale, $face] = $note ? MermaidFonts::configured($this->options, 'sequence', 'note', default: 14.0) : MermaidFonts::configured($this->options, 'sequence', 'message', default: 16.0);
        [$w, $h] = MermaidText::box($event['label'], $scale);
        $from = $positions[$event['from']];
        $to = $positions[$event['to']];
        if ('over' === $event['placement']) {
            $w += \abs($from - $to);
        }
        // Pinned `messageAlign` anchors a message label at the start, middle or end of its span.
        $align = $note ? 'center' : $this->options->text('sequence', 'messageAlign');
        $center = match (true) {
            'left of' === $event['placement'] => $from - ($spacing / 2),
            'right of' === $event['placement'] => $from + ($spacing / 2),
            'left' === $align && $from !== $to => \min($from, $to) + ($w / 2),
            'right' === $align && $from !== $to => \max($from, $to) - ($w / 2),
            default => ($from + $to) / 2,
        };
        $node = $note
            ? new GraphNode(
                $number,
                'event'.$number,
                $event['label'],
                $center,
                $y + ($h / 2),
                $w,
                $h,
                '@noteBorderColor',
                '@noteBkgColor',
                dashed: true,
                textColor: '@noteTextColor',
                font: $face,
                fontScale: $scale,
            )
            : new GraphNode($number, 'event'.$number, $event['label'], $center, $y + ($h / 2), $w, $h, null, null, dashed: false, textColor: '@signalTextColor', font: $face, fontScale: $scale);
        $arrow = $y + $h + 2;

        $lines = $note ? [] : $this->message($from, $to, $arrow, $spacing, $event['arrow']);
        $points = [] === $lines ? [] : $lines[0]->points;
        $sent = $points[0][1] ?? $arrow;
        // The arrowhead's tip, not the shortened line end, marks where a message arrives.
        $received = $lines[0]->arrowTip[1] ?? \array_last($points)[1] ?? $arrow;

        return [$node, $lines, $y + $h + 6, $sent, $received];
    }

    /**
     * @param array{kind: string, from: string, to: string, label: string, arrow: string, placement: string, origin: int} $event
     * @return array{?GraphNode, list<GraphEdge>, float, float, float}
     */
    private function control(array $event, float $width, float $y, int $number): array
    {
        if ('end' === $event['placement']) {
            return [null, [], $y + 2, $y, $y];
        }
        $label = $this->caption($event);
        [$w, $h] = MermaidText::box($label);
        $node = new GraphNode($number, 'control'.$number, $label, $width / 2, $y + ($h / 2), $w, $h, '@labelBoxBorderColor', '@labelBoxBkgColor', dashed: false, textColor: '@labelTextColor');

        return [$node, [], $y + $h + 4, $y, $y];
    }

    /** @param array{kind: string, from: string, to: string, label: string, arrow: string, placement: string, origin: int} $event */
    private function caption(array $event): string
    {
        return match ($event['kind']) {
            'note' => 'Note '.$event['placement'].' '.$event['from'].($event['from'] === $event['to'] ? '' : ','.$event['to']).': '.$event['label'],
            'control' => $event['placement'].('' === $event['label'] ? '' : ': '.$event['label']),
            'create', 'destroy', 'activate', 'deactivate' => $event['kind'].' '.$event['from'],
            default => $event['from'].$event['arrow'].$event['placement'].$event['to'].': '.$event['label'],
        };
    }

    /** @return \Generator<int, null, mixed, array{float, float}> */
    private function measure(SequenceDiagram $source): \Generator
    {
        $spacing = (float) \max(16, (([] === $source->groups ? 2 : 4) * \max([0, ...\array_column($source->activations, 'depth')])) + 8);
        // Fifty upstream pixels correspond to the existing eight native layout units.
        // Group frames retain a two-unit inset even when the requested margin is zero.
        $margin = \max([] === $source->groups ? 0.0 : 2.0, ($this->configuration->sequenceActorMargin ?? 50) / 6.25);
        $headerHeight = 6.0;
        foreach ($source->participants as $id => $participant) {
            [$w, $h] = MermaidText::box($participant['label']);
            // A glyph stands above its label; the sixteen-unit columns hold the widest, a boundary.
            $glyph = \str_starts_with(self::shape($source, $id) ?? '', 'seq-');
            $spacing = \max($spacing, $w + $margin);
            $headerHeight = \max($headerHeight, $glyph ? $h + 1.4 : $h);
            yield null;
        }
        foreach ($source->groups as $group) {
            [$w, $h] = MermaidText::box($group['label']);
            $spacing = \max($spacing, $w + $margin);
            yield null;
        }
        foreach ($source->metadata as $item) {
            [$w, $h] = MermaidText::box($item['label']);
            $spacing = \max($spacing, $w + $margin);
            yield null;
        }
        foreach ($source->events as $event) {
            [$w, $h] = MermaidText::box('control' === $event['kind'] ? $this->caption($event) : $event['label']);
            $spacing = \max($spacing, $w + $margin);
            yield null;
        }

        return [$spacing, $headerHeight];
    }

    /** @return list<GraphEdge> */
    private function message(float $from, float $to, float $y, float $spacing, string $arrow): array
    {
        $points = match (true) {
            $from !== $to => [[$from, $y], [$to, $y]],
            $this->options->flag('sequence', 'rightAngles') => [[$from, $y], [$from + ($spacing / 3), $y], [$from + ($spacing / 3), $y + 2], [$to, $y + 2]],
            default => self::loop($from, $y, $spacing / 3),
        };
        // Pinned central connections: `()` before the arrow dots the sender's lifeline, after it the receiver's.
        $dots = \array_keys(\array_filter([0 => \str_starts_with($arrow, '()'), \count($points) - 1 => \str_ends_with($arrow, '()')]));
        $arrow = \str_replace('()', replace: '', subject: $arrow);
        $forward = SequenceArrowheads::forward($arrow);
        $ends = $points;
        if ($forward !== $arrow) {
            $points = \array_reverse($points);
        }
        $dashed = \str_contains($arrow, '--');
        [$line, $marks] = SequenceArrowheads::head($points, $forward, strokeWidth: 0.12);
        $edges = [$this->line($line, \str_ends_with($forward, '>>') ? $line[\count($line) - 1] : null, $dashed), ...$marks];
        if (\str_starts_with($forward, '<<')) {
            $edges[] = $this->line(\array_reverse($points), $points[0], $dashed);
        }
        foreach ($dots as $end) {
            $edges[] = SequenceArrowheads::dot($ends[$end]);
        }

        return $edges;
    }

    /**
     * The pinned self message without `rightAngles`: the cubic `C x+60,y-10 x+60,y+30 x,y+20`,
     * scaled to native units and sampled.
     *
     * @return list<array{float, float}>
     */
    private static function loop(float $x, float $y, float $reach): array
    {
        $points = [];
        for ($step = 0; $step <= 12; ++$step) {
            $t = $step / 12;
            $u = 1 - $t;
            $points[] = [$x + (3 * $u * $t * $reach), $y + (3 * $u * $u * $t * -1.0) + (3 * $u * $t * $t * 3.0) + ($t * $t * $t * 2.0)];
        }

        return $points;
    }

    /** @param list<array{float, float}> $points
     * @param ?array{float, float} $tip
     */
    private function line(array $points, ?array $tip, bool $dashed): GraphEdge
    {
        $xs = \array_column($points, 0);
        $ys = \array_column($points, 1);
        $last = \count($points) - 1;
        if (null !== $tip) {
            $before = $points[$last - 1];
            $length = \hypot($before[0] - $tip[0], $before[1] - $tip[1]);
            $fraction = 0.5 / \max(1.0, $length);
            $points[$last] = [$tip[0] + (($before[0] - $tip[0]) * $fraction), $tip[1] + (($before[1] - $tip[1]) * $fraction)];
        }

        return new GraphEdge(0, 0, $points, $tip, \min($xs), \min($ys), \max($xs), \max($ys), null, null, null, $dashed);
    }
}
