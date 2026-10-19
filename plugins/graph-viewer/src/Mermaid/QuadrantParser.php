<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Located parsing for quadrant metadata, axes, points and point-class declarations. */
final class QuadrantParser
{
    private readonly QuadrantDiagram $diagram;
    private bool $header = false;

    public function __construct()
    {
        $this->diagram = new QuadrantDiagram();
    }

    public function accept(MermaidStatement $statement): void
    {
        $match = [];
        if (!$this->header) {
            if ('quadrantchart' !== \strtolower($statement->text)) {
                throw $statement->error('Expected quadrantChart');
            }
            $this->header = true;
            $this->diagram->origin = $statement->line;

            return;
        }
        match (true) {
            1 === \preg_match('/^title\s+(.*)$/is', $statement->text, $match) => $this->label('title', $match[1], $statement),
            1 === \preg_match('/^(accTitle|accDescr)\s*:\s*(.*)$/is', $statement->text, $match) => $this->label(\strtolower($match[1]), $match[2], $statement),
            1 === \preg_match('/^accDescr\s*\{([^}]*)\}$/i', $statement->text, $match) => $this->label('accdescr', $match[1], $statement),
            1 === \preg_match('/^(x-axis|y-axis)\s+(.+)$/is', $statement->text, $match) => $this->axis(\strtolower($match[1]), $match[2], $statement),
            1 === \preg_match('/^(quadrant-[1-4])\s+(.+)$/is', $statement->text, $match) => $this->label(\strtolower($match[1]), $match[2], $statement),
            1 === \preg_match('/^classDef\s+(\w+)\s+(.+)$/is', $statement->text, $match) => $this->pointClass($match[1], $match[2], $statement),
            default => $this->point($statement),
        };
    }

    private function label(string $name, string $text, MermaidStatement $statement): void
    {
        $this->diagram->labels[$name] = ['label' => FlowchartPath::label($text), 'origin' => $statement->line];
    }

    private function axis(string $name, string $text, MermaidStatement $statement): void
    {
        $quoted = new MermaidQuotedText();
        $length = \strlen($text);
        for ($index = 0; $index < $length; ++$index) {
            $quoted->advance($text[$index]);
            $match = [];
            if (!$quoted->quoted && 1 === \preg_match('/\G--+>/', $text, $match, offset: $index)) {
                $right = \trim(\substr($text, $index + \strlen($match[0])));
                $left = \substr($text, offset: 0, length: $index);
                if ('' === \trim($left)) {
                    throw $statement->error('Quadrant axis requires a low label before its arrow');
                }
                $this->label($name.'-low', $left, $statement);
                if ('' === $right) {
                    $this->diagram->labels[$name.'-low']['label'] .= ' ⟶';
                }
                if ('' !== $right) {
                    $this->label($name.'-high', $right, $statement);
                }

                return;
            }
        }
        $this->label($name.'-low', $text, $statement);
    }

    private function pointClass(string $name, string $text, MermaidStatement $statement): void
    {
        if (!\array_key_exists($name, $this->diagram->classes) && \count($this->diagram->classes) >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the quadrant class limit');
        }
        $this->diagram->classes[$name] = QuadrantStyles::parse($text, $statement);
    }

    private function point(MermaidStatement $statement): void
    {
        $match = [];
        if (1 !== \preg_match('/^(.+?)(?:\s*:::(\w+))?\s*:\s*\[\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*\]\s*(.*)$/s', $statement->text, $match)) {
            throw $statement->error('Expected quadrant point label: [x, y] with coordinates from zero to one');
        }
        if (\count($this->diagram->points) >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the quadrant point limit');
        }
        // @mago-expect analysis:invalid-type-cast -- The point grammar accepts only decimal numeric strings.
        $x = (float) $match[3];
        // @mago-expect analysis:invalid-type-cast -- The point grammar accepts only decimal numeric strings.
        $y = (float) $match[4];
        if ($x < 0.0 || $x > 1.0 || $y < 0.0 || $y > 1.0 || !\is_finite($x) || !\is_finite($y)) {
            throw $statement->error('Quadrant coordinates must be finite and between zero and one');
        }
        $this->diagram->points[] = [
            'label' => FlowchartPath::label($match[1]),
            'x' => $x,
            'y' => $y,
            'class' => $match[2],
            'style' => QuadrantStyles::parse($match[5], $statement),
            'origin' => $statement->line,
        ];
    }

    public function finish(): QuadrantDiagram
    {
        if (!$this->header) {
            throw new MermaidSyntaxError('Expected quadrantChart', offset: 0, sourceLine: 0, column: 0);
        }

        return $this->diagram;
    }
}
