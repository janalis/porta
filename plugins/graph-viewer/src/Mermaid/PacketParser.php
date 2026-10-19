<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Packet and packet-beta grammar with bounded, contiguous, inclusive bit ranges. */
final class PacketParser
{
    private readonly PacketDiagram $diagram;
    private bool $header = false;
    private int $next = 0;
    private int $fragments = 0;

    public function __construct(MermaidConfiguration $configuration = new MermaidConfiguration())
    {
        $this->diagram = new PacketDiagram($configuration->packetBitsPerRow ?? PacketDiagram::BITS_PER_ROW);
    }

    public function accept(MermaidStatement $statement): void
    {
        $match = [];
        if (!$this->header) {
            if (1 !== \preg_match('/^packet(?:-beta)?$/i', $statement->text)) {
                throw $statement->error('Expected packet or packet-beta');
            }
            $this->header = true;
            $this->diagram->origin = $statement->line;

            return;
        }
        if (
            1 === \preg_match('/^(title)(?:[\t ]+(.*))?$/is', $statement->text, $match)
            || 1 === \preg_match('/^(accTitle|accDescr)\s*:\s*(.*)$/is', $statement->text, $match)
            || 1 === \preg_match('/^(accDescr)\s*\{([^}]*)\}$/i', $statement->text, $match)
        ) {
            $label = \preg_replace('/[\t ]{2,}/', replacement: ' ', subject: \trim($match[2] ?? '')) ?? '';
            $this->diagram->metadata[\strtolower($match[1])] = ['label' => MermaidText::decode($label), 'origin' => $statement->line];

            return;
        }
        $this->field($statement);
    }

    private function field(MermaidStatement $statement): void
    {
        $match = [];
        $integer = '(?:0|[1-9][0-9]*)';
        $pattern = '/^(?:('.$integer.')(?:\s*-\s*('.$integer.'))?|\+('.$integer.'))\s*:\s*("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')$/sD';
        if (1 !== \preg_match($pattern, $statement->text, $match)) {
            throw $statement->error('Expected packet bit, inclusive range or +count with a quoted label');
        }
        $start = '' === $match[1] ? $this->next : self::integer($match[1], $statement);
        $end = '' === $match[3] ? self::integer('' === $match[2] ? (string) $start : $match[2], $statement) : $start + self::integer($match[3], $statement) - 1;
        if ($end < $start) {
            throw $statement->error('Packet field requires a positive width and an end at or after its start');
        }
        if ($start !== $this->next) {
            throw $statement->error('Packet fields must be contiguous; expected bit '.$this->next);
        }
        $this->fragments += \intdiv($end, $this->diagram->bitsPerRow) - \intdiv($start, $this->diagram->bitsPerRow) + 1;
        if ($end >= PacketDiagram::MAX_BITS || $this->fragments > MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the packet field or bit limit');
        }
        $quoted = $match[4];
        $label = \strtr(\substr($quoted, offset: 1, length: -1), ['\\'.$quoted[0] => $quoted[0], '\\\\' => '\\']);
        $this->diagram->fields[] = ['start' => $start, 'end' => $end, 'label' => MermaidText::decode($label), 'origin' => $statement->line];
        $this->next = $end + 1;
    }

    private static function integer(string $value, MermaidStatement $statement): int
    {
        if ((int) $value > PacketDiagram::MAX_BITS || \strlen($value) > 6) {
            throw $statement->error('Diagram exceeds the packet bit limit');
        }

        return (int) $value;
    }

    public function finish(): PacketDiagram
    {
        if (!$this->header) {
            throw new MermaidSyntaxError('Expected packet', offset: 0, sourceLine: 0, column: 0);
        }

        return $this->diagram;
    }
}
