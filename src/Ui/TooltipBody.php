<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * What a tooltip card holds when plain words are not enough: lines that each
 * keep their own role, which wrapping a styled string cannot (a span cut by a
 * wrap would bleed onto the next row), and how much room it may take — a
 * problem's message is read, not glanced at, so its card is allowed more.
 */
final readonly class TooltipBody
{
    /** The widest a card's text runs before it wraps, in columns. */
    public const int MAX_TEXT = 48;

    /** A tall text is cut here and ends in an ellipsis: a tooltip is a glance, not a page. */
    public const int MAX_LINES = 8;

    /**
     * @param list<TooltipLine> $lines
     */
    public function __construct(
        public array $lines,
        public int $maxText = self::MAX_TEXT,
        public int $maxLines = self::MAX_LINES,
    ) {}

    /** A plain card's text: each `\n` a line, a key after a tab — `Match case\tAlt+C`. */
    public static function fromText(string $text): self
    {
        $lines = [];
        foreach (\explode("\n", $text) as $paragraph) {
            [$words, $key] = [...\explode("\t", $paragraph, limit: 2), ''];
            $lines[] = new TooltipLine($words, $key);
        }

        return new self($lines);
    }

    /** The words without their styling, in the plain card's text form — what tells two bodies apart. */
    public function plain(): string
    {
        return \implode("\n", \array_map(
            static fn (TooltipLine $line): string => $line->lead.$line->text.('' === $line->key ? '' : "\t".$line->key),
            $this->lines,
        ));
    }
}
