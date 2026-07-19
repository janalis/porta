<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Image\ImageData;
use Porta\Tests\Support\RecordingImageViewerHandler;
use Porta\Ui\Glyphs;
use Porta\Widget\ImageViewerWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class ImageViewerWidgetTest extends TestCase
{
    /** A 64×8 horizontal gradient (red channel = x*4) for pan-visible sampling. */
    private function gradient(): ImageData
    {
        $gd = imagecreatetruecolor(64, height: 8);
        for ($x = 0; $x < 64; ++$x) {
            for ($y = 0; $y < 8; ++$y) {
                imagesetpixel($gd, $x, $y, (int) imagecolorallocate($gd, $x * 4, green: 0, blue: 0));
            }
        }

        return new ImageData($gd, 64, 8, 123);
    }

    public function testAFocusedPaneShowsAFocusFrameThatSurvivesNoAnsi(): void
    {
        $widget = new ImageViewerWidget();
        $plain = static fn (array $lines): string => implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $lines));

        $unfocused = $plain($widget->render(new RenderContext(12, 4)));
        $this->assertStringNotContainsString(Glyphs::FRAME_TOP_LEFT, $unfocused, 'no frame when the pane is blurred');

        $widget->setFocused(true);
        $focused = $plain($widget->render(new RenderContext(12, 4)));
        // The glyph carries the cue, so stripping ANSI still shows the whole box.
        $this->assertStringContainsString(Glyphs::FRAME_TOP_LEFT, $focused);
        $this->assertStringContainsString(Glyphs::FRAME_BOTTOM_RIGHT, $focused);
        $this->assertStringContainsString(Glyphs::FRAME_VERTICAL, $focused, 'the side rails');
        foreach ($widget->render(new RenderContext(12, 4)) as $line) {
            $this->assertSame(12, AnsiUtils::visibleWidth($line), 'the frame keeps every line pane-wide');
        }
    }

    public function testRendersBlankWithoutAnImageAndNothingOnAZeroSizePane(): void
    {
        $widget = new ImageViewerWidget();
        $this->assertSame([], $widget->render(new RenderContext(10, 0)), 'zero rows');
        $this->assertSame(array_fill(0, count: 3, value: str_repeat(' ', times: 10)), $widget->render(new RenderContext(10, 3)));
        $this->assertSame('', $widget->zoomLabel(), 'no image, no label');

        $widget->handleInput('='); // zoom without an image is a no-op, not an error
        $widget->scrollBy(1, 1);
        $widget->zoomAt(1, 0, 0);
        $this->assertSame('', $widget->zoomLabel());
    }

    public function testShowsAnImageAndZoomKeysDriveTheLabel(): void
    {
        $widget = new ImageViewerWidget();
        $widget->show($this->gradient());
        $widget->render(new RenderContext(8, 2)); // fit = min(8/64, 4/8) = 0.125

        $this->assertSame('Fit (13%)', $widget->zoomLabel());
        $this->assertStringContainsString('▀', implode('', $widget->render(new RenderContext(8, 2))));
        $this->assertStringContainsString('38;2;', implode('', $widget->render(new RenderContext(8, 2))));

        $widget->handleInput('='); // 0.125 → 0.25
        $this->assertSame('25%', $widget->zoomLabel());
        $widget->handleInput('+'); // raw alias → 0.5
        $this->assertSame('50%', $widget->zoomLabel());
        $widget->handleInput('-');
        $this->assertSame('25%', $widget->zoomLabel());
        $widget->handleInput('1');
        $this->assertSame('100%', $widget->zoomLabel());
        $widget->handleInput('0');
        $this->assertSame('Fit (13%)', $widget->zoomLabel());
        $widget->handleInput('1');
        $widget->handleInput('f');
        $this->assertSame('Fit (13%)', $widget->zoomLabel());

        $widget->clear();
        $this->assertSame('', $widget->zoomLabel());
    }

    public function testArrowsPanAndShiftPansFiveTimesAsFar(): void
    {
        $widget = new ImageViewerWidget();
        $widget->show($this->gradient());
        $widget->render(new RenderContext(4, 2)); // viewport 4×4 px at 100%
        $widget->handleInput('1');
        $widget->handleInput("\x1b[1;2H"); // unmatched sequence → no-op branch
        $widget->scrollBy(-1000, -1000); // 100% centers the viewport; rewind to the origin

        $first = static fn (array $lines): string => $lines[0];
        $home = $first($widget->render(new RenderContext(4, 2)));
        $this->assertStringContainsString('38;2;0;0;0', $home, 'origin sample is pixel x=0');

        $widget->handleInput("\x1b[C"); // pan right 2 cells = 2 px → gradient shifts
        $afterRight = $first($widget->render(new RenderContext(4, 2)));
        $this->assertNotSame($home, $afterRight);
        $this->assertStringContainsString('38;2;8;0;0', $afterRight, 'leftmost sample is now pixel x=2');

        $widget->handleInput("\x1b[1;2C"); // shift+right pans 10 px
        $this->assertStringContainsString('38;2;48;0;0', $first($widget->render(new RenderContext(4, 2))), 'x=12 after the shift step');

        $widget->handleInput("\x1b[1;2D"); // shift+left back to x=2
        $widget->handleInput("\x1b[D"); // and to x=0
        $this->assertSame($home, $first($widget->render(new RenderContext(4, 2))));

        $widget->handleInput("\x1b[B"); // vertical pans clamp within the 8px-tall image
        $widget->handleInput("\x1b[1;2B");
        $widget->handleInput("\x1b[1;2A");
        $widget->handleInput("\x1b[A");
        $this->assertSame($home, $first($widget->render(new RenderContext(4, 2))));

        $widget->scrollBy(2, 0); // wheel path
        $this->assertSame($afterRight, $first($widget->render(new RenderContext(4, 2))));
    }

    public function testPassThroughActionsFireTheCallbacks(): void
    {
        $widget = new ImageViewerWidget();
        $handler = new RecordingImageViewerHandler();
        $widget->setHandler($handler);
        $widget->show($this->gradient());
        $widget->render(new RenderContext(8, 2));

        $widget->handleInput("\x1b[1;5C"); // ctrl+right → next tab
        $widget->handleInput("\x1b[1;5D"); // ctrl+left → previous tab
        $widget->handleInput("\x1bw"); // alt+w → close tab
        $widget->handleInput("\x10"); // ctrl+p → go to file
        $widget->handleInput("\x1b\x13"); // ctrl+alt+s (legacy: ESC + ctrl+s) → save all
        $widget->handleInput('='); // zoom → onViewChanged
        $widget->zoomAt(1, 2, 1); // ctrl+wheel zoom → onViewChanged too

        $this->assertSame(['cycle:1', 'cycle:-1', 'close', 'goto', 'save_all', 'view', 'view'], $handler->events);
        $this->assertSame('50%', $widget->zoomLabel(), 'wheel zoom stepped the ladder');
    }
}
