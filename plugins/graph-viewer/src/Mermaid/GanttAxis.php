<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Tick generation is bounded independently of task duration or authored millisecond intervals. */
final readonly class GanttAxis
{
    public function __construct(
        private GanttDate $calendar,
    ) {}

    /** @return list<float> */
    public function ticks(float $minimum, float $maximum, GanttDiagram $source): array
    {
        $match = [];
        if (1 === \preg_match('/^([1-9]\d*)(millisecond|second|minute|hour|day|week|month)$/', $source->tick, $match)) {
            $count = (int) $match[1];
            $unit = $match[2];
            $step = $count
            * match ($unit) {
                'millisecond' => 0.001,
                'second' => 1,
                'minute' => 60,
                'hour' => 3600,
                'day' => 86_400,
                'week' => 604_800,
                default => 2_592_000,
            };
            if ($count <= 120_000 && (($maximum - $minimum) / $step) <= 512) {
                return $this->custom($minimum, $maximum, $count, $unit, $source->weekday);
            }
        }

        return $this->automatic($minimum, $maximum, $source);
    }

    /** @return list<float> */
    private function automatic(float $minimum, float $maximum, GanttDiagram $source): array
    {
        $target = \max(0.001, ($maximum - $minimum) / 8);
        if ($target >= (2 * 86_400)) {
            [$unit, $count] = match (true) {
                $target <= 604_800 => ['week', 1],
                $target <= 1_209_600 => ['week', 2],
                default => ['month', (int) \max(1, \ceil($target / 2_629_800))],
            };

            return $this->custom($minimum, $maximum, $count, $unit, $source->weekday);
        }
        $step = 172_800.0;
        foreach ([
            0.001,
            0.002,
            0.005,
            0.01,
            0.02,
            0.05,
            0.1,
            0.2,
            0.5,
            1.0,
            2.0,
            5.0,
            10.0,
            15.0,
            30.0,
            60.0,
            120.0,
            300.0,
            600.0,
            900.0,
            1800.0,
            3600.0,
            7200.0,
            10_800.0,
            21_600.0,
            43_200.0,
            86_400.0,
            172_800.0,
        ] as $candidate) {
            if ($candidate < $target) {
                continue;
            }
            $step = $candidate;
            break;
        }
        $ticks = [];
        for ($tick = \ceil($minimum / $step) * $step; $tick <= $maximum && \count($ticks) < 512; $tick += $step) {
            $ticks[] = $tick;
        }

        return $ticks;
    }

    /** @return list<float> */
    private function custom(float $minimum, float $maximum, int $count, string $unit, string $weekday): array
    {
        $step = $count
        * match ($unit) {
            'millisecond' => 0.001,
            'second' => 1,
            'minute' => 60,
            'hour' => 3600,
            default => 86_400,
        };
        $date = match ($unit) {
            'month' => $this->month($minimum, $count),
            'week' => $this->week($minimum, $weekday),
            default => $this->calendar->at(\ceil($minimum / $step) * $step),
        };
        $ticks = [];
        while (GanttDate::seconds($date) <= $maximum && \count($ticks) < 512) {
            $seconds = GanttDate::seconds($date);
            if ($seconds >= $minimum) {
                $ticks[] = $seconds;
            }
            $date = match ($unit) {
                'millisecond' => $this->calendar->at($seconds + ($count / 1000)),
                default => $date->modify('+'.$count.' '.$unit),
            };
        }

        return $ticks;
    }

    private function month(float $minimum, int $count): \DateTimeImmutable
    {
        $date = $this->calendar->at($minimum);
        $month = (int) (\floor((((int) $date->format('Y') * 12) + (int) $date->format('n') - 1) / $count) * $count);

        return $date->setDate((int) \floor($month / 12), ($month % 12) + 1, day: 1)->setTime(0, 0);
    }

    private function week(float $minimum, string $weekday): \DateTimeImmutable
    {
        $date = $this->calendar->at($minimum);
        $days = ['monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7];
        $offset = ((int) $date->format('N') - ($days[$weekday] ?? 7) + 7) % 7;

        return $date->setTime(0, 0)->modify('-'.$offset.' days');
    }

    public function label(float $time, string $format): string
    {
        $date = $this->calendar->at($time);

        return (
            \preg_replace_callback(
                '/%([aAbBcdefgGHIjLmMpqQsSuUVwWxXyYZ%])/',
                static fn (array $match): string => match ($match[1]) {
                    'a' => $date->format('D'),
                    'A' => $date->format('l'),
                    'b' => $date->format('M'),
                    'B' => $date->format('F'),
                    'c' => $date->format('D M j H:i:s Y'),
                    'd' => $date->format('d'),
                    'e' => \str_pad($date->format('j'), length: 2, pad_type: \STR_PAD_LEFT),
                    'f' => $date->format('u'),
                    'g' => \substr($date->format('o'), offset: -2),
                    'G' => $date->format('o'),
                    'H' => $date->format('H'),
                    'I' => $date->format('h'),
                    'j' => \str_pad((string) ((int) $date->format('z') + 1), length: 3, pad_string: '0', pad_type: \STR_PAD_LEFT),
                    'L' => $date->format('v'),
                    'm' => $date->format('m'),
                    'M' => $date->format('i'),
                    'p' => $date->format('A'),
                    'q' => (string) (int) \ceil((int) $date->format('n') / 3),
                    'Q' => (string) (int) ($time * 1000),
                    's' => (string) (int) \floor($time),
                    'S' => $date->format('s'),
                    'u' => $date->format('N'),
                    'U' => (string) (int) \floor(((int) $date->format('z') + 7 - (int) $date->format('w')) / 7),
                    'W' => (string) (int) \floor(((int) $date->format('z') + 8 - (int) $date->format('N')) / 7),
                    'V' => $date->format('W'),
                    'w' => $date->format('w'),
                    'x' => $date->format('m/d/Y'),
                    'X' => $date->format('H:i:s'),
                    'y' => $date->format('y'),
                    'Y' => $date->format('Y'),
                    'Z' => $date->format('O'),
                    default => '%',
                },
                $format,
            ) ?? $format
        );
    }
}
