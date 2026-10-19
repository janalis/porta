<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Symfony\Component\Tui\Ansi\AnsiUtils;

/** Linear grapheme wrapping, yielding before a long label can monopolize the event loop. */
final class MermaidWrap
{
    /** @return \Generator<int, null, mixed, string> */
    public static function text(string $text, int $width): \Generator
    {
        $matches = [];
        \preg_match_all('/\X/u', $text, $matches);
        $lines = [];
        $line = '';
        $columns = 0;
        foreach ($matches[0] as $character) {
            $size = AnsiUtils::visibleWidth($character);
            if ("\n" === $character || ($columns > 0 && ($columns + $size) > $width)) {
                $lines[] = $line;
                $line = '';
                $columns = 0;
            }
            if ("\n" !== $character) {
                $line .= $character;
                $columns += $size;
            }
            yield null;
        }
        $lines[] = $line;

        return \implode("\n", $lines);
    }
}
