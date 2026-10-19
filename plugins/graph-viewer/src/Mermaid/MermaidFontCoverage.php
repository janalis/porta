<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/**
 * Which characters a TrueType or OpenType font draws, read from its `cmap` table: format 12
 * for the full Unicode range, else format 4 for the Basic Multilingual Plane. A collection
 * (`.ttc`) answers for its first face. Only the table directory and the one subtable are read,
 * so large system fonts stay cheap to ask.
 */
final class MermaidFontCoverage
{
    /** @var array<int, bool> */
    private array $answers = [];
    private ?string $subtable = null;
    private int $format = 0;
    private bool $loaded = false;

    public function __construct(
        private readonly string $path,
    ) {}

    public function covers(int $codepoint): bool
    {
        return $this->answers[$codepoint] ??= $this->lookup($codepoint);
    }

    private function lookup(int $codepoint): bool
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $this->load();
        }

        return match ($this->format) {
            12 => $this->grouped($codepoint),
            4 => $codepoint <= 0xFFFF && $this->segmented($codepoint),
            default => false,
        };
    }

    private function load(): void
    {
        $handle = \is_file($this->path) && \is_readable($this->path) ? \fopen($this->path, mode: 'rb') : false;
        if (false === $handle) {
            return;
        }
        try {
            $face = 'ttcf' === self::read($handle, 0, length: 4) ? self::long($handle, 12) : 0;
            $tables = self::short($handle, $face + 4);
            $cmap = null;
            for ($index = 0; $index < $tables; ++$index) {
                $record = $face + 12 + (16 * $index);
                if ('cmap' === self::read($handle, $record, length: 4)) {
                    $cmap = self::long($handle, $record + 8);
                    break;
                }
            }
            if (null !== $cmap) {
                $this->choose($handle, $cmap);
            }
        } finally {
            \fclose($handle);
        }
    }

    /** The best subtable: Unicode full repertoire (format 12) first, then the BMP (format 4). */
    private function choose(mixed $handle, int $cmap): void
    {
        $count = self::short($handle, $cmap + 2);
        $best = [0, 0];
        for ($index = 0; $index < $count; ++$index) {
            $record = $cmap + 4 + (8 * $index);
            $platform = self::short($handle, $record);
            $encoding = self::short($handle, $record + 2);
            $offset = $cmap + self::long($handle, $record + 4);
            $format = self::short($handle, $offset);
            $unicode = 0 === $platform || (3 === $platform && \in_array($encoding, [1, 10], strict: true));
            if ($unicode && $format > $best[0] && \in_array($format, [4, 12], strict: true)) {
                $best = [$format, $offset];
            }
        }
        [$this->format, $offset] = $best;
        $length = match ($this->format) {
            12 => self::long($handle, $offset + 4),
            4 => self::short($handle, $offset + 2),
            default => 0,
        };
        $this->subtable = $length > 0 ? self::read($handle, $offset, $length) : null;
    }

    private function grouped(int $codepoint): bool
    {
        $table = (string) $this->subtable;
        $groups = self::unpackLong($table, 12);
        [$low, $high] = [0, $groups - 1];
        while ($low <= $high) {
            $middle = \intdiv(num1: $low + $high, num2: 2);
            $start = self::unpackLong($table, 16 + (12 * $middle));
            $end = self::unpackLong($table, 20 + (12 * $middle));
            if ($codepoint < $start) {
                $high = $middle - 1;
                continue;
            }
            if ($codepoint > $end) {
                $low = $middle + 1;
                continue;
            }

            return 0 !== (self::unpackLong($table, 24 + (12 * $middle)) + ($codepoint - $start));
        }

        return false;
    }

    private function segmented(int $codepoint): bool
    {
        $table = (string) $this->subtable;
        $segments = \intdiv(num1: self::unpackShort($table, 6), num2: 2);
        $index = 0;
        while ($index < $segments && $codepoint > self::unpackShort($table, 14 + (2 * $index))) {
            ++$index;
        }
        $start = self::unpackShort($table, 16 + (2 * ($segments + $index)));
        $inside = $index < $segments && $codepoint >= $start && 0xFFFF !== $codepoint;

        return $inside && 0 !== (self::glyph($table, $segments, $index, $codepoint - $start) & 0xFFFF);
    }

    /** A format 4 segment's glyph: by delta, or through the glyph array, where zero stays missing. */
    private static function glyph(string $table, int $segments, int $index, int $offset): int
    {
        $delta = self::unpackShort($table, 16 + (2 * ((2 * $segments) + $index)));
        $rangeAt = 16 + (2 * ((3 * $segments) + $index));
        $range = self::unpackShort($table, $rangeAt);
        $start = self::unpackShort($table, 16 + (2 * ($segments + $index)));
        $mapped = 0 === $range ? $start + $offset : self::unpackShort($table, $rangeAt + $range + (2 * $offset));

        return 0 !== $range && 0 === $mapped ? 0 : $mapped + $delta;
    }

    private static function read(mixed $handle, int $offset, int $length): string
    {
        $bytes = \is_resource($handle) && 0 === \fseek($handle, $offset) ? \fread($handle, \max(1, $length)) : false;

        return false === $bytes ? '' : $bytes;
    }

    private static function short(mixed $handle, int $offset): int
    {
        return self::unpackShort(self::read($handle, $offset, length: 2), 0);
    }

    private static function long(mixed $handle, int $offset): int
    {
        return self::unpackLong(self::read($handle, $offset, length: 4), 0);
    }

    private static function unpackShort(string $bytes, int $offset): int
    {
        $value = \strlen($bytes) >= ($offset + 2) ? \unpack('n', $bytes, $offset) : false;

        return false === $value ? 0 : (int) $value[1];
    }

    private static function unpackLong(string $bytes, int $offset): int
    {
        $value = \strlen($bytes) >= ($offset + 4) ? \unpack('N', $bytes, $offset) : false;

        return false === $value ? 0 : (int) $value[1];
    }
}
