<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Forward references settle in bounded passes; exclusions extend logical completion independently of paint. */
final class GanttSchedule
{
    private int $budget = 100_000;

    public function __construct(
        private readonly GanttDate $calendar,
    ) {}

    /** @return \Generator<int, null, mixed, void> */
    public function resolve(GanttDiagram $source): \Generator
    {
        $positions = [];
        foreach ($source->raw as $index => $task) {
            $positions[$task['id']] = $index;
        }
        $resolved = [];
        $pending = \array_keys($source->raw);
        for ($pass = 0; [] !== $pending && $pass < 128; ++$pass) {
            $next = [];
            foreach ($pending as $index) {
                yield null;
                $raw = $source->raw[$index];
                $start = $this->start($index, $source, $positions, $resolved);
                $end = null;
                if (null !== $start) {
                    $end = $this->end($raw['end'], $start, $source, $positions, $resolved, $raw['statement']);
                }
                if (null === $start || null === $end) {
                    $next[] = $index;
                    continue;
                }
                if (!\is_finite($end) || $end < -377_736_739_200 || $end > 253_402_300_799) {
                    throw $raw['statement']->error('Gantt date exceeds the calendar limit');
                }
                $adjust = $this->adjust($start, $end, $raw['end'], $source, $raw['statement']);
                yield from $adjust;
                [$end, $draw] = $adjust->getReturn();
                $resolved[$index] = [
                    'id' => $raw['id'],
                    'label' => $raw['label'],
                    'section' => $raw['section'],
                    'tags' => $raw['tags'],
                    'start' => $start,
                    'end' => $end,
                    'draw' => $draw,
                    'origin' => $raw['statement']->line,
                ];
            }
            if ($pending === $next) {
                break;
            }
            $pending = $next;
        }
        if (\count($resolved) !== \count($source->raw)) {
            throw $source->raw[$pending[0]]['statement']->error('Unresolved or cyclic Gantt task dependencies');
        }
        \ksort($resolved);
        $source->tasks = \array_values($resolved);
    }

    /** @param array<array-key, int> $positions
     * @param array<int, array{id: string, label: string, section: string, tags: list<string>, start: float, end: float, draw: ?float, origin: int}> $resolved
     */
    private function start(int $index, GanttDiagram $source, array $positions, array $resolved): ?float
    {
        $raw = $source->raw[$index];
        $start = $raw['start'];

        return match (true) {
            null === $start => 0 === $index ? $this->calendar->midnight() : $resolved[$index - 1]['end'] ?? null,
            \str_starts_with($start, 'after ') => $this->reference($start, 'after', $positions, $resolved),
            default => $this->calendar->start($start, $source->format, $raw['statement']),
        };
    }

    /** @param array<array-key, int> $positions
     * @param array<int, array{id: string, label: string, section: string, tags: list<string>, start: float, end: float, draw: ?float, origin: int}> $resolved
     */
    private function reference(string $text, string $keyword, array $positions, array $resolved): ?float
    {
        $times = [];
        $missing = false;
        $tokens = [];
        \preg_match_all('/\S+/', \substr($text, \strlen($keyword)), $tokens);
        foreach ($tokens[0] as $id) {
            if (!\array_key_exists($id, $positions)) {
                continue;
            }
            $task = $resolved[$positions[$id]] ?? null;
            if (null === $task) {
                $missing = true;
                break;
            }
            $times[] = 'after' === $keyword ? $task['end'] : $task['start'];
        }

        $time = $this->calendar->midnight();
        if ([] !== $times) {
            $time = 'after' === $keyword ? \max($times) : \min($times);
        }

        return $missing ? null : $time;
    }

    /** @param array<array-key, int> $positions
     * @param array<int, array{id: string, label: string, section: string, tags: list<string>, start: float, end: float, draw: ?float, origin: int}> $resolved
     */
    private function end(string $text, float $start, GanttDiagram $source, array $positions, array $resolved, MermaidStatement $statement): ?float
    {
        if (\str_starts_with($text, 'until ')) {
            return $this->reference($text, 'until', $positions, $resolved);
        }
        $parsed = $this->calendar->parse($text, $source->format);

        return match (true) {
            null === $parsed => $this->calendar->duration($start, $text, $statement),
            $source->inclusive => GanttDate::seconds($this->calendar->at($parsed)->modify('+1 day')),
            default => $parsed,
        };
    }

    /** @return \Generator<int, null, mixed, array{float, ?float}> */
    private function adjust(float $start, float $end, string $authoredEnd, GanttDiagram $source, MermaidStatement $statement): \Generator
    {
        if ([] === $source->excludes || 1 === \preg_match('/^\d{4}-\d\d-\d\d$/', $authoredEnd)) {
            return [$end, null];
        }
        $cursor = $this->calendar->at($start)->modify('+1 day');
        $invalid = false;
        $draw = null;
        $extra = 0;
        while (GanttDate::seconds($cursor) <= $end) {
            yield null;
            if (--$this->budget < 0 || $extra > 10_000) {
                throw $statement->error('Gantt exclusions exceed the calendar iteration limit');
            }
            if (!$invalid) {
                $draw = $end;
            }
            $invalid = $this->excluded(GanttDate::seconds($cursor), $source);
            if ($invalid) {
                $end = GanttDate::seconds($this->calendar->at($end)->modify('+1 day'));
                ++$extra;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return [$end, $draw];
    }

    public function excluded(float $time, GanttDiagram $source): bool
    {
        $date = $this->calendar->at($time);
        $formatted = $this->calendar->label($time, $source->format);
        $iso = $date->format('Y-m-d');
        if (\in_array($formatted, $source->includes, strict: true) || \in_array($iso, $source->includes, strict: true)) {
            return false;
        }
        $day = (int) $date->format('N');
        $weekend = 'friday' === $source->weekend ? 5 : 6;

        return (
            (\in_array('weekends', $source->excludes, strict: true) && ($day === $weekend || $day === ($weekend + 1)))
            || \in_array(\strtolower($date->format('l')), $source->excludes, strict: true)
            || \in_array($formatted, $source->excludes, strict: true)
            || \in_array($iso, $source->excludes, strict: true)
        );
    }
}
