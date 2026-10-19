<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/** Class compartments preserve attribute/method order and source-linked member legends. */
final readonly class ClassLayout
{
    private MermaidOptions $options;

    public function __construct(?MermaidOptions $options = null)
    {
        $this->options = $options ?? MermaidOptions::defaults();
    }

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(ClassDiagram $source): \Generator
    {
        $graph = $source->graph;
        if (!$this->options->flag('class', 'hierarchicalNamespaces')) {
            $graph = self::flat($source);
        }
        $legend = $source->metadata;
        $runs = [];
        foreach ($graph->nodes as $id => $node) {
            $type = $source->types[$id];
            $name = $node['label'].('' === $type ? '' : '<'.$type.'>');
            // Pinned headers set the first annotation in guillemets above the bold name.
            $annotations = \array_map(static fn (string $annotation): string => '«'.$annotation.'»', \array_slice($source->annotations[$id] ?? [], offset: 0, length: 1));
            $attributes = [];
            $methods = [];
            foreach ($source->members[$id] ?? [] as $member) {
                $legend[] = $member;
                yield null;
                $display = ClassMembers::display($member['text']);
                if (\str_contains(\substr($member['text'], offset: 1), needle: ')')) {
                    $methods[] = $display;
                    continue;
                }
                $attributes[] = $display;
            }
            $compartments = [];
            foreach ([$attributes, $methods] as $rows) {
                if ([] === $rows) {
                    continue;
                }
                $compartments[] = ['text' => \implode("\n", \array_column($rows, 0)), 'origin' => $node['origin']];
            }
            $name = \implode("\n", [...$annotations, $name]);
            // A pinned class without members keeps an empty members box unless `hideEmptyMembersBox`.
            if ([] === $compartments && !$this->options->flag('class', 'hideEmptyMembersBox')) {
                $compartments[] = ['text' => '', 'origin' => $node['origin']];
            }
            $card = ErCard::label($name, $compartments);
            yield from $card;
            $graph->nodes[$id]['label'] = $card->getReturn()['label'];
            [$runs[$id], $underlined, $width] = self::runs($card->getReturn()['label'], \count($annotations), [...$attributes, ...$methods]);
            $graph->shapes[$id] = $card->getReturn()['shape'].([] === $underlined ? '' : ';u='.$width.'='.\implode(',', $underlined));
        }
        foreach ($source->notes as $index => $note) {
            $id = 'class-note-'.$index;
            // Notes sort after every class, so classes keep their pinned color slots.
            $graph->node($id, $note['text'], new MermaidStatement('', offset: \PHP_INT_MAX - \count($source->notes) + $index, line: $note['origin'], column: 0), groups: []);
            if (\array_key_exists($note['for'], $graph->nodes)) {
                $graph->edge($id, $note['for'], label: '', arrow: '-.-', statement: new MermaidStatement('', offset: 0, line: $note['origin'], column: 0));
            }
        }
        $graph->rankGap = 18.0;
        $layout = new RankedGraphLayout()->layout($graph);
        yield from $layout;
        $scene = $layout->getReturn();
        $routing = MermaidCardRoutes::layout($scene->graph);
        yield from $routing;
        $routed = $routing->getReturn();
        $edges = $routed->edges;
        $nodes = \array_map(static fn (GraphNode $node): GraphNode => \str_starts_with($node->name, 'class-note-')
            ? $node->painted('@noteBorderColor', '@noteBkgColor', '@noteTextColor')
            : $node, $routed->nodes);
        $styled = [];
        foreach ($routed->nodes as $node) {
            // An authored font style sets the whole card in that face, as the pinned CSS does.
            $styled += \array_key_exists($node->name, $runs) && GraphFont::Regular === $node->font ? ['node:'.$node->id => $runs[$node->name]] : [];
        }
        foreach ($routed->edges as $index => $edge) {
            // Note links follow the relationships and draw dotted, without UML ends.
            if (!\array_key_exists($index, $source->markers)) {
                continue;
            }
            $marker = $source->markers[$index];
            $edges[$index] = ClassMarkers::shaft($edge, $marker['left'], $marker['right']);
            \array_push($edges, ...ClassMarkers::forEdge($edge, $marker['left'], $marker['right']));
            yield null;
        }

        return new MermaidScene(
            new GraphLayout($routed->width, $routed->height, $nodes, $edges, $routed->clusters),
            $scene->origins,
            [...$legend, ...$scene->legend],
            $scene->elements,
            $scene->shapes,
            runs: $styled,
        );
    }

    /**
     * A card's runs: the annotations, then the name in bold, then each member padded to the
     * widest so the members read left-aligned, abstract ones in italics. Static members'
     * lines are returned for their underlines, with the padded width.
     *
     * @param list<array{string, string}> $members display text and classifier, in label order
     * @return array{list<list<array{string, GraphFont}>>, list<int>, int}
     */
    private static function runs(string $label, int $annotations, array $members): array
    {
        $lines = \explode("\n", $label);
        $header = \array_search('', $lines, strict: true);
        $header = false === $header ? \count($lines) : $header;
        $faces = [];
        foreach ($members as [$text, $classifier]) {
            foreach (\explode("\n", self::wrapped($text)) as $line) {
                $faces[] = [$line, $classifier];
            }
        }
        $width = \max([0, ...\array_map(static fn (array $face): int => AnsiUtils::visibleWidth($face[0]), $faces)]);
        $runs = [];
        $underlined = [];
        $member = 0;
        foreach ($lines as $index => $line) {
            if ($index < $header) {
                $runs[] = [[$line, $index < $annotations ? GraphFont::Regular : GraphFont::Bold]];
                continue;
            }
            if ('' === $line && !\array_key_exists($member, $faces)) {
                $runs[] = [['', GraphFont::Regular]];
                continue;
            }
            [$text, $classifier] = '' === $line ? ['', ''] : $faces[$member++];
            $underlined = '$' === $classifier ? [...$underlined, $index] : $underlined;
            $runs[] = '' === $line
                ? [['', GraphFont::Regular]]
                : [[$text, '*' === $classifier ? GraphFont::Italic : GraphFont::Regular], [\str_repeat(' ', \max(0, $width - AnsiUtils::visibleWidth($text))), GraphFont::Regular]];
        }

        return [$runs, $underlined, $width];
    }

    /** A member as the card wraps it. */
    private static function wrapped(string $text): string
    {
        $wrap = MermaidWrap::text($text, width: 48);
        \iterator_to_array($wrap);

        return $wrap->getReturn();
    }

    /**
     * Pinned `hierarchicalNamespaces: false`: only declared namespaces frame classes, each under
     * its full dotted name and never nested; a class joins its nearest declared namespace.
     */
    private static function flat(ClassDiagram $source): MermaidGraph
    {
        $graph = clone $source->graph;
        $groups = [];
        foreach ($graph->groups as $id => $group) {
            if (!\array_key_exists($id, $source->declared)) {
                continue;
            }
            $groups[$id] = ['label' => \substr($id, offset: 10), 'parent' => null] + $group;
        }
        $graph->groups = $groups;
        foreach ($graph->nodes as $id => $node) {
            $declared = \array_filter($node['groups'], static fn (string $group): bool => \array_key_exists($group, $groups));
            $graph->nodes[$id]['groups'] = [] === $declared ? [] : [\array_last($declared)];
        }

        return $graph;
    }
}
