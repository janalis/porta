<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Ordered readable labels accompany native circular sectors and donut geometry. */
final readonly class PieLayout
{
    public function __construct(
        private MermaidConfiguration $configuration = new MermaidConfiguration(),
        private ?MermaidPalette $palette = null,
    ) {}

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(PieDiagram $source): \Generator
    {
        $work = new PieCanvas($this->configuration, $this->palette ?? MermaidPalette::defaults('pie'))->layout(\iterator_to_array($this->items($source), preserve_keys: false));
        yield from $work;

        return $work->getReturn();
    }

    /** @return \Generator<int, array{display: string, text: string, origin: int, ratio: ?float, highlight: bool}> */
    private function items(PieDiagram $source): \Generator
    {
        $metadata = $source->metadata;
        $metadata['title'] ??= ['label' => 'Pie', 'origin' => $source->origin];
        foreach ($metadata as $kind => $item) {
            $text = $kind.': '.$this->printable($item['label']);
            yield ['display' => $text, 'text' => $text, 'origin' => $item['origin'], 'ratio' => null, 'highlight' => false];
        }
        foreach ($source->slices as $slice) {
            $ratio = $source->total > 0.0 ? $slice['value'] / $source->total : 0.0;
            $percent = \number_format(100 * $ratio, decimals: 2, decimal_separator: '.', thousands_separator: '').'%';
            $highlight = null !== $this->configuration->pieHighlight && $this->configuration->pieHighlight === $slice['label'];
            $label = ($highlight ? '* ' : '').$this->printable($slice['label']);
            $text = $label.': '.$slice['text'].' ('.$percent.')';
            $display = $source->showData ? $text : $label.' ('.$percent.')';
            yield ['display' => $display, 'text' => $text, 'origin' => $slice['origin'], 'ratio' => $ratio, 'highlight' => $highlight];
        }
    }

    private function printable(string $text): string
    {
        return \strtr($text, ["\x08" => '\\b', "\f" => '\\f', "\r" => '\\r', "\t" => '\\t', "\v" => '\\v', "\0" => '\\0']);
    }
}
