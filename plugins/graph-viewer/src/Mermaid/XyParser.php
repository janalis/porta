<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Mermaid XY and XY-beta declarations, with finite values and bounded total plot work. */
final class XyParser
{
    private readonly XyDiagram $diagram;
    private bool $header = false;
    private int $points = 0;

    public function __construct()
    {
        $this->diagram = new XyDiagram();
    }

    public function accept(MermaidStatement $statement): void
    {
        $match = [];
        if (!$this->header) {
            if (1 !== \preg_match('/^xychart(?:-beta)?(?:[\t ]+(vertical|horizontal))?$/i', $statement->text, $match)) {
                throw $statement->error('Expected xychart or xychart-beta with optional vertical or horizontal orientation');
            }
            $this->header = true;
            $this->diagram->orientation = \strtolower($match[1] ?? 'vertical');
            $this->diagram->oriented = '' !== ($match[1] ?? '');
            $this->diagram->origin = $statement->line;

            return;
        }
        match (true) {
            1 === \preg_match('/^title\s+(.+)$/is', $statement->text, $match) => $this->metadata('title', $match[1], $statement),
            1 === \preg_match('/^(accTitle|accDescr)\s*:\s*(.*)$/is', $statement->text, $match) => $this->metadata(\strtolower($match[1]), $match[2], $statement),
            1 === \preg_match('/^accDescr\s*\{([^}]*)\}$/i', $statement->text, $match) => $this->metadata('accdescr', $match[1], $statement),
            1 === \preg_match('/^(x-axis|y-axis)\s+(.+)$/is', $statement->text, $match) => $this->axis(\strtolower($match[1]), $match[2], $statement),
            1 === \preg_match('/^(bar|line)\b(.*)$/is', $statement->text, $match) => $this->plot(\strtolower($match[1]), $match[2], $statement),
            default => throw $statement->error('Expected XY title, axis, bar or line declaration'),
        };
    }

    private function metadata(string $name, string $text, MermaidStatement $statement): void
    {
        $this->diagram->metadata[$name] = ['label' => FlowchartPath::label($text), 'origin' => $statement->line];
    }

    private function axis(string $name, string $text, MermaidStatement $statement): void
    {
        $array = XyValues::array($text, $statement);
        if (null !== $array) {
            if ('y-axis' === $name) {
                throw $statement->error('XY Y axis requires a numeric range, not categories');
            }
            [$title, $body] = $array;
            $this->diagram->categories = \array_map(FlowchartPath::label(...), XyValues::items($body, $statement));
            $this->diagram->xRange = null;
            $this->metadata($name, $title, $statement);

            return;
        }
        $match = [];
        if (1 === \preg_match('/^(.*?)\s*('.XyValues::NUMBER.')\s*-->\s*('.XyValues::NUMBER.')\s*$/s', $text, $match)) {
            $range = [XyValues::number($match[2], $statement), XyValues::number($match[3], $statement)];
            if ('x-axis' === $name) {
                $this->diagram->xRange = $range;
                $this->diagram->categories = null;
            }
            if ('y-axis' === $name) {
                $this->diagram->yRange = $range;
            }
            $this->metadata($name, $match[1], $statement);

            return;
        }
        if (null !== XyValues::find($text, '-->')) {
            throw $statement->error('Expected an XY numeric axis range');
        }
        $this->metadata($name, $text, $statement);
    }

    private function plot(string $kind, string $text, MermaidStatement $statement): void
    {
        $array = XyValues::array($text, $statement);
        if (null === $array) {
            throw $statement->error('XY plots require a data array');
        }
        [$title, $body] = $array;
        $values = [];
        foreach (XyValues::items($body, $statement) as $item) {
            if ($this->points >= MermaidGraph::MAX_ITEMS) {
                throw $statement->error('Diagram exceeds the XY point limit');
            }
            $match = [];
            if (1 !== \preg_match('/^('.XyValues::NUMBER.')(?:\s*("(?:[^"\\\\]|\\\\.)*"))?$/s', $item, $match)) {
                throw $statement->error('Expected XY value with optional quoted point label');
            }
            $values[] = ['value' => XyValues::number($match[1], $statement), 'label' => FlowchartPath::label($match[2] ?? '')];
            ++$this->points;
        }
        $this->diagram->series[] = ['kind' => 'bar' === $kind ? 'bar' : 'line', 'title' => FlowchartPath::label($title), 'origin' => $statement->line, 'values' => $values];
    }

    public function finish(): XyDiagram
    {
        if (!$this->header || [] === $this->diagram->series) {
            throw new MermaidSyntaxError('Expected an XY chart with at least one plot', offset: 0, sourceLine: 0, column: 0);
        }
        $this->diagram->xRange ??= [1.0, (float) \count($this->diagram->series[0]['values'])];
        $minimum = \INF;
        $maximum = -\INF;
        foreach ($this->diagram->series as $index => $series) {
            $values = null === $this->diagram->categories ? $series['values'] : \array_slice($series['values'], offset: 0, length: \count($this->diagram->categories));
            $this->diagram->series[$index]['values'] = $values;
            foreach ($values as $point) {
                $minimum = \min($minimum, $point['value']);
                $maximum = \max($maximum, $point['value']);
            }
        }
        $this->diagram->yRange ??= [$minimum, $maximum];

        return $this->diagram;
    }
}
