<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\I18n\PaneReorder;
use Porta\Overlay\Region\FloatingRegions;
use Porta\Support\AnsiText;
use Porta\Tests\Support\TerminalReplay;
use Porta\Ui\BidiTui;
use Porta\Ui\PaneSegmenter;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * What reaches the SCREEN, not what the renderer composed.
 *
 * The suite grades composed frames everywhere else. But
 * {@see \Symfony\Component\Tui\Render\ScreenWriter} only writes the whole
 * screen once: from the second frame on it rewrites the changed row range and
 * positions itself with relative cursor moves. A full-render test cannot see a
 * defect in that path, because the path it takes is the other one. So these
 * drive several frames and replay the bytes.
 *
 * The RTL case earns its own frames: mirroring happens per pane between the
 * renderer and the writer, so a mirrored screen is exactly where a row-offset
 * bug shows up as content from one pane appearing in another's columns.
 */
final class DifferentialRepaintTest extends TestCase
{
    private const int COLUMNS = 40;
    private const int ROWS = 4;

    /** @var list<string> the lines the widget renders this frame */
    private static array $lines = [];

    private Renderer $renderer;

    private AbstractWidget $widget;

    // @mago-expect lint:no-boolean-flag-parameter -- the flag picks which of
    // the two render paths to build; splitting it would duplicate the rig.
    private function tui(VirtualTerminal $terminal, bool $rtl): BidiTui
    {
        $this->renderer = new Renderer(new StyleSheet());
        $tui = new BidiTui(terminal: $terminal, renderer: $this->renderer);
        $this->widget = new class extends AbstractWidget {
            public function render(RenderContext $context): array
            {
                return DifferentialRepaintTest::lines();
            }
        };
        $tui->add($this->widget);
        if ($rtl) {
            $tui->setPaneReorder(
                new PaneSegmenter($this->renderer, new class implements FloatingRegions {
                    public function regions(): array
                    {
                        return [];
                    }
                }),
                new PaneReorder(),
            );
        }

        return $tui;
    }

    /** @return list<string> */
    public static function lines(): array
    {
        return self::$lines;
    }

    /**
     * One two-pane row: a label pane, the separator, a value pane — the shape
     * every settings row has.
     */
    private static function row(string $left, string $right): string
    {
        $pane = \intdiv(self::COLUMNS - 1, num2: 2);

        return AnsiText::padRight($left, $pane).'|'.AnsiText::padRight($right, self::COLUMNS - 1 - $pane);
    }

    /** @param list<list<string>> $frames each frame's [left, right] rows */
    private function play(array $frames, bool $rtl, int $ambiguousWidth = 1): array
    {
        $terminal = new VirtualTerminal(columns: self::COLUMNS, rows: self::ROWS);
        $tui = $this->tui($terminal, $rtl);
        $replay = new TerminalReplay(self::COLUMNS, self::ROWS, $ambiguousWidth);
        $intended = [];

        foreach ($frames as $frame) {
            self::$lines = \array_map(static fn (array $pair): string => self::row($pair[0], $pair[1]), $frame);
            $terminal->clearOutput();
            // A widget caches its rendered lines; without this the second frame
            // re-serves the first and the differential path is never taken.
            $this->widget->invalidate();
            $tui->requestRender();
            $tui->processRender();
            $replay->feed($terminal->getOutput());
            $intended = self::$lines;
        }

        return [
            \array_map(rtrim(...), \array_slice($replay->rows(), offset: 0, length: \count($intended))),
            \array_map(static fn (string $l): string => \rtrim(AnsiUtils::stripAnsiCodes($l)), $intended),
        ];
    }

    public function testTheFirstFrameReachesTheScreenIntact(): void
    {
        // The baseline: if a full render did not replay exactly, nothing below
        // would mean anything.
        [$screen, $intended] = $this->play([[['name', 'value'], ['other', 'thing']]], rtl: false);

        $this->assertSame($intended, $screen);
    }

    /**
     * The invariant the differential path owes: a screen arrived at by
     * incremental repaints is the same screen a single render of the final
     * content produces. Stated this way it needs no mirror arithmetic of its
     * own — the full-render path IS the oracle, and it is the path every other
     * test in the suite already trusts.
     *
     * @param list<list<array{string, string}>> $frames
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sequences')]
    public function testAnIncrementallyPaintedScreenEqualsAFreshlyRenderedOne(array $frames, bool $rtl): void
    {
        [$incremental] = $this->play($frames, $rtl);
        [$fresh] = $this->play([$frames[\count($frames) - 1]], $rtl);

        $this->assertSame($fresh, $incremental);
    }

    /** @return iterable<string, array{list<list<array{string, string}>>, bool}> */
    public static function sequences(): iterable
    {
        $shalom = "\u{05E9}\u{05DC}\u{05D5}\u{05DD}";
        $ivrit = "\u{05E2}\u{05D1}\u{05E8}\u{05D9}\u{05EA}";

        yield 'one value changes' => [
            [
                [['name', 'value'], ['other', 'thing']],
                [['name', 'CHANGED'], ['other', 'thing']],
            ],
            false,
        ];

        yield 'both rows change' => [
            [
                [['name', 'value'], ['other', 'thing']],
                [['name', 'a'], ['other', 'b']],
            ],
            false,
        ];

        yield 'a row grows then shrinks' => [
            [
                [['name', 'v'], ['other', 'thing']],
                [
                    ['name',  'a much longer value'],
                    ['other', 'thing'],
                ],
                [['name', 'v'], ['other', 'thing']],
            ],
            false,
        ];

        yield 'mirrored, stepping values' => [
            [
                [[$shalom, 'dark'], [$ivrit, 'bar']],
                [[$shalom, 'light'], [$ivrit, 'bar']],
                [[$shalom, 'light'], [$ivrit, 'block']],
            ],
            true,
        ];

        yield 'mirrored, the label changes too' => [
            [
                [[$shalom, 'dark'], [$ivrit, 'bar']],
                [[$ivrit, 'dark'], [$shalom, 'bar']],
            ],
            true,
        ];
    }

    public function testAnOverWideRowWrapsAndPushesEveryLaterRowOffByOne(): void
    {
        // The mechanism, made visible: the row is composed to exactly the pane
        // width under Porta's accounting, and a terminal that spends two
        // columns on an Ambiguous glyph wraps it. Nothing in src is wrong here
        // — this is what the user's terminal would do to a correct frame.
        $ambiguous = "\u{2502}"; // the pane separator itself

        [$narrow] = $this->play([[[$ambiguous.'ab', 'v'], ['second', 'w']]], rtl: false, ambiguousWidth: 1);
        [$wide] = $this->play([[[$ambiguous.'ab', 'v'], ['second', 'w']]], rtl: false, ambiguousWidth: 2);

        $this->assertNotSame($narrow, $wide, 'a terminal that widens Ambiguous does not show the composed frame');
    }

    public function testResyncScreenRepaintsAbsolutelySoADesyncedCursorRecovers(): void
    {
        // Frames after the first are positioned RELATIVELY, and the writer
        // never emits an absolute reposition on its own -- a whole real session
        // of output contains no CSI 2J and no CSI H. So once the cursor is off
        // by a row, nothing recovers. resyncScreen() is the repair, and what
        // makes it one is the clear plus the absolute home.
        $terminal = new VirtualTerminal(columns: self::COLUMNS, rows: self::ROWS);
        $tui = $this->tui($terminal, rtl: false);
        self::$lines = [self::row('name', 'value')];
        $tui->requestRender();
        $tui->processRender();

        // An ordinary second frame: relative only.
        $terminal->clearOutput();
        $this->widget->invalidate();
        self::$lines = [self::row('name', 'other')];
        $tui->requestRender();
        $tui->processRender();
        $ordinary = $terminal->getOutput();
        $this->assertStringNotContainsString("\x1b[2J", $ordinary, 'the normal path never repositions absolutely');

        $terminal->clearOutput();
        $this->widget->invalidate();
        $tui->resyncScreen();
        $tui->processRender();
        $repaired = $terminal->getOutput();

        $this->assertStringContainsString("\x1b[2J", $repaired, 'the screen is cleared');
        $this->assertStringContainsString("\x1b[H", $repaired, 'and the cursor placed absolutely');
    }
}
