<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Calendar arithmetic uses an injected clock and retains subsecond task precision. */
final readonly class GanttDate
{
    public function __construct(
        public \DateTimeImmutable $today = new \DateTimeImmutable(),
    ) {}

    public function at(float $seconds): \DateTimeImmutable
    {
        return new \DateTimeImmutable(\sprintf('@%.6F', $seconds))->setTimezone($this->today->getTimezone());
    }

    public function midnight(): float
    {
        return self::seconds($this->today->setTime(0, 0));
    }

    public function parse(string $value, string $format): ?float
    {
        if (\in_array($format, ['x', 'X'], strict: true)) {
            return \is_numeric($value) ? (float) $value / ('x' === $format ? 1000 : 1) : null;
        }
        $php = self::phpFormat($format);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d '.$php, $this->today->format('Y-m-d').' '.$value, $this->today->getTimezone());

        return false === $date || $date->format($php) !== $value ? null : self::seconds($date);
    }

    public function start(string $value, string $format, MermaidStatement $statement): float
    {
        $parsed =
            \in_array($format, ['x', 'X'], strict: true) && \is_numeric($value) && 1 === \preg_match('/^\d+$/', $value)
                ? (float) $value / 1000
                : $this->parse($value, $format);
        if (null !== $parsed) {
            return $this->bounded($parsed, $statement);
        }
        // The pinned database falls back to ECMAScript's legacy numeric date grammar.
        if (1 === \preg_match('/^\d{1,2}$/', $value)) {
            $number = (int) $value;
            $value = match (true) {
                0 === $number => '2000-01-01',
                $number <= 12 => '2001-'.\str_pad((string) $number, length: 2, pad_string: '0', pad_type: \STR_PAD_LEFT).'-01',
                $number >= 50 => (1900 + $number).'-01-01',
                $number >= 32 => (2000 + $number).'-01-01',
                default => '',
            };
        }
        if (1 !== \preg_match('/^\d{1,4}-\d{1,2}-\d{1,2}(?:[T ][\d:.]+(?:Z|[+-]\d\d:?\d\d)?)?$/', $value)) {
            throw $statement->error('Invalid Gantt start date');
        }
        try {
            $date = new \DateTimeImmutable($value, $this->today->getTimezone());
        } catch (\Exception) {
            throw $statement->error('Invalid Gantt start date');
        }

        return $this->bounded(self::seconds($date), $statement);
    }

    public function duration(float $start, string $value, MermaidStatement $statement): float
    {
        $match = [];
        if (1 !== \preg_match('/^(\d+(?:\.\d+)?)(ms|[Mdhmswy])$/', $value, $match)) {
            return $start;
        }
        /** @var numeric-string $number The duration regexp captures only decimal digits and an optional fraction. */
        $number = $match[1];
        $amount = (float) $number;
        $unit = $match[2];
        $end = \in_array($unit, ['M', 'y', 'd', 'w'], strict: true)
            ? $this->calendarDuration($start, $amount, $unit, $statement)
            : $start
            + (
                $amount
                * match ($unit) {
                    'h' => 3600,
                    'm' => 60,
                    's' => 1,
                    default => 0.001,
                }
            );

        return $this->bounded($end, $statement);
    }

    private function calendarDuration(float $start, float $amount, string $unit, MermaidStatement $statement): float
    {
        if ('M' === $unit || 'y' === $unit) {
            $date = $this->at($start);
            $months = (int) $amount * ('y' === $unit ? 12 : 1);
            if ($months > 120_000) {
                throw $statement->error('Gantt duration exceeds the calendar limit');
            }
            $day = (int) $date->format('j');
            $next = $date->setDate((int) $date->format('Y'), (int) $date->format('n'), day: 1)->modify('+'.$months.' months');
            $date = $next->setDate((int) $next->format('Y'), (int) $next->format('n'), \min($day, (int) $next->format('t')));

            return self::seconds($date);
        }
        $days = (int) \round($amount * ('w' === $unit ? 7 : 1));
        if ($days > 4_000_000) {
            throw $statement->error('Gantt duration exceeds the calendar limit');
        }

        return self::seconds($this->at($start)->modify('+'.$days.' days'));
    }

    public static function seconds(\DateTimeInterface $date): float
    {
        return $date->getTimestamp() + ((int) $date->format('u') / 1_000_000);
    }

    private function bounded(float $seconds, MermaidStatement $statement): float
    {
        if (!\is_finite($seconds) || $seconds < -377_736_739_200 || $seconds > 253_402_300_799) {
            throw $statement->error('Gantt date exceeds the calendar limit');
        }

        return $seconds;
    }

    public function label(float $seconds, string $format): string
    {
        return match ($format) {
            'x' => (string) (int) ($seconds * 1000),
            'X' => (string) (int) $seconds,
            default => $this->at($seconds)->format(self::phpFormat($format)),
        };
    }

    private static function phpFormat(string $format): string
    {
        $tokens = [];
        \preg_match_all('/\[[^\]]*\]|YYYY|YY|MMMM|MMM|MM|M|DD|D|HH|H|hh|h|mm|m|ss|s|SSS|A|a|ZZ|Z|./s', $format, $tokens);
        $result = '';
        foreach ($tokens[0] as $token) {
            $result .= match ($token) {
                'YYYY' => 'Y',
                'YY' => 'y',
                'MMMM' => 'F',
                'MMM' => 'M',
                'MM' => 'm',
                'M' => 'n',
                'DD' => 'd',
                'D' => 'j',
                'HH' => 'H',
                'H' => 'G',
                'hh' => 'h',
                'h' => 'g',
                'mm', 'm' => 'i',
                'ss', 's' => 's',
                'SSS' => 'v',
                'A', 'a' => $token,
                'Z' => 'P',
                'ZZ' => 'O',
                default => \implode('', \array_map(static fn (string $char): string => '\\'.$char, \str_split(\trim($token, characters: '[]')))),
            };
        }

        return $result;
    }
}
