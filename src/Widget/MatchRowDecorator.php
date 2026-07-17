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
     * @param list<array{start: int, length: int}> $ranges match spans relative to the text start
     */
    public static function highlight(string $text, int $width, string $bg, int $prefixWidth, string $searchBg, array $ranges): string
    {
        // Close each highlight with the row bg so the tail keeps its colour.
        $line = $bg.AnsiText::truncate($text, $width, pad: true).Sgr::RESET;
        foreach ($ranges as $range) {
            $start = $prefixWidth + $range['start'];
            $line = LineDecorator::apply($line, $start, $start + $range['length'], $searchBg, $bg, $searchBg);
        }

        return $line;
    }
}
