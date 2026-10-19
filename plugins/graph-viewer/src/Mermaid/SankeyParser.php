<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Three-column CSV, with strict numeric validation before any weighted geometry is built. */
final class SankeyParser
{
    private readonly SankeyDiagram $diagram;
    private bool $header = false;
    /** @var array<array-key, int> */
    private array $ids = [];

    public function __construct()
    {
        $this->diagram = new SankeyDiagram();
    }

    public function accept(MermaidStatement $statement): void
    {
        if (!$this->header) {
            if (1 !== \preg_match('/^sankey(?:-beta)?$/i', $statement->text)) {
                throw $statement->error('Expected sankey or sankey-beta');
            }
            $this->header = true;
            $this->diagram->origin = $statement->line;

            return;
        }
        if (\count($this->diagram->links) >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the Sankey flow limit');
        }
        $field = '("(?:[^"]|"")*"|[^",\r\n]*)';
        $match = [];
        if (1 !== \preg_match('/^[\t ]*'.$field.'[\t ]*,[\t ]*'.$field.'[\t ]*,[\t ]*'.$field.'[\t ]*$/sD', $statement->text, $match)) {
            throw $statement->error('Expected exactly three Sankey CSV fields');
        }
        $source = $this->node(self::field($match[1]), $statement);
        $target = $this->node(self::field($match[2]), $statement);
        $number = self::field($match[3]);
        if (!\is_numeric($number) || 1 !== \preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/D', $number)) {
            throw $statement->error('Sankey values must be finite non-negative numbers');
        }
        $value = (float) $number;
        if ($value < 0 || !\is_finite($value)) {
            throw $statement->error('Sankey values must be finite non-negative numbers');
        }
        $this->diagram->links[] = ['source' => $source, 'target' => $target, 'value' => $value, 'origin' => $statement->line];
    }

    private static function field(string $text): string
    {
        $text = \trim($text);
        if (\str_starts_with($text, '"')) {
            $text = \str_replace('""', replace: '"', subject: \substr($text, offset: 1, length: -1));
        }

        return \trim($text);
    }

    private function node(string $label, MermaidStatement $statement): int
    {
        if (\array_key_exists($label, $this->ids)) {
            return $this->ids[$label];
        }
        if (\count($this->diagram->nodes) >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the Sankey node limit');
        }
        $id = \count($this->diagram->nodes);
        $this->ids[$label] = $id;
        $this->diagram->nodes[] = ['label' => $label, 'origin' => $statement->line];

        return $id;
    }

    public function finish(): SankeyDiagram
    {
        if (!$this->header || [] === $this->diagram->links) {
            throw new MermaidSyntaxError('Expected Sankey flow data', offset: 0, sourceLine: 0, column: 0);
        }

        return $this->diagram;
    }
}
