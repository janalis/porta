<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Ui\Glyphs;
use Porta\Ui\Theme\Styles;
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
        $body = \preg_replace('/^.*\x1b\[H/s', replacement: '', subject: $raw); // strip up to cursor-home

        return \array_map(
            AnsiUtils::stripAnsiCodes(...),
            \explode("\r\n", (string) $body),
        );
    }

    /**
     * {@see screenGrid()} with every blank cell painted in the modal shadow's
     * background drawn as ░, so a test can see where a shadow landed. The
     * shadow's colour is read from the dark theme's own rule, not repeated.
     *
     * @return list<string>
     */
    private function shadowGrid(Tui $tui, VirtualTerminal $terminal): array
    {
        $terminal->consumeOutput();
        $this->frame($tui);
        $body = (string) \preg_replace('/^.*\x1b\[H/s', replacement: '', subject: $terminal->consumeOutput());
        $shadow = self::backgroundsOf(Styles::dark()->rule('.modal::shadow')->apply('x'))[0]['bg'];

        return \array_map(
            static fn (array $cells): string => \implode('', \array_map(
                static fn (array $cell): string => ' ' === $cell['glyph'] && null !== $shadow && $shadow === $cell['bg'] ? '░' : $cell['glyph'],
                $cells,
            )),
            \array_map(self::backgroundsOf(...), \explode("\r\n", $body)),
        );
    }

    /**
     * Each printed grapheme of a painted row with the background it was
     * painted on (the SGR 48 parameters, as written), escapes dropped.
     *
     * @return list<array{glyph: string, bg: ?string}>
     */
    private static function backgroundsOf(string $row): array
    {
        $tokens = [];
        \preg_match_all('/\x1b\[([0-9;]*)m|\x1b_[^\x07]*\x07|\x1b\[[0-9;?]*[ -\/]*[@-~]|\X/u', $row, $tokens, \PREG_SET_ORDER);
        $bg = null;
        $cells = [];
        foreach ($tokens as $token) {
            if (\str_starts_with($token[0], "\x1b")) {
                $params = $token[1] ?? null;
                if (null !== $params && \str_ends_with($token[0], 'm')) {
                    $bg = self::backgroundAfter($bg, '' === $params ? [0] : \array_map(\intval(...), \explode(';', $params)));
                }
                continue;
            }
            $cells[] = ['glyph' => $token[0], 'bg' => $bg];
        }

        return $cells;
    }

    /**
     * The background an SGR's parameters leave: reset by 0 or 49, set by
     * 48;5;n or 48;2;r;g;b, untouched by anything else (a 38 colour's own
     * parameters are skipped, so they are never read as a 48).
     *
     * @param list<int> $params
     */
    private static function backgroundAfter(?string $bg, array $params): ?string
    {
        for ($i = 0, $count = \count($params); $i < $count; ++$i) {
            $param = $params[$i];
            $wide = 2 === ($params[$i + 1] ?? null) ? 4 : 2; // 2;r;g;b or 5;n
            $bg = match ($param) {
                0, 49 => null,
                48 => \implode(';', \array_slice($params, $i + 1, $wide)),
                default => $bg,
            };
            if (38 === $param || 48 === $param) {
                $i += $wide;
            }
        }

        return $bg;
    }

    /**
     * The index of the first grid row containing $needle, or null.
     *
     * @param list<string> $grid
     */
    private function rowOf(array $grid, string $needle): ?int
    {
        foreach ($grid as $i => $row) {
            if (\str_contains($row, $needle)) {
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
        if (null === $top || !\str_contains($grid[$top], Glyphs::MODAL_TOP_LEFT)) {
            self::fail(\sprintf('no card titled "%s" is on screen', $title));
        }

        $card = [];
        for ($row = $top, $height = \count($grid); $row < $height; ++$row) {
            $card[] = $grid[$row];
            if (\str_contains($grid[$row], Glyphs::MODAL_BOTTOM_LEFT)) {
                return \implode("\n", $card);
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
