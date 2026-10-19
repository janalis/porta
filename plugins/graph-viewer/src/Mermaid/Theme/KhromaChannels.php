<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid\Theme;

/**
 * khroma 2.1.0 channels (MIT; resources/licenses/khroma.txt): RGB and HSL views of one color,
 * converted lazily, where writing one space fixes the space a later stringify uses.
 */
final class KhromaChannels
{
    private const int ALL = 0;
    private const int RGB = 1;
    private const int HSL = 2;

    private int $type = self::ALL;

    public private(set) bool $changed = false;

    /** @param array<'r'|'g'|'b'|'h'|'s'|'l'|'a', float> $data */
    public function __construct(
        private array $data,
        public readonly ?string $color = null,
    ) {}

    public function hsl(): bool
    {
        return self::HSL === $this->type || !\array_key_exists('r', $this->data);
    }

    /** @param 'r'|'g'|'b'|'h'|'s'|'l'|'a' $channel */
    public function get(string $channel): float
    {
        $rgb = \in_array($channel, ['r', 'g', 'b'], strict: true);
        $own = $this->data[$channel] ?? null;
        $current = 'a' === $channel || (null !== $own && $this->type !== ($rgb ? self::HSL : self::RGB));
        if (!$current) {
            // The other space supplies whatever channels this one has not stored yet.
            $this->ensure($rgb ? ['h', 's', 'l'] : ['r', 'g', 'b'], $rgb ? self::fromRgb(...) : self::fromHsl(...));
        }

        return match (true) {
            $current => $own ?? $this->data['a'],
            $rgb => self::fromHsl($this->data, $channel),
            default => self::fromRgb($this->data, $channel),
        };
    }

    /** @param 'r'|'g'|'b'|'h'|'s'|'l'|'a' $channel */
    public function set(string $channel, float $value): void
    {
        if ('a' !== $channel) {
            $type = \in_array($channel, ['r', 'g', 'b'], strict: true) ? self::RGB : self::HSL;
            if (self::ALL !== $this->type && $this->type !== $type) {
                throw new \InvalidArgumentException('Cannot change both RGB and HSL channels at the same time');
            }
            $this->type = $type;
        }
        $this->changed = true;
        $this->data[$channel] = $value;
    }

    /**
     * @param list<'r'|'g'|'b'|'h'|'s'|'l'> $channels
     * @param \Closure(array<string, float>, string): float $convert
     */
    private function ensure(array $channels, \Closure $convert): void
    {
        foreach ($channels as $channel) {
            $this->data[$channel] ??= $convert($this->data, $channel);
        }
    }

    public static function clamp(string $channel, float $value): float
    {
        $maximum = ['r' => 255.0, 'g' => 255.0, 'b' => 255.0, 's' => 100.0, 'l' => 100.0, 'a' => 1.0][$channel] ?? null;

        return match (true) {
            null === $maximum => \fmod($value, num2: 360),
            $value >= $maximum => $maximum,
            default => $value < 0 ? 0.0 : $value,
        };
    }

    /** @param array<string, float> $data */
    private static function fromHsl(array $data, string $channel): float
    {
        $h = $data['h'] ?? \NAN;
        $s = $data['s'] ?? \NAN;
        $l = $data['l'] ?? \NAN;
        if (0.0 === $s || \is_nan($s)) {
            return $l * 2.55;
        }
        $h /= 360;
        $s /= 100;
        $l /= 100;
        $q = $l < .5 ? $l * (1 + $s) : $l + $s - ($l * $s);
        $p = (2 * $l) - $q;

        return match ($channel) {
            'r' => self::hue($p, $q, $h + (1 / 3)) * 255,
            'g' => self::hue($p, $q, $h) * 255,
            default => self::hue($p, $q, $h - (1 / 3)) * 255,
        };
    }

    private static function hue(float $p, float $q, float $t): float
    {
        if ($t < 0) {
            ++$t;
        }
        if ($t > 1) {
            --$t;
        }

        return match (true) {
            $t < (1 / 6) => $p + (($q - $p) * 6 * $t),
            $t < (1 / 2) => $q,
            $t < (2 / 3) => $p + (($q - $p) * ((2 / 3) - $t) * 6),
            default => $p,
        };
    }

    /** @param array<string, float> $data */
    private static function fromRgb(array $data, string $channel): float
    {
        $r = ($data['r'] ?? \NAN) / 255;
        $g = ($data['g'] ?? \NAN) / 255;
        $b = ($data['b'] ?? \NAN) / 255;
        $max = \max($r, $g, $b);
        $min = \min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        $s = $l > .5 ? \fdiv($d, 2 - $max - $min) : \fdiv($d, $max + $min);

        return match (true) {
            'l' === $channel => $l * 100,
            $max === $min => 0.0,
            's' === $channel => $s * 100,
            $max === $r => (\fdiv($g - $b, $d) + ($g < $b ? 6 : 0)) * 60,
            $max === $g => (\fdiv($b - $r, $d) + 2) * 60,
            $max === $b => (\fdiv($r - $g, $d) + 4) * 60,
            default => -1.0,
        };
    }
}
