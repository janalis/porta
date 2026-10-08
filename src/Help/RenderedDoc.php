<?php

declare(strict_types=1);

namespace Porta\Help;

use Porta\Markdown\SourceText;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A markdown document rendered for one pane width: the styled ANSI lines, the
 * link spans keyed by rendered position (for Tab-cycling and hit-testing), the
 * source behind each line — the line its block starts on, and the bytes under
 * each cell — and the line each anchor (a heading, a footnote) leads to.
 */
final readonly class RenderedDoc
{
    /**
     * @param list<string>                                  $lines
     * @param list<LinkSpan>                                $links   ordered by group, then line
     * @param list<int>                                     $origins for each rendered line, the 0-based source line its block starts on
     * @param list<list<array{int, int, int, int, string}>> $cells   for each rendered line, its runs: col, end col, source start, source end, the source text when drawn verbatim
     * @param array<string, int>                            $anchors anchor => the rendered line it leads to
     */
    public function __construct(
        public array $lines,
        public array $links,
        public array $origins = [],
        public array $cells = [],
        public array $anchors = [],
        public ?SourceText $source = null,
    ) {}

    /** The number of distinct links (Tab cycles these groups). */
    public function groupCount(): int
    {
        $max = -1;
        foreach ($this->links as $span) {
            $max = \max($max, $span->group);
        }

        return $max + 1;
    }

    /** @return list<LinkSpan> every rendered fragment of one link */
    public function spansOfGroup(int $group): array
    {
        return \array_values(\array_filter($this->links, static fn (LinkSpan $span): bool => $span->group === $group));
    }

    /** The link target of a group, or null for an unknown group. */
    public function target(int $group): ?string
    {
        foreach ($this->links as $span) {
            if ($span->group === $group) {
                return $span->target;
            }
        }

        return null;
    }

    /**
     * The source byte drawn at display column $col of rendered line $line:
     * exact inside text drawn as written, the start of the run otherwise; null
     * where nothing of the source is drawn.
     */
    public function sourceAt(int $line, int $col): ?int
    {
        foreach ($this->cells[$line] ?? [] as [$start, $end, $source, , $text]) {
            if ($col < $start || $col >= $end || $source < 0) {
                continue;
            }

            return '' === $text ? $source : $source + \strlen(AnsiUtils::sliceByColumn($text, 0, $col - $start));
        }

        return null;
    }

    /**
     * Where in the source the cell at ($line, $col) was written, as the
     * editor's (line, byte column): exact inside text drawn as written, the
     * start of its block's first line elsewhere; null below the page.
     *
     * @return array{int, int}|null
     */
    public function sourcePointAt(int $line, int $col): ?array
    {
        $origin = $this->origins[$line] ?? null;
        if (null === $origin) {
            return null;
        }
        $byte = $this->sourceAt($line, $col);
        if (null === $byte || null === $this->source) {
            return [$origin, 0];
        }

        return [$this->source->lineOf($byte), $this->source->columnOf($byte)];
    }

    /** The rendered line an anchor (`#heading`, a footnote) leads to, matched as GitHub does. */
    public function anchor(string $name): ?int
    {
        return $this->anchors[$name] ?? $this->anchors[\mb_strtolower($name, encoding: 'UTF-8')] ?? null;
    }
}
