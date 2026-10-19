<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Gantt lines retain colon-rich labels and date/time values without executable callbacks. */
final class GanttParser
{
    private readonly GanttDiagram $diagram;
    private bool $header = false;
    private string $section = '';
    private int $generated = 0;

    public function __construct(
        GanttDate $calendar = new GanttDate(),
    ) {
        $this->diagram = new GanttDiagram($calendar);
    }

    public function accept(MermaidStatement $statement): void
    {
        $text = $statement->text;
        if (!$this->header) {
            if ('gantt' !== \strtolower($text)) {
                throw $statement->error('Expected gantt header');
            }
            $this->header = true;
            $this->diagram->origin = $statement->line;

            return;
        }
        $match = [];
        match (true) {
            1 === \preg_match('/^(dateFormat|axisFormat|tickInterval|todayMarker|weekday|weekend)\s+(.+)$/i', $text, $match) => $this->directive(\strtolower($match[1]), \trim($match[2])),
            1 === \preg_match('/^(includes|excludes)\s+(.+)$/i', $text, $match) => $this->dates(\strtolower($match[1]), $match[2]),
            'inclusiveenddates' === \strtolower($text) => $this->diagram->inclusive = true,
            'topaxis' === \strtolower($text) => $this->diagram->topAxis = true,
            1 === \preg_match('/^section\s+(.+)$/i', $text, $match) => $this->section($match[1], $statement->line),
            1 === \preg_match('/^(title\s+|accTitle\s*:|accDescr\s*:)(.*)$/is', $text, $match) => $this->metadata(
                \strtolower(\trim($match[1], characters: " \t:")),
                \trim($match[2]),
                $statement->line,
            ),
            1 === \preg_match('/^accDescr\s*\{(.*)\}$/is', $text, $match) => $this->metadata('accdescr', \trim($match[1]), $statement->line),
            \str_starts_with($text, 'click ') => $this->metadata('click:'.$statement->line, $text, $statement->line),
            default => $this->task($statement),
        };
    }

    private function dates(string $key, string $value): void
    {
        $tokens = [];
        \preg_match_all('/[^\s,]+/', \strtolower($value), $tokens);
        if ('includes' === $key) {
            $this->diagram->includes = \array_values(\array_unique([...$this->diagram->includes, ...$tokens[0]]));

            return;
        }
        $this->diagram->excludes = \array_values(\array_unique([...$this->diagram->excludes, ...$tokens[0]]));
    }

    private function section(string $label, int $line): void
    {
        $this->section = MermaidText::decode($label);
        $this->diagram->sections[$this->section] ??= ['label' => $this->section, 'origin' => $line];
    }

    private function metadata(string $kind, string $label, int $line): void
    {
        $this->diagram->metadata[$kind] = ['label' => MermaidText::decode($label), 'origin' => $line];
    }

    private function directive(string $key, string $value): void
    {
        match ($key) {
            'dateformat' => $this->diagram->format = $value,
            'axisformat' => $this->diagram->axis = $value,
            'tickinterval' => $this->diagram->tick = $value,
            'todaymarker' => $this->diagram->todayMarker = $value,
            'weekday' => $this->diagram->weekday = \strtolower($value),
            default => $this->diagram->weekend = \strtolower($value),
        };
    }

    private function task(MermaidStatement $statement): void
    {
        $separator = \strpos($statement->text, needle: ':');
        if (false === $separator || \count($this->diagram->raw) >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Expected Gantt task label and data within the task limit');
        }
        $label = MermaidText::decode(\trim(\substr($statement->text, offset: 0, length: $separator)));
        $data = \substr($statement->text, $separator + 1);
        $data = \preg_replace('/[#;].*$/s', replacement: '', subject: $data) ?? $data;
        $parts = \array_map(\trim(...), \explode(',', $data));
        $tags = [];
        $tagCount = 0;
        foreach ($parts as $part) {
            if (!\in_array($part, ['active', 'done', 'crit', 'milestone', 'vert'], strict: true)) {
                break;
            }
            $tags[] = $part;
            ++$tagCount;
        }
        $parts = \array_slice($parts, $tagCount);
        if ([] === $parts || \count($parts) > 3) {
            throw $statement->error('Expected one to three Gantt task data fields');
        }
        $id = 3 === \count($parts) ? $parts[0] : 'task'.++$this->generated;
        $start = \count($parts) > 1 ? $parts[\count($parts) - 2] : null;
        $end = $parts[\count($parts) - 1];
        $this->diagram->raw[] = ['id' => $id, 'label' => $label, 'section' => $this->section, 'tags' => $tags, 'start' => $start, 'end' => $end, 'statement' => $statement];
    }

    public function finish(): GanttDiagram
    {
        if (!$this->header) {
            throw new MermaidSyntaxError('Expected a diagram header', offset: 0, sourceLine: 0, column: 0);
        }

        return $this->diagram;
    }
}
