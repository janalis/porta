<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Symfony\Component\Tui\Ansi\AnsiUtils;

/** Fixed-width cells keep wrapped keys and values aligned in cells, SVG and PNG. */
final class UsecaseTable
{
    /** @param list<array{key: string, value: string}> $rows
     * @return \Generator<int, null, mixed, array{label: string, drawLabel: string, shape: string}>
     */
    public static function label(string $title, array $rows): \Generator
    {
        $keyWidth = 4;
        $valueWidth = 8;
        foreach ($rows as $row) {
            $keyWidth = \min(24, \max($keyWidth, AnsiUtils::visibleWidth($row['key'])));
            $valueWidth = \min(40, \max($valueWidth, AnsiUtils::visibleWidth($row['value'])));
        }
        $total = $keyWidth + $valueWidth + 3;
        $wrap = MermaidWrap::text($title, $total);
        yield from $wrap;
        $titleLines = \explode("\n", $wrap->getReturn());
        $label = \implode("\n", $titleLines);
        $drawLabel = \str_repeat("\n", \count($titleLines) - 1);
        $dividers = [];
        foreach ($rows as $row) {
            $dividers[] = \substr_count($label, needle: "\n") + 1;
            $key = MermaidWrap::text($row['key'], $keyWidth);
            yield from $key;
            $value = MermaidWrap::text($row['value'], $valueWidth);
            yield from $value;
            $keys = \explode("\n", $key->getReturn());
            $values = \explode("\n", $value->getReturn());
            $lines = [];
            for ($index = 0; $index < \max(\count($keys), \count($values)); ++$index) {
                $left = self::pad($keys[$index] ?? '', $keyWidth);
                $right = self::pad($values[$index] ?? '', $valueWidth);
                $lines[] = $left.'   '.$right;
            }
            $body = "\n\n".\implode("\n", $lines);
            $label .= $body;
            $drawLabel .= $body;
            yield null;
        }

        return ['label' => $label, 'drawLabel' => $drawLabel, 'shape' => 'uc-json:'.$keyWidth.':'.\implode(',', $dividers)];
    }

    private static function pad(string $text, int $width): string
    {
        return $text.\str_repeat(' ', \max(0, $width - AnsiUtils::visibleWidth($text)));
    }
}
