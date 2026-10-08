<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

use Porta\Markdown\Node\HtmlBlock;
use Porta\Support\AnsiText;
use Porta\Ui\Role;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A raw HTML block, drawn the way the README that wrote it meant: centred
 * `<p align="center">` headers, `<h1>`–`<h6>`, images and links, line breaks,
 * and the text between — the shapes GitHub's own pages are built from. A
 * comment draws nothing. A block holding structure this page cannot draw
 * (a `<table>`, a `<ul>`) is shown as written, muted, so the reader sees
 * there is markup and what it says.
 */
final readonly class HtmlBlockLayout
{
    /** The tags a block may hold and still be drawn rather than shown. */
    private const string DRAWN = '/^(?:p|div|center|h[1-6]|br|img|a|picture|source|b|strong|i|em|s|del|strike|code|tt|kbd|sub|sup|span|ins|u|small|big|mark|abbr|samp|var|font|details|summary|hr)$/i';

    public function __construct(
        private StylePen $pen,
        private ImageLayout $images = new ImageLayout(),
    ) {}

    /** @return list<PageLine> */
    public function lines(HtmlBlock $html, int $width, LayoutState $state): array
    {
        return $this->literal($html->literal, $html, $width, $state);
    }

    /**
     * $literal — all of $html's text, or what is left of it once a
     * `<details>` took its summary — drawn.
     *
     * @return list<PageLine>
     */
    public function literal(string $literal, HtmlBlock $html, int $width, LayoutState $state): array
    {
        $literal = \preg_replace('/<!--.*?-->/s', replacement: '', subject: $literal) ?? $literal;
        if ('' === \trim($literal)) {
            return [];
        }
        $origin = $state->lineOf($html->range->start);
        if (!self::drawable($literal)) {
            return $this->verbatim($html, $width, $origin, $state);
        }
        $lines = [];
        foreach (new HtmlParagraphs($state, $html->range)->of($literal) as $index => [$fragments, $center]) {
            if ($index > 0) {
                $lines[] = PageLine::blank($origin); // block elements stand apart, as a browser spaces them
            }
            foreach ($this->images->of($fragments, $width, $state) ?? $this->wrap($fragments, $width) as $line) {
                $lines[] = ($center ? $line->after(PageLine::spaces(\intdiv(\max(0, $width - $line->width), num2: 2))) : $line)->withOrigin($origin);
            }
        }

        return $lines;
    }

    /** Whether every tag in $literal is one this page knows how to draw. */
    private static function drawable(string $literal): bool
    {
        $m = [];
        \preg_match_all('/<\/?([A-Za-z][A-Za-z0-9-]*)/', $literal, $m);

        return \array_all($m[1], static fn (string $name): bool => 1 === \preg_match(self::DRAWN, $name));
    }

    /**
     * @param list<Fragment> $fragments
     *
     * @return list<PageLine>
     */
    private function wrap(array $fragments, int $width): array
    {
        return [] === $fragments ? [] : new Wrapper($this->pen)->wrap($fragments, $width);
    }

    /** @return list<PageLine> */
    private function verbatim(HtmlBlock $html, int $width, int $origin, LayoutState $state): array
    {
        $lines = [];
        foreach (\explode("\n", $html->literal) as $index => $line) {
            $text = AnsiText::truncate(\str_replace("\t", replace: '    ', subject: $line), $width);
            // Each row is its own source line: drawn as written, byte for byte; else its start.
            $exact = $text === $line ? $state->lineEndCells($origin + $index, $line, 0) : [];
            $start = $state->source->offsetOf($origin + $index, 0);
            $lines[] = new PageLine(
                $this->pen->role($text, Role::Muted),
                AnsiUtils::visibleWidth($text),
                $origin + $index,
                [],
                [] === $exact ? [[0, AnsiUtils::visibleWidth($text), $start, $start, '']] : $exact,
            );
        }

        return $lines;
    }
}
