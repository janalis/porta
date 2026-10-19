<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Pinned pie grammar: first duplicate label wins; zero-valued slices remain in the legend. */
final class PieParser
{
    private readonly PieDiagram $diagram;
    private bool $header = false;

    public function __construct()
    {
        $this->diagram = new PieDiagram();
    }

    public function accept(MermaidStatement $statement): void
    {
        $match = [];
        if (!$this->header) {
            if (1 !== \preg_match('/^pie\b(?:[\t ]+(showData)\b)?[\t ]*(.*)$/is', $statement->text, $match)) {
                throw $statement->error('Expected pie with optional showData and title');
            }
            $this->header = true;
            $this->diagram->origin = $statement->line;
            $this->diagram->showData = '' !== ($match[1] ?? '');
            if ('' !== $match[2]) {
                $prefix = \strlen($statement->text) - \strlen($match[2]);
                $this->accept(new MermaidStatement($match[2], $statement->offset + $prefix, $statement->line, $statement->column + $prefix));
            }

            return;
        }
        match (true) {
            1 === \preg_match('/^title(?:\s+(.*))?$/i', $statement->text, $match) => $this->metadata('title', $match[1] ?? '', $statement),
            1 === \preg_match('/^(accTitle|accDescr)\s*:\s*(.*)$/is', $statement->text, $match) => $this->metadata(\strtolower($match[1]), $match[2], $statement),
            1 === \preg_match('/^accDescr\s*\{([^}]*)\}$/i', $statement->text, $match) => $this->metadata('accdescr', $match[1], $statement),
            default => $this->slice($statement),
        };
    }

    private function metadata(string $kind, string $text, MermaidStatement $statement): void
    {
        $this->diagram->metadata[$kind] = ['label' => MermaidText::decode(\trim($text)), 'origin' => $statement->line];
    }

    private function slice(MermaidStatement $statement): void
    {
        $match = [];
        if (1 !== \preg_match('/^(?<label>"(?:[^"\\\\]++|\\\\.)*"|\'(?:[^\'\\\\]++|\\\\.)*\')\s*:\s*(?<value>-?(?:0|[1-9]\d*|\d+\.\d+))$/s', $statement->text, $match)) {
            throw $statement->error('Expected quoted pie label followed by a numeric value');
        }
        // @mago-expect analysis:invalid-type-cast -- The preceding grammar match accepts only numeric strings.
        $value = (float) $match['value'];
        if ($value < 0.0 || !\is_finite($value)) {
            throw $statement->error('Pie value must be finite and nonnegative');
        }
        $decoded = MermaidQuotedString::decode($match['label']);
        $label = MermaidText::decode($decoded);
        // Slice identity follows parser strings, before terminal display entity decoding.
        if (\array_key_exists($decoded, $this->diagram->slices)) {
            return;
        }
        if (\count($this->diagram->slices) >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the pie slice limit');
        }
        $total = $this->diagram->total + $value;
        if (!\is_finite($total)) {
            throw $statement->error('Pie total exceeds the numeric value limit');
        }
        $this->diagram->total = $total;
        $this->diagram->slices[$decoded] = ['label' => $label, 'value' => $value, 'text' => $match['value'], 'origin' => $statement->line];
    }

    public function finish(): PieDiagram
    {
        if (!$this->header) {
            throw new MermaidSyntaxError('Expected pie', offset: 0, sourceLine: 0, column: 0);
        }

        return $this->diagram;
    }
}
