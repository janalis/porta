<?php

declare(strict_types=1);

namespace Porta\Tests\Support\Screenshots;

use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A full frame as Porta WROTE it, drawn as an SVG for the docs.
 *
 * Built from the cells rather than rasterised from a terminal, so it needs no
 * fonts and no converter on the machine that builds the docs, and it is exactly
 * what the IDE composed. Text goes out in runs of one style, each pinned to its
 * columns with `textLength`, so the grid holds in whatever monospace font the
 * reader's browser picks. The glyphs a font draws badly at cell size — the
 * half blocks the rules are made of, and box drawing, which leaves gaps
 * between rows — are drawn as shapes instead, and a kitty picture as the PNG
 * the IDE sent ({@see KittyImages}).
 *
 * Input is a FORCED frame: the writer clears, homes, and writes every row
 * joined by CRLF, then parks the cursor with relative moves. Anything else (a
 * differential repaint) is not a grid and is not read as one.
 */
final class ScreenSvg
{
    private const float CELL_WIDTH = 8.4;
    private const float CELL_HEIGHT = 18.0;
    private const float FONT_SIZE = 14.0;
    private const float BASELINE = 13.5;
    private const float PADDING = 12.0;
    private const float TITLE_BAR = 28.0;
    private const string DEFAULT_FG = '#d0d0d0';
    private const string DEFAULT_BG = '#1c1c1c';
    private const string FONT = "'JetBrains Mono', 'SF Mono', Menlo, Consolas, 'DejaVu Sans Mono', monospace";
    private const string DASHED_LINE = ' stroke-dasharray="2 2"';

    /**
     * Block elements as the part of the cell they fill: [x0, y0, x1, y1] in eighths.
     *
     * @var array<string, array{int, int, int, int}>
     */
    private const array BLOCKS = [
        '▀' => [0, 0, 8, 4],
        '▄' => [0, 4, 8, 8],
        '█' => [0, 0, 8, 8],
        '▌' => [0, 0, 4, 8],
        '▐' => [4, 0, 8, 8],
        '▎' => [0, 0, 2, 8],
        '▁' => [0, 7, 8, 8],
        '▏' => [0, 0, 1, 8],
    ];

    /**
     * Shades as the opacity of their foreground over the background.
     *
     * @var array<string, float>
     */
    private const array SHADES = ['░' => 0.25, '▒' => 0.5, '▓' => 0.75];

    /**
     * Box drawing as its four arms — up, right, down, left — each 0 (none),
     * 1 (light), 2 (heavy) or 3 (double).
     *
     * @var array<string, array{int, int, int, int}>
     */
    private const array BOXES = [
        '─' => [0, 1, 0, 1],
        '│' => [1, 0, 1, 0],
        '┌' => [0, 1, 1, 0],
        '┐' => [0, 0, 1, 1],
        '└' => [1, 1, 0, 0],
        '┘' => [1, 0, 0, 1],
        '├' => [1, 1, 1, 0],
        '┤' => [1, 0, 1, 1],
        '┬' => [0, 1, 1, 1],
        '┴' => [1, 1, 0, 1],
        '┼' => [1, 1, 1, 1],
        '━' => [0, 2, 0, 2],
        '┃' => [2, 0, 2, 0],
        '┏' => [0, 2, 2, 0],
        '┓' => [0, 0, 2, 2],
        '┗' => [2, 2, 0, 0],
        '┛' => [2, 0, 0, 2],
        '┣' => [2, 2, 2, 0],
        '┫' => [2, 0, 2, 2],
        '═' => [0, 3, 0, 3],
        '║' => [3, 0, 3, 0],
        '╔' => [0, 3, 3, 0],
        '╗' => [0, 0, 3, 3],
        '╚' => [3, 3, 0, 0],
        '╝' => [3, 0, 0, 3],
        '┄' => [0, 1, 0, 1],
        '┈' => [0, 1, 0, 1],
        '┆' => [1, 0, 1, 0],
    ];

    /** The arms of {@see BOXES} as unit vectors from the centre, in the same order. */
    private const array ARMS = [[0, -1], [1, 0], [0, 1], [-1, 0]];

    /** The dashed members of {@see BOXES}. */
    private const array DASHED = ['┄', '┈', '┆'];

    /**
     * Rounded corners: the horizontal and the vertical edge they join, each a
     * direction from the centre.
     *
     * @var array<string, array{int, int}>
     */
    private const array ARCS = ['╭' => [1, 1], '╮' => [-1, 1], '╰' => [1, -1], '╯' => [-1, -1]];

    /**
     * Private-use glyphs a terminal font carries and a web font does not, as
     * the nearest standard one: a reader's browser would draw a box.
     */
    private const array PRIVATE_USE = ["\u{E0A0}" => '⎇'];

    /** @var array<string, string> colour, width and dash => path data, so each stroke style is one element */
    private array $strokes = [];

    /** @var list<string> */
    private array $backgrounds = [];

    /** @var list<string> drawn over every background, which is only flushed when its run ends */
    private array $blocks = [];

    /** @var list<string> */
    private array $glyphs = [];

    /** @var array<int, list<array{int, int, int, int}>> image id => [screen row, screen column, placement row, placement column] */
    private array $imageCells = [];

    /**
     * @param positive-int $columns
     * @param positive-int $rows
     */
    private function __construct(
        private readonly int $columns,
        private readonly int $rows,
        private readonly KittyImages $images,
    ) {}

    /**
     * @param string       $frame   the bytes of one forced full frame
     * @param positive-int $columns
     * @param positive-int $rows
     * @param string       $before  what the terminal was sent before the frame: where its pictures are
     */
    public static function fromFrame(string $frame, int $columns, int $rows, string $title = '', string $before = ''): string
    {
        $home = \strrpos($frame, needle: "\x1b[H");
        if (false === $home) {
            throw new \InvalidArgumentException('Not a full frame: it never homes the cursor.');
        }
        $lines = \array_slice(\explode("\r\n", \substr($frame, $home + 3)), offset: 0, length: $rows);
        $svg = new self($columns, $rows, KittyImages::fromOutput($before.$frame));
        foreach ($lines as $row => $line) {
            $svg->row($row, $line);
        }

        return $svg->document($title, self::cursor($lines));
    }

    /** The default colours, for {@see Style} — one place to change the palette. */
    public static function defaultForeground(): string
    {
        return self::DEFAULT_FG;
    }

    public static function defaultBackground(): string
    {
        return self::DEFAULT_BG;
    }

    /**
     * Where the writer parked the cursor, if it showed it: it ends the last row,
     * then moves up and to a column, and `?25h` makes it visible.
     *
     * @param list<string> $lines
     *
     * @return array{int, int}|null row and column
     */
    private static function cursor(array $lines): ?array
    {
        $last = \array_key_last($lines);
        if (null === $last || !\str_contains($lines[$last], "\x1b[?25h")) {
            return null;
        }
        $row = $last;
        $col = 0;
        $moves = [];
        \preg_match_all('/\x1b\[(\d*)([ABG])/', $lines[$last], $moves, \PREG_SET_ORDER);
        foreach ($moves as [, $count, $command]) {
            $count = '' === $count ? 1 : (int) $count;
            match ($command) {
                'A' => $row -= $count,
                'B' => $row += $count,
                default => $col = $count - 1,
            };
        }

        return [$row, $col];
    }

    private function row(int $row, string $line): void
    {
        $style = Style::plain();
        $run = null;
        $background = null; // [start column, colour]
        foreach (AnsiUtils::walkCells($line) as $cell) {
            if (0 === $cell['width']) {
                if (\str_starts_with($cell['text'], "\x1b[") && \str_ends_with($cell['text'], 'm')) {
                    $style = $style->apply(\substr($cell['text'], offset: 2, length: -1));
                }
                continue;
            }
            $col = $cell['col'];
            if ($col >= $this->columns) {
                break;
            }
            $bg = $style->background();
            if (null === $background || $background[1] !== $bg) {
                $this->flushBackground($row, $background, $col);
                $background = [$col, $bg];
            }
            $glyph = self::PRIVATE_USE[$cell['text']] ?? $cell['text'];
            if (null !== $run && $run->continuesWith($style, $col) && !$this->isDrawn($glyph, $style)) {
                $run->text .= $glyph;
                continue;
            }
            $this->flushRun($row, $run);
            $run = $this->draw($glyph, $row, $col, $style) ? null : new TextRun($col, $style, $glyph);
        }
        $this->flushRun($row, $run);
        $this->flushBackground($row, $background, $this->columns);
    }

    /** Whether $glyph is drawn rather than written: a shape, or a piece of a picture. */
    private function isDrawn(string $glyph, Style $style): bool
    {
        return (
            \array_key_exists($glyph, self::BLOCKS)
            || \array_key_exists($glyph, self::SHADES)
            || \array_key_exists($glyph, self::ARCS)
            || \array_key_exists($glyph, self::BOXES)
            || null !== KittyImages::cell($glyph, $style->fg)
        );
    }

    /** Draw $glyph when it is drawn rather than written; false when it is text. */
    private function draw(string $glyph, int $row, int $col, Style $style): bool
    {
        $piece = KittyImages::cell($glyph, $style->fg);
        $fg = $style->foreground();

        return match (true) {
            null !== $piece => $this->piece($piece, $row, $col),
            \array_key_exists($glyph, self::BLOCKS) => $this->block(self::BLOCKS[$glyph], $row, $col, $fg),
            \array_key_exists($glyph, self::SHADES) => $this->shade(self::SHADES[$glyph], $row, $col, $fg),
            \array_key_exists($glyph, self::ARCS) => $this->arc(self::ARCS[$glyph], $row, $col, $fg),
            \array_key_exists($glyph, self::BOXES) => $this->box(self::BOXES[$glyph], \in_array($glyph, self::DASHED, strict: true) ? self::DASHED_LINE : '', $row, $col, $fg),
            default => false,
        };
    }

    /** @param array{int, int, int} $piece image id, placement row, placement column */
    private function piece(array $piece, int $row, int $col): true
    {
        $this->imageCells[$piece[0]][] = [$row, $col, $piece[1], $piece[2]];

        return true;
    }

    /** @param array{int, int, int, int} $eighths */
    private function block(array $eighths, int $row, int $col, string $fg): true
    {
        [$x0, $y0, $x1, $y1] = $eighths;
        $this->blocks[] = \sprintf(
            '<rect x="%s" y="%s" width="%s" height="%s" fill="%s"/>',
            self::n($this->x($col) + (($x0 * self::CELL_WIDTH) / 8)),
            self::n($this->y($row) + (($y0 * self::CELL_HEIGHT) / 8)),
            self::n((($x1 - $x0) * self::CELL_WIDTH) / 8),
            self::n((($y1 - $y0) * self::CELL_HEIGHT) / 8),
            $fg,
        );

        return true;
    }

    private function shade(float $opacity, int $row, int $col, string $fg): true
    {
        $this->blocks[] = \sprintf(
            '<rect x="%s" y="%s" width="%s" height="%s" fill="%s" fill-opacity="%s"/>',
            self::n($this->x($col)),
            self::n($this->y($row)),
            self::n(self::CELL_WIDTH),
            self::n(self::CELL_HEIGHT),
            $fg,
            self::n($opacity),
        );

        return true;
    }

    /** @param array{int, int} $edges which way the horizontal and the vertical edge go */
    private function arc(array $edges, int $row, int $col, string $fg): true
    {
        [$cx, $cy] = $this->centre($row, $col);
        $this->stroke($fg, '1.2', '', \sprintf(
            'M%s %sQ%s %s %s %s',
            self::n($cx + (($edges[0] * self::CELL_WIDTH) / 2)),
            self::n($cy),
            self::n($cx),
            self::n($cy),
            self::n($cx),
            self::n($cy + (($edges[1] * self::CELL_HEIGHT) / 2)),
        ));

        return true;
    }

    /**
     * @param array{int, int, int, int} $arms
     * @param string                    $dash the dash attribute, or none
     */
    private function box(array $arms, string $dash, int $row, int $col, string $fg): true
    {
        [$cx, $cy] = $this->centre($row, $col);
        foreach ($arms as $arm => $weight) {
            if (0 === $weight) {
                continue;
            }
            [$dx, $dy] = self::ARMS[$arm];
            // A double line is two light ones, either side of the centre line.
            foreach (3 === $weight ? [-1.5, 1.5] : [0.0] as $offset) {
                $this->stroke($fg, 2 === $weight ? '2.4' : '1.2', $dash, \sprintf(
                    'M%s %sL%s %s',
                    self::n($cx + ($dy * $offset)),
                    self::n($cy + ($dx * $offset)),
                    self::n($cx + (($dx * self::CELL_WIDTH) / 2) + ($dy * $offset)),
                    self::n($cy + (($dy * self::CELL_HEIGHT) / 2) + ($dx * $offset)),
                ));
            }
        }

        return true;
    }

    /** @return array{float, float} */
    private function centre(int $row, int $col): array
    {
        return [$this->x($col) + (self::CELL_WIDTH / 2), $this->y($row) + (self::CELL_HEIGHT / 2)];
    }

    private function stroke(string $colour, string $width, string $dash, string $path): void
    {
        $key = $colour.'|'.$width.'|'.$dash;
        $this->strokes[$key] = ($this->strokes[$key] ?? '').$path;
    }

    /** @param array{int, string}|null $background */
    private function flushBackground(int $row, ?array $background, int $end): void
    {
        if (null === $background || self::DEFAULT_BG === $background[1] || $end <= $background[0]) {
            return;
        }
        $this->backgrounds[] = \sprintf(
            '<rect x="%s" y="%s" width="%s" height="%s" fill="%s"/>',
            self::n($this->x($background[0])),
            self::n($this->y($row)),
            self::n(($end - $background[0]) * self::CELL_WIDTH),
            self::n(self::CELL_HEIGHT),
            $background[1],
        );
    }

    private function flushRun(int $row, ?TextRun $run): void
    {
        if (null === $run) {
            return;
        }
        $style = $run->style;
        $attributes = \sprintf(' fill="%s"', $style->foreground());
        $attributes .= $style->bold ? ' font-weight="bold"' : '';
        $attributes .= $style->italic ? ' font-style="italic"' : '';
        $attributes .= $style->underline ? ' text-decoration="underline"' : '';
        $attributes .= $style->dim ? ' opacity="0.6"' : '';
        // One <text> per phrase: a renderer collapses runs of spaces, which
        // `textLength` would then make up for by stretching the glyphs. A lone
        // space between words survives, and keeps the element count down.
        $pieces = \preg_split('/( {2,})/', $run->text, flags: \PREG_SPLIT_DELIM_CAPTURE);
        $column = $run->column;
        foreach (false === $pieces ? [] : $pieces as $piece) {
            $phrase = \trim($piece, characters: ' ');
            if ('' !== $phrase) {
                $this->glyphs[] = \sprintf(
                    '<text x="%s" y="%s" textLength="%s" lengthAdjust="spacingAndGlyphs"%s>%s</text>',
                    self::n($this->x($column + \strlen($piece) - \strlen(\ltrim($piece, characters: ' ')))),
                    self::n($this->y($row) + self::BASELINE),
                    self::n(AnsiUtils::visibleWidth($phrase) * self::CELL_WIDTH),
                    $attributes,
                    self::xml($phrase),
                );
            }
            $column += AnsiUtils::visibleWidth($piece);
        }
    }

    /** @param array{int, int}|null $cursor */
    private function document(string $title, ?array $cursor): string
    {
        $width = ($this->columns * self::CELL_WIDTH) + (2 * self::PADDING);
        $height = ($this->rows * self::CELL_HEIGHT) + (2 * self::PADDING) + self::TITLE_BAR;
        $strokes = '';
        foreach ($this->strokes as $key => $path) {
            [$colour, $strokeWidth, $dash] = \explode('|', $key, limit: 3) + ['', '', ''];
            $strokes .= \sprintf('<path d="%s" stroke="%s" stroke-width="%s"%s/>', $path, $colour, $strokeWidth, $dash);
        }
        $caret = null === $cursor
            ? ''
            : \sprintf(
                '<rect x="%s" y="%s" width="2" height="%s" fill="%s"/>',
                self::n($this->x($cursor[1])),
                self::n($this->y($cursor[0])),
                self::n(self::CELL_HEIGHT),
                self::DEFAULT_FG,
            );
        $dots = '';
        foreach (['#ff5f57', '#febc2e', '#28c840'] as $i => $colour) {
            $dots .= \sprintf('<circle cx="%s" cy="%s" r="6" fill="%s"/>', self::n(self::PADDING + 8 + ($i * 20)), self::n((self::TITLE_BAR / 2) + 2), $colour);
        }
        $heading = '' === $title
            ? ''
            : \sprintf('<text x="%s" y="%s" text-anchor="middle" fill="#8a8a8a" font-size="13">%s</text>', self::n($width / 2), self::n((self::TITLE_BAR / 2) + 6), self::xml($title));

        return \sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$s %2$s" width="%1$s" height="%2$s" font-family="%3$s" font-size="%4$s">'
            .'<rect width="%1$s" height="%2$s" rx="10" fill="%5$s"/>%6$s%7$s'
            .'<g shape-rendering="crispEdges">%8$s</g>%12$s<g fill="none" stroke-linecap="square">%9$s</g>'
            .'<g xml:space="preserve" style="white-space:pre">%10$s</g>%11$s</svg>'
            ."\n",
            self::n($width),
            self::n($height),
            self::xml(self::FONT),
            self::n(self::FONT_SIZE),
            self::DEFAULT_BG,
            $dots,
            $heading,
            \implode('', [...$this->backgrounds, ...$this->blocks]),
            $strokes,
            \implode('', $this->glyphs),
            $caret,
            $this->pictures(),
        );
    }

    /**
     * Each placed picture, stretched over its whole placement and clipped to
     * the cells that show it — an overlay or the pane edge may hide the rest.
     */
    private function pictures(): string
    {
        $pictures = '';
        foreach ($this->imageCells as $id => $cells) {
            $png = $this->images->png($id);
            $size = $this->images->size($id);
            if (null === $png || null === $size) {
                continue;
            }
            [$row, $col, $pieceRow, $pieceCol] = $cells[0];
            $clip = '';
            foreach ($cells as [$cellRow, $cellCol]) {
                $clip .= \sprintf('M%s %sh%sv%sh-%sz', self::n($this->x($cellCol)), self::n($this->y($cellRow)), self::n(self::CELL_WIDTH), self::n(self::CELL_HEIGHT), self::n(self::CELL_WIDTH));
            }
            $pictures .= \sprintf(
                '<clipPath id="picture-%1$d"><path d="%2$s"/></clipPath><image clip-path="url(#picture-%1$d)" x="%3$s" y="%4$s" width="%5$s" height="%6$s" preserveAspectRatio="none" href="data:image/png;base64,%7$s"/>',
                $id,
                $clip,
                self::n($this->x($col - $pieceCol)),
                self::n($this->y($row - $pieceRow)),
                self::n($size[0] * self::CELL_WIDTH),
                self::n($size[1] * self::CELL_HEIGHT),
                \base64_encode($png),
            );
        }

        return $pictures;
    }

    private function x(int $col): float
    {
        return self::PADDING + ($col * self::CELL_WIDTH);
    }

    private function y(int $row): float
    {
        return self::PADDING + self::TITLE_BAR + ($row * self::CELL_HEIGHT);
    }

    /** A coordinate, short: no trailing zeros, two decimals at most. */
    private static function n(float $value): string
    {
        return \rtrim(\rtrim(\number_format($value, decimals: 2, decimal_separator: '.', thousands_separator: ''), characters: '0'), characters: '.');
    }

    private static function xml(string $text): string
    {
        return \htmlspecialchars($text, flags: \ENT_XML1 | \ENT_QUOTES | \ENT_SUBSTITUTE, encoding: 'UTF-8');
    }
}
