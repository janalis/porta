<?php

declare(strict_types=1);

namespace Porta\Tests\Support\Screenshots;

/**
 * The SGR state at one cell of a {@see ScreenSvg} row: colours as `#rrggbb`
 * (null for the terminal's default) and the attributes Porta writes.
 */
final readonly class Style
{
    private function __construct(
        public ?string $fg = null,
        public ?string $bg = null,
        public bool $bold = false,
        public bool $dim = false,
        public bool $italic = false,
        public bool $underline = false,
        public bool $inverse = false,
    ) {}

    public static function plain(): self
    {
        return new self();
    }

    /** The state after an SGR whose parameters are $params (the bytes between `CSI` and `m`). */
    public function apply(string $params): self
    {
        $parts = \preg_split('/[;:]/', $params);
        $codes = '' === $params || false === $parts ? [0] : \array_map(\intval(...), $parts);
        $state = \get_object_vars($this);
        for ($i = 0, $count = \count($codes); $i < $count; ++$i) {
            $code = $codes[$i];
            if (38 === $code || 48 === $code) {
                [$colour, $used] = self::extended(\array_slice($codes, $i + 1));
                $state[38 === $code ? 'fg' : 'bg'] = $colour;
                $i += $used;
                continue;
            }
            match (true) {
                0 === $code => $state = \get_object_vars(self::plain()),
                1 === $code => $state['bold'] = true,
                2 === $code => $state['dim'] = true,
                3 === $code => $state['italic'] = true,
                4 === $code => $state['underline'] = true,
                7 === $code => $state['inverse'] = true,
                22 === $code => $state = ['bold' => false, 'dim' => false] + $state,
                23 === $code => $state['italic'] = false,
                24 === $code => $state['underline'] = false,
                27 === $code => $state['inverse'] = false,
                $code >= 30 && $code <= 37 => $state['fg'] = self::indexed($code - 30),
                39 === $code => $state['fg'] = null,
                $code >= 40 && $code <= 47 => $state['bg'] = self::indexed($code - 40),
                49 === $code => $state['bg'] = null,
                $code >= 90 && $code <= 97 => $state['fg'] = self::indexed($code - 82),
                $code >= 100 && $code <= 107 => $state['bg'] = self::indexed($code - 92),
                default => null,
            };
        }

        return new self(...$state);
    }

    /** The colour glyphs are drawn in, inverse applied. */
    public function foreground(): string
    {
        return $this->inverse ? $this->bg ?? ScreenSvg::defaultBackground() : $this->fg ?? ScreenSvg::defaultForeground();
    }

    /** The colour the cell is filled with, inverse applied. */
    public function background(): string
    {
        return $this->inverse ? $this->fg ?? ScreenSvg::defaultForeground() : $this->bg ?? ScreenSvg::defaultBackground();
    }

    /** Whether two cells can share one `<text>`: everything but the background. */
    public function sameInk(self $other): bool
    {
        return $this->foreground() === $other->foreground() && $this->bold === $other->bold && $this->dim === $other->dim && $this->italic === $other->italic && $this->underline === $other->underline;
    }

    /**
     * A `5;n` or `2;r;g;b` colour and how many parameters it used.
     *
     * @param list<int> $codes
     *
     * @return array{?string, int}
     */
    private static function extended(array $codes): array
    {
        return match ($codes[0] ?? null) {
            5 => [self::indexed($codes[1] ?? 0), 2],
            2 => [self::hex($codes[1] ?? 0, $codes[2] ?? 0, $codes[3] ?? 0), 4],
            default => [null, 0],
        };
    }

    /** The xterm 256-colour palette. */
    private static function indexed(int $index): string
    {
        $system = [
            '#000000',
            '#cd0000',
            '#00cd00',
            '#cdcd00',
            '#0000ee',
            '#cd00cd',
            '#00cdcd',
            '#e5e5e5',
            '#7f7f7f',
            '#ff0000',
            '#00ff00',
            '#ffff00',
            '#5c5cff',
            '#ff00ff',
            '#00ffff',
            '#ffffff',
        ];
        $levels = [0, 95, 135, 175, 215, 255];
        $cube = \max(0, $index - 16);
        $grey = 8 + (10 * (\min($index, 255) - 232));

        return match (true) {
            $index < 16 => $system[\max(0, $index)],
            $index < 232 => self::hex($levels[\intdiv($cube, num2: 36)], $levels[\intdiv($cube, num2: 6) % 6], $levels[$cube % 6]),
            default => self::hex($grey, $grey, $grey),
        };
    }

    private static function hex(int $red, int $green, int $blue): string
    {
        return \sprintf('#%02x%02x%02x', $red, $green, $blue);
    }
}
