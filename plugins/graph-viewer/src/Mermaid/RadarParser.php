<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Located declarations, closed options and complete per-axis curves. */
final class RadarParser
{
    private readonly RadarDiagram $diagram;
    private bool $header = false;
    private int $entries = 0;

    public function __construct()
    {
        $this->diagram = new RadarDiagram();
    }

    public function accept(MermaidStatement $statement): void
    {
        if (!$this->header) {
            if (1 !== \preg_match('/^radar-beta\s*:?$/i', $statement->text)) {
                throw $statement->error('Expected radar-beta');
            }
            $this->header = true;
            $this->diagram->origin = $statement->line;

            return;
        }
        $this->declaration($statement);
    }

    private function declaration(MermaidStatement $statement): void
    {
        $match = [];
        if (1 === \preg_match('/^(title(?:[\t ]|$)|accTitle\s*:|accDescr\s*[:{])(.*)$/is', $statement->text, $match)) {
            $name = \strtolower(\rtrim($match[1], characters: " \t:{"));
            $body = $match[2];
            if (\str_ends_with($match[1], '{')) {
                $body = \substr($body, offset: 0, length: -1);
            }
            $this->diagram->metadata[$name] = ['label' => MermaidText::decode(\trim(\preg_replace('/%%[^\r\n]*/', replacement: '', subject: $body) ?? $body)), 'origin' => $statement->line];

            return;
        }
        if (1 === \preg_match('/^(axis|curve)\b/', $statement->text, $match)) {
            foreach (RadarDeclarations::parse($statement) as $item) {
                $this->item($match[1], $item, $statement);
            }

            return;
        }
        foreach (\explode(',', $statement->text) as $option) {
            $this->option(\trim($option), $statement);
        }
    }

    /** @param array{name: string, label: string, origin: int, entries: list<array{axis: ?string, value: float, origin: int}>} $item */
    private function item(string $kind, array $item, MermaidStatement $statement): void
    {
        if ('axis' === $kind) {
            if (\count($this->diagram->axes) >= 256 || \in_array($item['name'], \array_column($this->diagram->axes, 'name'), strict: true)) {
                throw $statement->error('Radar axes require unique ids and at most 256 declarations');
            }
            $this->diagram->axes[] = ['name' => $item['name'], 'label' => $item['label'], 'origin' => $item['origin']];

            return;
        }
        $this->entries += \count($item['entries']);
        if ($this->entries > MermaidGraph::MAX_ITEMS || \count($this->diagram->curves) >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the radar point or curve limit');
        }
        $this->diagram->curves[] = $item;
    }

    private function option(string $option, MermaidStatement $statement): void
    {
        $match = [];
        if (1 !== \preg_match('/^(showLegend|ticks|max|min|graticule)\s+(\S+)$/', $option, $match)) {
            throw $statement->error('Expected a radar option');
        }
        [$all, $name, $value] = $match;
        if ('showLegend' === $name || 'graticule' === $name) {
            $choices = 'showLegend' === $name ? ['true', 'false'] : ['circle', 'polygon'];
            if (!\in_array($value, $choices, strict: true)) {
                throw $statement->error('Invalid radar '.$name.' option');
            }
            match ($name) {
                'showLegend' => $this->diagram->showLegend = 'true' === $value,
                default => $this->diagram->graticule = $value,
            };

            return;
        }
        $number = RadarDeclarations::number($value, $statement);
        if ('ticks' === $name && \floor($number) !== $number) {
            throw $statement->error('Radar ticks must be a whole number');
        }
        match ($name) {
            'ticks' => $this->diagram->ticks = (int) \min(32.0, $number),
            'min' => $this->diagram->minimum = $number,
            default => $this->diagram->maximum = $number,
        };
    }

    public function finish(): RadarDiagram
    {
        if (!$this->header) {
            throw new MermaidSyntaxError('Expected radar-beta', offset: 0, sourceLine: 0, column: 0);
        }
        foreach ($this->diagram->curves as $index => $curve) {
            $resolved = self::resolve($this->diagram, $curve);
            if ([] === $resolved || \count($resolved) !== \count($this->diagram->axes)) {
                throw new MermaidSyntaxError('Radar curves require one value for every axis', offset: 0, sourceLine: $curve['origin'], column: 0);
            }
            $this->diagram->curves[$index]['entries'] = $resolved;
        }
        if (null !== $this->diagram->maximum && $this->diagram->maximum < $this->diagram->minimum) {
            throw new MermaidSyntaxError('Radar maximum must be at least its minimum', offset: 0, sourceLine: $this->diagram->origin, column: 0);
        }

        return $this->diagram;
    }

    /** @param array{name: string, label: string, origin: int, entries: list<array{axis: ?string, value: float, origin: int}>} $curve
     * @return list<array{axis: ?string, value: float, origin: int}>
     */
    private static function resolve(RadarDiagram $source, array $curve): array
    {
        if (null === $curve['entries'][0]['axis']) {
            return $curve['entries'];
        }
        $names = \array_column($source->axes, 'name');
        $found = [];
        foreach ($curve['entries'] as $entry) {
            if (!\in_array($entry['axis'], $names, strict: true)) {
                throw new MermaidSyntaxError('Unknown radar axis reference', offset: 0, sourceLine: $entry['origin'], column: 0);
            }
            $found[$entry['axis']] ??= $entry;
        }
        $entries = [];
        foreach ($names as $name) {
            if (!\array_key_exists($name, $found)) {
                throw new MermaidSyntaxError('Missing radar value for axis '.$name, offset: 0, sourceLine: $curve['origin'], column: 0);
            }
            $entries[] = $found[$name];
        }

        return $entries;
    }
}
