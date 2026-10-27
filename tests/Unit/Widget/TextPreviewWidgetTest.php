<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Editor\TextPreview;
use Porta\I18n\Translator;
use Porta\Support\Files;
use Porta\Tests\Support\RecordingViewerTabHandler;
use Porta\Widget\TextPreviewWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The read-only look at a file too large to edit.
 *
 * Over the 2 MB cap the IDE used to flash "File too large" and do nothing, so
 * a grown composer.lock, a log or a bundled asset was unopenable and you left
 * the IDE to look at it.
 */
final class TextPreviewWidgetTest extends TestCase
{
    private function widget(TextPreview $preview, string $path = '/tmp/huge.log'): TextPreviewWidget
    {
        $widget = new TextPreviewWidget(new Translator('en'));
        $widget->show($path, $preview);

        return $widget;
    }

    /** @return list<string> the rendered rows with their decoration stripped */
    private function plain(TextPreviewWidget $widget, int $cols = 40, int $rows = 4): array
    {
        return \array_map(AnsiUtils::stripAnsiCodes(...), $widget->render(new RenderContext($cols, $rows)));
    }

    public function testTheBannerSaysTheSizeAndTheBodyShowsTheTop(): void
    {
        $widget = $this->widget(new TextPreview("l0\nl1\nl2\nl3\nl4\n", bytes: 5_242_880, truncated: true));

        $rows = $this->plain($widget, cols: 90);

        $this->assertStringContainsString('huge.log', $rows[0]);
        $this->assertStringContainsString('5.0 MB', $rows[0], 'the size of the WHOLE file, not the prefix');
        $this->assertStringContainsString('showing the first', $rows[0]);
        $this->assertSame(['l0', 'l1', 'l2'], \array_map(rtrim(...), \array_slice($rows, offset: 1)));
    }

    /**
     * A file UNDER the prefix cap can still be over the edit cap — 2.4 MB is
     * read whole — and the banner must not claim it was cut.
     */
    public function testAnUntruncatedPreviewSaysOnlyThatItIsTooLargeToEdit(): void
    {
        $widget = $this->widget(new TextPreview("a\nb\n", bytes: 2_516_582, truncated: false));

        $banner = $this->plain($widget, cols: 90)[0];

        $this->assertStringContainsString('too large to edit', $banner);
        $this->assertStringNotContainsString('showing the first', $banner);
    }

    /**
     * The cap is exactly 2 MiB, so at one decimal a file just over it read
     * "is 2.0 MB, showing the first 2.0 MB" — a banner whose two halves say the
     * same thing while existing to say they differ.
     */
    public function testABannerNeverSaysTheWholeFileIsTheSizeOfThePrefix(): void
    {
        $cap = Files::MAX_TEXT_BYTES;
        $widget = $this->widget(new TextPreview(\str_repeat('x', times: $cap), bytes: $cap + 30_000, truncated: true));

        $banner = $this->plain($widget, cols: 90)[0];

        // Anchored at the start: the banner is inset one column, like every
        // other header row in the IDE.
        $this->assertStringStartsWith(' Read-only: huge.log is 2.03 MB, showing the first 2.00 MB', $banner);
        $this->assertSame('Read-only preview  2.00 MB of 2.03 MB', $widget->statusRight());
    }

    /**
     * The widening stops at two decimals. A file a hundred bytes over the cap
     * really is the size of its own preview to any precision worth printing,
     * and there the repetition is simply true.
     */
    public function testSizesThatStayEqualAtTwoDecimalsKeepTheShortForm(): void
    {
        $cap = Files::MAX_TEXT_BYTES;
        $widget = $this->widget(new TextPreview(\str_repeat('x', times: $cap), bytes: $cap + 100, truncated: true));

        $this->assertStringContainsString('is 2.00 MB, showing the first 2.00 MB', $this->plain($widget, cols: 90)[0]);
    }

    /** Sizes that already differ are left alone: one decimal is what a size reads as. */
    public function testDistinctSizesKeepOneDecimal(): void
    {
        $widget = $this->widget(new TextPreview(\str_repeat('a', times: 2048), bytes: 1_073_741_824, truncated: true));

        $this->assertSame('Read-only preview  2.0 KB of 1.0 GB', $widget->statusRight());
    }

    public function testScrollingStopsAtTheLastBodyFullOfLines(): void
    {
        $widget = $this->widget(new TextPreview(\implode("\n", \range(start: 0, end: 9)), bytes: 3_000_000, truncated: false));

        // 4 rows = 1 banner + 3 body, over ten lines: the last top is line 7.
        $widget->render(new RenderContext(40, 4));
        $widget->handleInput("\x1b[6~");
        $this->assertSame(['3', '4', '5'], \array_map(rtrim(...), \array_slice($this->plain($widget), offset: 1)));

        $widget->handleInput("\x1b[F");
        $this->assertSame(['7', '8', '9'], \array_map(rtrim(...), \array_slice($this->plain($widget), offset: 1)), 'clamped at the tail');

        $widget->handleInput("\x1b[B"); // already at the end
        $this->assertSame(['7', '8', '9'], \array_map(rtrim(...), \array_slice($this->plain($widget), offset: 1)));

        $widget->handleInput("\x1b[H");
        $this->assertSame(['0', '1', '2'], \array_map(rtrim(...), \array_slice($this->plain($widget), offset: 1)));

        $widget->handleInput("\x1b[A"); // already at the top
        $this->assertSame(['0', '1', '2'], \array_map(rtrim(...), \array_slice($this->plain($widget), offset: 1)));
    }

    /** Down and Up move a line, Page Up a body back. */
    public function testTheArrowsMoveALineAndPageUpABody(): void
    {
        $widget = $this->widget(new TextPreview(\implode("\n", \range(start: 0, end: 9)), bytes: 3_000_000, truncated: false));
        $widget->render(new RenderContext(40, 4)); // 1 banner + 3 body
        $top = fn (): string => \rtrim($this->plain($widget)[1] ?? '');

        $widget->handleInput("\x1b[B");
        $this->assertSame('1', $top(), 'down a line');
        $widget->handleInput("\x1b[A");
        $this->assertSame('0', $top(), 'and back up');

        $widget->handleInput("\x1b[F"); // End: 7 on top
        $widget->handleInput("\x1b[5~");
        $this->assertSame('4', $top(), 'a body of three back');
    }

    /** With no IDE to hand them to — a bare pane — the tab keys do nothing, and nothing breaks. */
    public function testWithNoHandlerTheTabKeysDoNothing(): void
    {
        $widget = $this->widget(new TextPreview("a\n", bytes: 3_000_000, truncated: false));
        $widget->render(new RenderContext(40, 4));
        $before = $this->plain($widget);

        foreach (["\x1b[1;5C", "\x1b[1;5D", "\x1bw", "\x1b[115;7u"] as $key) {
            $widget->handleInput($key);
        }

        $this->assertSame($before, $this->plain($widget));
    }

    /** The wheel is the same verb, which is what makes the pane feel like a document. */
    public function testTheWheelScrollsTheSameRows(): void
    {
        $widget = $this->widget(new TextPreview(\implode("\n", \range(start: 0, end: 9)), bytes: 3_000_000, truncated: false));
        $widget->render(new RenderContext(40, 4));

        $widget->wheelBy(2);

        $this->assertSame(['2', '3', '4'], \array_map(rtrim(...), \array_slice($this->plain($widget), offset: 1)));
    }

    /**
     * A preview is arbitrary bytes, so one enormous line is ordinary here
     * rather than exceptional — a minified bundle is a single line of
     * megabytes. `padRight` is a bound with no ceiling, and a row wider than
     * its pane throws out of the render loop and takes the session with it.
     */
    public function testAnEnormousLineIsCutToThePaneRatherThanOverflowingIt(): void
    {
        $widget = $this->widget(new TextPreview(\str_repeat('x', times: 5_000)."\nshort\n", bytes: 9_000_000, truncated: true));

        foreach ([1, 2, 7, 20, 80] as $cols) {
            foreach ($widget->render(new RenderContext($cols, 4)) as $row => $line) {
                $this->assertSame($cols, AnsiUtils::visibleWidth($line), "row {$row} at {$cols} columns");
            }
        }
    }

    public function testTheStatusSegmentNamesBothSizesAndGoesQuietWhenCleared(): void
    {
        $widget = $this->widget(new TextPreview(\str_repeat('a', times: 2048), bytes: 1_073_741_824, truncated: true));

        $this->assertSame('Read-only preview  2.0 KB of 1.0 GB', $widget->statusRight());

        $widget->clear();

        $this->assertSame('', $widget->statusRight());
        $this->assertSame([], \array_filter(\array_map(rtrim(...), \array_slice($this->plain($widget), offset: 1))), 'and draws nothing');
    }

    /**
     * A pane is handed `mixed` — its loader's own type travelling through core
     * untouched. Anything that is not this pane's payload must draw an empty
     * pane rather than fatalling on a property access.
     */
    public function testAPayloadFromAnotherPaneDrawsNothing(): void
    {
        $widget = new TextPreviewWidget(new Translator('en'));
        $widget->show('/tmp/x.bin', ['not' => 'a preview']);

        $this->assertSame('', $widget->statusRight());
        $this->assertSame('', \rtrim($this->plain($widget)[0]), 'no banner without a payload to describe');
    }

    /**
     * Two keys before a frame is drawn, which is the case a render-time clamp
     * cannot cover.
     *
     * `document_end` asks to go to `PHP_INT_MAX`, and the next `scrollBy()` adds
     * to whatever is stored — so if the jump were left unclamped until the next
     * render, End followed by Down (an ordinary burst from one read of stdin)
     * overflows the int to a float and the clamp's `int` parameter is a
     * TypeError. Rendering between the two keys hides it, which is exactly what
     * the other scrolling case above does.
     */
    public function testEndThenDownWithNoFrameBetweenThemStaysAnInt(): void
    {
        $widget = $this->widget(new TextPreview(\implode("\n", \range(start: 0, end: 9)), bytes: 3_000_000, truncated: false));
        $widget->render(new RenderContext(40, 4)); // one frame, to fix the body height

        $widget->handleInput("\x1b[F"); // End
        $widget->handleInput("\x1b[B"); // Down, with no frame in between

        $this->assertSame(['7', '8', '9'], \array_map(rtrim(...), \array_slice($this->plain($widget), offset: 1)));
    }

    /**
     * The tab verbs reach the IDE while the code editor is detached.
     *
     * A pane owns the editor area's slot, so the editor's keymap is not
     * listening — without these three arms, Ctrl+Right and Alt+W would do
     * nothing at all while a large file is open, and the tab you opened it from
     * would be unreachable by keyboard.
     */
    public function testTabManagementKeysRouteBackToTheIde(): void
    {
        $widget = $this->widget(new TextPreview("a\n", bytes: 3_000_000, truncated: false));
        $handler = new RecordingViewerTabHandler();
        $widget->setHandler($handler);

        $widget->handleInput("\x1b[1;5C"); // ctrl+right
        $widget->handleInput("\x1b[1;5D"); // ctrl+left
        $widget->handleInput("\x1bw"); // alt+w

        $this->assertSame(['cycle:1', 'cycle:-1', 'close'], $handler->events);
    }

    /** A key this pane does not bind is left alone rather than swallowed. */
    public function testAnUnboundKeyDoesNothing(): void
    {
        $widget = $this->widget(new TextPreview(\implode("\n", \range(start: 0, end: 9)), bytes: 3_000_000, truncated: false));
        $handler = new RecordingViewerTabHandler();
        $widget->setHandler($handler);
        $widget->render(new RenderContext(40, 4));

        $widget->handleInput('q');

        $this->assertSame([], $handler->events);
        $this->assertSame(['0', '1', '2'], \array_map(rtrim(...), \array_slice($this->plain($widget), offset: 1)), 'and did not scroll');
    }

    /**
     * A pane given no room draws nothing. The layout can hand a widget zero
     * columns mid-resize, and every row this builds starts from a banner that
     * would be padded to a width it does not have.
     */
    public function testAPaneWithNoRoomDrawsNothing(): void
    {
        $widget = $this->widget(new TextPreview("a\nb\n", bytes: 3_000_000, truncated: false));

        $this->assertSame([], $widget->render(new RenderContext(0, 10)));
        $this->assertSame([], $widget->render(new RenderContext(40, 0)));
    }
}
