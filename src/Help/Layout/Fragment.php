<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

use Porta\Markdown\SourceRange;

/**
 * One styled run of a block's inline content, before wrapping: its text, how
 * it is drawn, the link it belongs to (a target and a group shared by every
 * piece of one link), where it was written, and an anchor a line holding it
 * answers to. A break fragment ends the line.
 */
final readonly class Fragment
{
    public function __construct(
        public string $text,
        public InlineStyle $style = new InlineStyle(),
        public ?string $target = null,
        public int $group = -1,
        public ?SourceRange $source = null,
        public bool $verbatim = false,
        public ?string $anchor = null,
        public bool $isBreak = false,
    ) {}

    public static function lineBreak(): self
    {
        return new self('', isBreak: true);
    }
}
