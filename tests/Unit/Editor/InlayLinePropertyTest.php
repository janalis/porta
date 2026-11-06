<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Editor\InlayHint;
use Porta\Editor\InlayLine;
use Porta\Editor\WrapLayout;

/**
 * {@see InlayLine}'s laws, over many generated lines rather than chosen ones.
 *
 * The caret, a click, a drag, a hover and the renderer each fixed the mapping
 * on their own before it became one class — a click right of a hint landed by
 * the hint's width, a hint pushed wrapped code out of view. The cases in
 * InlayLineTest pin each boundary; these say what must hold for every line,
 * so a change that keeps those cases and breaks a combination nobody wrote
 * down still fails: wide glyphs beside hints, hints sharing a column, hints
 * with margins, every wrap width from none to narrower than a hint.
 */
final class InlayLinePropertyTest extends TestCase
{
    private const array GLYPHS = ['a', 'b', ' ', '(', '$', 'é', '好', '世'];

    public function testTheMappingHoldsItsLawsOverGeneratedLines(): void
    {
        \mt_srand(seed: 20_261_106); // seeded: every run walks the same cases, and a failure names its own
        for ($case = 0; $case < 400; ++$case) {
            $text = '';
            for ($length = self::roll(0, max: 14), $i = 0; $i < $length; ++$i) {
                $text .= self::GLYPHS[self::roll(0, \count(self::GLYPHS) - 1)];
            }
            $doc = new Document($text."\n");
            $starts = self::characterStarts($text);
            $hints = [];
            for ($count = self::roll(0, max: 4), $i = 0; $i < $count; ++$i) {
                $hints[] = new InlayHint(
                    $starts[self::roll(0, \count($starts) - 1)],
                    \str_repeat('x', times: self::roll(1, max: 3)),
                    padLeft: 1 === self::roll(0, max: 1),
                    padRight: 1 === self::roll(0, max: 1),
                );
            }
            $wrap = 0 === self::roll(0, max: 2) ? null : self::roll(2, max: 9);
            $line = InlayLine::place($hints, $doc, 0, $wrap);
            $this->assertLaws(
                $line,
                $doc,
                $starts,
                $wrap,
                \json_encode([$text, $wrap, \array_map(static fn (InlayHint $h): array => [$h->col, $h->label, $h->padLeft, $h->padRight], $hints)], \JSON_THROW_ON_ERROR),
            );
        }
    }

    /** @param list<int> $starts the byte offset of every character, and the line's end */
    private function assertLaws(InlayLine $line, Document $doc, array $starts, ?int $wrap, string $case): void
    {
        $previous = -1;
        foreach ($starts as $byte) {
            $column = $doc->displayColAt(0, $byte);
            $screen = $line->screenCol($column);
            $this->assertGreaterThan($previous, $screen, "screen columns only move right — {$case}");
            $previous = $screen;
            $this->assertSame(['displayCol' => $column, 'hint' => null], $line->hit($screen), "a click where a character is drawn answers that character — {$case}");
        }
        $end = $line->screenCol($doc->lineDisplayWidth(0));
        for ($screen = 0; $screen <= $end; ++$screen) {
            $hit = $line->hit($screen);
            if (null === $hit['hint']) {
                continue;
            }
            // Right of it, past every hint at its column — they are drawn together.
            $after = $line->screenCol($hit['displayCol']);
            $there = \array_sum(\array_map(static fn (array $at): int => $hit['displayCol'] === $at['displayCol'] ? $at['hint']->width() : 0, $line->placed()));
            $this->assertTrue($after > $screen && ($after - $there) <= $screen, "a cell on a hint answers the character just right of it — {$case}");
        }
        if (null === $wrap) {
            return;
        }
        $last = new WrapLayout($doc)->rowsFor(0, $wrap) - 1;
        for ($segment = 0; $segment < $last; ++$segment) {
            $this->assertSame($wrap, $line->rowStart($segment + 1, $wrap) - $line->rowStart($segment, $wrap), "a full row holds its text and no hint — {$case}");
        }
        $this->assertLessThanOrEqual($wrap, $end - $line->rowStart($last, $wrap), "the last row's text and hints fit the width — {$case}");
    }

    private static function roll(int $min, int $max): int
    {
        // @noinspection RandomApiMigrationInspection -- seeded on purpose: random_int() cannot replay a case
        return \mt_rand($min, $max);
    }

    /** @return list<int> */
    private static function characterStarts(string $text): array
    {
        $starts = [0];
        foreach (\mb_str_split($text) as $character) {
            $starts[] = \end($starts) + \strlen($character);
        }

        return $starts;
    }
}
