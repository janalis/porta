<?php

declare(strict_types=1);

namespace Porta\Ui\Markup;

use Porta\Support\AnsiText;

/**
 * One span of parsed markup: its tag's spec (null for the root) and what it
 * holds, in order — plain text already unescaped, or nested spans. It paints
 * as {@see runs()}: a single span is byte for byte `$style->apply($text)`, and
 * a nested one takes the merged style of every tag around it.
 */
final readonly class MarkupNode
{
    /** @param list<string|MarkupNode> $children */
    public function __construct(
        public ?StyleSpec $spec,
        public array $children,
    ) {}

    /** The text alone, every tag dropped. */
    public function plain(): string
    {
        $text = '';
        foreach ($this->children as $child) {
            $text .= \is_string($child) ? $child : $child->plain();
        }

        return $text;
    }

    /**
     * The text as flat runs, each carrying the specs of the spans around it.
     *
     * @param list<StyleSpec> $around
     *
     * @return list<MarkupRun>
     */
    public function runs(array $around = []): array
    {
        $specs = null === $this->spec ? $around : [...$around, $this->spec];
        $runs = [];
        foreach ($this->children as $child) {
            \array_push($runs, ...\is_string($child) ? [new MarkupRun($child, $specs)] : $child->runs($specs));
        }

        return $runs;
    }

    /**
     * The runs word-wrapped to $width columns ({@see AnsiText::wrapProse()} over the
     * plain text), one list per line — so a styled span a break falls inside
     * is styled on both lines rather than left open across the break.
     *
     * @return non-empty-list<list<MarkupRun>>
     */
    public function lines(int $width): array
    {
        $runs = $this->runs();
        $plain = $this->plain();
        $lines = [];
        $cursor = 0;
        foreach (AnsiText::wrapProse($plain, $width) as $row => $line) {
            if ($row > 0 && ' ' === ($plain[$cursor] ?? '')) {
                ++$cursor; // the space a word break consumed; a break inside a word, hard or after its hyphen, consumes none
            }
            $lines[] = MarkupRun::slice($runs, $cursor, \strlen($line));
            $cursor += \strlen($line);
        }

        return $lines;
    }
}
