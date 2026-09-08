<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Ui\Glyphs;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Screen-scrape helpers for E2e assertions on the composed frame: the full
 * grid as plain rows, and row lookup by content. Extracted with the
 * IdeEdgeCasesTest split — several suites read the screen the same way.
 */
trait ScreenReads
{
    /** Satisfied by the host TestCase (PHPUnit's Assert::fail). */
    abstract public static function fail(string $message = ''): never;

    /**
     * Force a full frame and return the screen as stripped text rows. A forced
     * render resets the differential writer, so the output is the clear/home
     * prefix followed by every row joined by CRLF — splittable into a grid,
     * unlike the incremental updates a normal render emits.
     *
     * @return list<string>
     */
    private function screenGrid(Tui $tui, VirtualTerminal $terminal): array
    {
        $terminal->consumeOutput(); // drop anything buffered so far
        $tui->requestRender(force: true); // reset the writer -> next frame is full
        $tui->processRender();
        $raw = $terminal->consumeOutput();
        $body = preg_replace('/^.*\x1b\[H/s', replacement: '', subject: $raw); // strip up to cursor-home

        return array_map(
            AnsiUtils::stripAnsiCodes(...),
            explode("\r\n", (string) $body),
        );
    }

    /**
     * The index of the first grid row containing $needle, or null.
     *
     * @param list<string> $grid
     */
    private function rowOf(array $grid, string $needle): ?int
    {
        foreach ($grid as $i => $row) {
            if (str_contains($row, $needle)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The framed card whose top border carries $title, as its own text.
     *
     * Assert against THIS rather than against the whole screen. A picker draws
     * over the buffer, and its rows are usually drawn FROM the buffer — symbol
     * names, file names, copied lines — so a screen-wide
     * `assertStringContainsString('greet', ...)` passes whether the popup lists
     * `greet` or nothing at all. Measured twice, by replacing every row's label
     * and watching two suites stay green.
     *
     * @param list<string> $grid
     */
    private function card(array $grid, string $title): string
    {
        $top = $this->rowOf($grid, $title);
        if (null === $top || !str_contains($grid[$top], Glyphs::MODAL_TOP_LEFT)) {
            self::fail(\sprintf('no card titled "%s" is on screen', $title));
        }

        $card = [];
        for ($row = $top; $row < \count($grid); ++$row) {
            $card[] = $grid[$row];
            if (str_contains($grid[$row], Glyphs::MODAL_BOTTOM_LEFT)) {
                return implode("\n", $card);
            }
        }

        self::fail(\sprintf('the card titled "%s" has no bottom border', $title));
    }

    /**
     * Force a full render pass so widget rects and rendered glyphs reflect the
     * current tree. A bare tick() renders only when a change already requested
     * one; reading getWidgetRect (or a rendered row) behind a plain delay races
     * the first frame. This is the force-frame idiom screenGrid() uses.
     */
    private function frame(Tui $tui): void
    {
        $tui->requestRender(force: true);
        $tui->processRender();
    }
}
