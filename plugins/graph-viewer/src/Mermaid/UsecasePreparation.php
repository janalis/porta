<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Request-local graph projection leaves the parsed declaration model unchanged. */
final class UsecasePreparation
{
    public readonly MermaidGraph $graph;
    /** @var array<array-key, string> */
    public array $drawLabels = [];
    /** @var list<array{text: string, origin: int}> */
    public array $legend = [];

    public function __construct(
        private readonly UsecaseDiagram $source,
        private readonly UsecaseConfiguration $configuration = new UsecaseConfiguration(),
    ) {
        $this->graph = new MermaidGraph('use case');
        $this->graph->direction = $source->graph->direction;
        $this->graph->rankGap = $configuration->spacing('rankSpacing', default: 8.0);
        $this->graph->nodeGap = $configuration->spacing('nodeSpacing', default: 6.0);
        $this->graph->padding = $configuration->spacing('diagramPadding', default: 4.0);
    }

    /** @return \Generator<int, null, mixed, self> */
    public function build(): \Generator
    {
        foreach ($this->source->elements as $element) {
            $css = $this->configuration->css($element->kind);
            if ('' !== $css && \in_array($element->kind, ['actor', 'usecase'], strict: true)) {
                $this->graph->styles->accept(new MermaidStatement('classDef porta_'.$element->kind.' '.$css, offset: 0, line: 0, column: 0));
                $this->graph->styles->assign($element->id, 'porta_'.$element->kind, $element->statement);
            }
            yield null;
        }
        yield from UsecaseStyles::apply($this->source, $this->graph);
        foreach ($this->source->elements as $element) {
            if ('boundary' === $element->kind) {
                $this->graph->groups[$element->id] = [
                    'label' => $element->label,
                    'origin' => $element->statement->line,
                    'offset' => $element->statement->offset,
                    'parent' => null,
                    'direction' => $this->graph->direction,
                    'explicit' => false,
                ];
                yield null;
                continue;
            }
            if ('edge' !== $element->kind) {
                yield from $this->element($element);
            }
            yield null;
        }
        foreach ($this->graph->groups as $id => $group) {
            $this->graph->closeGroup((string) $id, [(string) $id]);
            yield null;
        }
        foreach ($this->source->relations as $relation) {
            $index = \count($this->graph->edges);
            $name = $relation->id ?? '@usecase-edge:'.$index;
            $label = $relation->label;
            if (null !== $relation->markdown) {
                $label = $this->markdown('edge:'.$index, $relation->markdown);
            }
            $this->graph->edge($relation->from, $relation->to, $label, $relation->arrow, $relation->statement);
            if (null !== $relation->id) {
                $this->graph->edgeIds[$relation->id] = $index;
            }
            if (null === $relation->id) {
                $this->graph->styles->assign($name, 'default', $relation->statement);
            }
            $this->graph->edgeStyles->identify($index, $name);
            if ('generalization' === $relation->kind) {
                $this->legend[] = ['text' => $relation->from.' --|> '.$relation->to, 'origin' => $relation->statement->line];
            }
            $metadata = null === $relation->id ? [] : $this->source->elements[$relation->id]->metadata;
            foreach ($metadata as $key => $value) {
                $this->legend[] = [
                    'text' => $name.' '.$key.': '.match (true) {
                        \is_string($value) => $value,
                        true === $value => 'true',
                        default => 'false',
                    },
                    'origin' => $relation->statement->line,
                ];
            }
            yield null;
        }
        foreach ($this->source->notes as $index => $note) {
            $id = '@usecase-note:'.$index;
            $wrap = MermaidWrap::text($note['label'], width: 48);
            yield from $wrap;
            $this->graph->node($id, $note['markdown'] ? $this->markdown('node:'.$id, $note['raw']) : $wrap->getReturn(), $note['statement'], groups: []);
            $this->graph->edge($id, $note['target'], '', '.-', $note['statement']);
            yield null;
        }

        return $this;
    }

    /** @return \Generator<int, null, mixed, void> */
    private function element(UsecaseElement $element): \Generator
    {
        $id = $element->id;
        $label = null === $element->stereotype ? $element->label : '«'.$element->stereotype."»\n".$element->label;
        $shape = $element->shape;
        if (null === $element->json) {
            $wrap = MermaidWrap::text($label, width: 48);
            yield from $wrap;
            // Pinned backtick labels are Markdown; a stereotype keeps its plain line above them.
            $label = $element->markdown ? $this->markdown('node:'.$id, (null === $element->stereotype ? '' : '«'.$element->stereotype."»\n").$element->raw) : $wrap->getReturn();
        }
        if (null !== $element->json) {
            $rows = UsecaseJson::rows($element->json, $element->statement);
            yield from $rows;
            $card = UsecaseTable::label($element->label, $rows->getReturn());
            yield from $card;
            $label = $card->getReturn()['label'];
            $shape = $card->getReturn()['shape'];
            $this->drawLabels[$id] = $card->getReturn()['drawLabel'];
            foreach ($rows->getReturn() as $row) {
                $this->legend[] = ['text' => $id.' '.$row['key'].': '.$row['value'], 'origin' => $element->statement->line];
            }
        }
        if ('actor' === $element->kind) {
            $type = $element->metadata['type'] ?? 'normal';
            $icon = $element->metadata['icon'] ?? '';
            $variant = '' !== $icon ? 'icon' : (string) $type;
            $shape = 'uc-actor:'.$variant.':'.(true === ($element->metadata['business'] ?? false) ? '1' : '0');
            [$width, $height] = $this->graph->styles->measure($id, $label);
            $this->graph->sizes[$id] = [\max(8.0, $width), $height + 6];
            $this->drawLabels[$id] = '';
            if (\is_string($icon) && '' !== $icon) {
                $this->legend[] = ['text' => $element->label.' icon: '.$icon, 'origin' => $element->statement->line];
            }
        }
        if ('actor' !== $element->kind && true === ($element->metadata['business'] ?? false)) {
            $shape = 'uc-business';
        }
        $this->graph->node($id, $label, $element->statement, null === $element->parent ? [] : [$element->parent]);
        $this->graph->shapes[$id] = $shape;
    }

    /** A Markdown label wrapped at the 48 columns plain labels take; its plain text sizes the element. */
    private function markdown(string $key, string $raw): string
    {
        $markdown = MermaidMarkdown::parse($raw)->wrap(wrappingWidth: 360.0);
        $this->graph->markdown[$key] = $markdown;

        return MermaidText::decode($markdown->plain());
    }
}
