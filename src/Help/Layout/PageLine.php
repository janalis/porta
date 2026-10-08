<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

/**
 * One rendered row of the page, with what lies on it: link spans, the source
 * bytes behind its cells, the anchors that lead to it, and the source line its
 * block starts on.
 */
final readonly class PageLine
{
    /**
     * @param list<array{int, int, string, int}>         $links   col, end col, target, group
     * @param list<array{int, int, int, int, string}>    $cells   col, end col, source start, source end, the text when it is the source verbatim ('' otherwise)
     * @param list<string>                               $anchors
     */
    public function __construct(
        public string $text = '',
        public int $width = 0,
        public int $origin = 0,
        public array $links = [],
        public array $cells = [],
        public array $anchors = [],
    ) {}

    /** $count columns of plain space. */
    public static function spaces(int $count): self
    {
        $count = \max(0, $count);

        return new self(\str_repeat(' ', $count), $count);
    }

    public static function blank(int $origin): self
    {
        return new self('', 0, $origin);
    }

    /** This line drawn after $prefix: every span shifted past it, and the prefix's own spans kept. */
    public function after(self $prefix): self
    {
        $shift = $prefix->width;
        $links = $prefix->links;
        foreach ($this->links as [$col, $end, $target, $group]) {
            $links[] = [$col + $shift, $end + $shift, $target, $group];
        }
        $cells = $prefix->cells;
        foreach ($this->cells as [$col, $end, $start, $stop, $text]) {
            $cells[] = [$col + $shift, $end + $shift, $start, $stop, $text];
        }

        return new self($prefix->text.$this->text, $prefix->width + $this->width, $this->origin, $links, $cells, [...$prefix->anchors, ...$this->anchors]);
    }

    /** This line followed by $suffix on the same row. */
    public function then(self $suffix): self
    {
        return $suffix->after($this)->withOrigin($this->origin);
    }

    public function withOrigin(int $origin): self
    {
        return new self($this->text, $this->width, $origin, $this->links, $this->cells, $this->anchors);
    }

    public function withAnchor(string $anchor): self
    {
        return new self($this->text, $this->width, $this->origin, $this->links, $this->cells, [...$this->anchors, $anchor]);
    }
}
