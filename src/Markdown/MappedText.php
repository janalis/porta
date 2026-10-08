<?php

declare(strict_types=1);

namespace Porta\Markdown;

/**
 * Text gathered from the source in pieces — a paragraph's lines without their
 * indentation, a table cell with its `\|` unescaped — together with where each
 * piece came from. The inline parser works on the string and asks this for the
 * source byte behind any position in it.
 */
final readonly class MappedText
{
    /**
     * @param list<array{int, int}> $pieces each piece's [offset in $text, offset in the source], ascending
     */
    public function __construct(
        public string $text,
        private array $pieces,
    ) {}

    /** One verbatim run of source text starting at $source. */
    public static function verbatim(string $text, int $source): self
    {
        return new self($text, [[0, $source]]);
    }

    /** The source offset of the byte at $position (or of the end, for the length). */
    public function source(int $position): int
    {
        $low = 0;
        $high = \count($this->pieces) - 1;
        while ($low < $high) {
            $mid = ($low + $high + 1) >> 1;
            $fits = $this->pieces[$mid][0] <= $position;
            $low = $fits ? $mid : $low;
            $high = $fits ? $high : $mid - 1;
        }
        [$at, $source] = $this->pieces[$low] ?? [0, 0];

        return $source + $position - $at;
    }

    /** The source range behind [$start, $end) of the text. */
    public function range(int $start, int $end): SourceRange
    {
        if ($end <= $start) {
            $at = $this->source($start);

            return new SourceRange($at, $at);
        }

        return new SourceRange($this->source($start), $this->source($end - 1) + 1);
    }

    /** The part from $start on, still mapped. */
    public function from(int $start): self
    {
        $pieces = [[0, $this->source($start)]];
        foreach ($this->pieces as [$at, $source]) {
            if ($at <= $start) {
                continue;
            }
            $pieces[] = [$at - $start, $source];
        }

        return new self(\substr($this->text, $start), $pieces);
    }

    /** The first $length bytes, still mapped. */
    public function head(int $length): self
    {
        $pieces = \array_values(\array_filter($this->pieces, static fn (array $piece): bool => $piece[0] < \max(1, $length)));

        return new self(\substr($this->text, offset: 0, length: $length), $pieces);
    }

    /** This text followed by $next, both keeping their sources. */
    public function append(self $next): self
    {
        $offset = \strlen($this->text);
        $pieces = $this->pieces;
        foreach ($next->pieces as [$at, $source]) {
            $pieces[] = [$at + $offset, $source];
        }

        return new self($this->text.$next->text, $pieces);
    }
}
