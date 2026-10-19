<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphFont;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/** A styled label line as text marks: each run placed after the previous one, every bundled face sharing one advance. */
final class MermaidRuns
{
    /**
     * One line centered on `$x`.
     *
     * @param list<array{string, GraphFont}> $runs
     * @return list<MermaidMark>
     */
    public static function centered(array $runs, float $x, float $y, string $color, float $fontScale): array
    {
        if (1 === \count($runs)) {
            return [new MermaidMark('text', [[$x, $y]], $runs[0][0], stroke: $color, font: $runs[0][1], fontScale: $fontScale)];
        }

        return self::from($runs, $x - ((self::width($runs) * $fontScale) / 2), $y, $color, $fontScale);
    }

    /**
     * One line starting at `$left`.
     *
     * @param list<array{string, GraphFont}> $runs
     * @return list<MermaidMark>
     */
    public static function from(array $runs, float $left, float $y, string $color, float $fontScale): array
    {
        $marks = [];
        foreach ($runs as [$text, $font]) {
            // Drawn text drops edge spaces, so each run is centered on what it shows.
            $lead = \strlen($text) - \strlen(\ltrim($text, characters: ' '));
            $shown = \trim($text, characters: ' ');
            if ('' !== $shown) {
                $marks[] = new MermaidMark('text', [[$left + (($lead + (AnsiUtils::visibleWidth($shown) / 2)) * $fontScale), $y]], $shown, stroke: $color, font: $font, fontScale: $fontScale);
            }
            $left += AnsiUtils::visibleWidth($text) * $fontScale;
        }

        return $marks;
    }

    /** @param list<array{string, GraphFont}> $runs */
    private static function width(array $runs): int
    {
        return \array_sum(\array_map(static fn (array $run): int => AnsiUtils::visibleWidth($run[0]), $runs));
    }
}
