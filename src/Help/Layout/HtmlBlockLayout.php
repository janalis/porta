<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

use Porta\Markdown\Node\HtmlBlock;
use Porta\Support\AnsiText;
use Porta\Ui\Role;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A raw HTML block. A comment draws nothing; anything else is shown as
 * written, muted, so the reader sees there is markup the page does not draw.
 */
final readonly class HtmlBlockLayout
{
    public function __construct(
        private StylePen $pen,
    ) {}

    /** @return list<PageLine> */
    public function lines(HtmlBlock $html, int $width, LayoutState $state): array
    {
        if (1 === \preg_match('/^\s*<!--.*-->\s*$/s', $html->literal)) {
            return [];
        }
        $first = $state->lineOf($html->range->start);
        $lines = [];
        foreach (\explode("\n", $html->literal) as $index => $line) {
            $text = AnsiText::truncate(\str_replace("\t", replace: '    ', subject: $line), $width);
            $lines[] = new PageLine($this->pen->role($text, Role::Muted), AnsiUtils::visibleWidth($text), $first + $index);
        }

        return $lines;
    }
}
