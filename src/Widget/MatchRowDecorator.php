<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Support\AnsiText;
use Porta\Ui\Sgr;
use Porta\Widget\Render\LineDecorator;

/**
 * Renders a search-result row: truncate/pad the row $text to $width over the
 * row background $bg, then tint each match range with $searchBg. Shared by the
 * find-in-files modal and the results panel, which produced byte-identical
 * copies of this truncate-then-highlight loop.
 */
final class MatchRowDecorator
{
    /**
     * @param string                                $searchBg    the match tint's open SGR ({@see \Symfony\Component\Tui\Style\Style::getAnsiRestore()})
     * @param string                                $searchClose its attribute-matched reset ({@see Sgr::resetFor()}) — cancels reverse/underline (Monochrome) before the row bg restores the tail
     * @param list<array{start: int, length: int}>  $ranges      match spans relative to the text start
     */
    public static function highlight(string $text, int $width, string $bg, int $prefixWidth, string $searchBg, string $searchClose, array $ranges): string
    {
        // Close each highlight by cancelling its own attribute, then re-setting
        // the row bg so the tail keeps its colour.
        $line = $bg.AnsiText::truncate($text, $width, pad: true).Sgr::RESET;
        foreach ($ranges as $range) {
            $start = $prefixWidth + $range['start'];
            $line = LineDecorator::apply($line, $start, $start + $range['length'], $searchBg, $searchClose.$bg, $searchBg);
        }

        return $line;
    }
}
