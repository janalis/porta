<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Widget\Render\HeaderRenderer;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\Style;

final class HeaderRendererTest extends TestCase
{
    public function testDefaultBackgroundBoldAndPaddedToWidth(): void
    {
        $line = HeaderRenderer::render('Title', 10, new Style(background: 237, bold: true));

        $this->assertStringStartsWith("\x1b[48;5;237m\x1b[1m", $line);
        $this->assertStringEndsWith("\x1b[22m\x1b[49m", $line, 'closes with targeted resets');
        // Padded to the full width.
        $this->assertSame(10, AnsiUtils::visibleWidth($line));
        $this->assertSame('Title     ', AnsiUtils::stripAnsiCodes($line));
    }

    public function testCustomBackground(): void
    {
        $line = HeaderRenderer::render('X', 5, new Style(background: 235, bold: true));

        $this->assertStringStartsWith("\x1b[48;5;235m\x1b[1m", $line);
    }

    public function testForegroundIsEmittedBeforeBackground(): void
    {
        $line = HeaderRenderer::render('X', 5, new Style(background: 237, color: 231, bold: true));

        $this->assertStringStartsWith("\x1b[38;5;231m\x1b[48;5;237m\x1b[1m", $line);
    }

    public function testSuffixRendersFaintAndRightAligned(): void
    {
        $line = HeaderRenderer::render('Title', 20, new Style(background: 237, bold: true), suffix: 'Esc');

        $this->assertSame(20, AnsiUtils::visibleWidth($line), 'the suffix stays inside the bar width');
        $this->assertSame('Title           Esc ', AnsiUtils::stripAnsiCodes($line), 'right-aligned with a one-cell margin');
        $this->assertStringContainsString("\x1b[22m\x1b[2mEsc", $line, 'the hint drops bold and renders faint');
    }

    public function testSuffixClipsTheTitleNotTheHint(): void
    {
        $line = HeaderRenderer::render('A very long header', 12, new Style(background: 237, bold: true), suffix: 'Esc');

        $this->assertSame(12, AnsiUtils::visibleWidth($line));
        $this->assertStringEndsWith('Esc ', AnsiUtils::stripAnsiCodes($line), 'the hint survives; the title clips');
    }

    public function testTruncatesWithEllipsis(): void
    {
        $line = HeaderRenderer::render('A very long header', 6, new Style(background: 237, bold: true));

        $this->assertSame(6, AnsiUtils::visibleWidth($line));
        $this->assertStringContainsString('…', AnsiUtils::stripAnsiCodes($line));
    }

    /**
     * Every title row in the IDE is this renderer, so it must fill its width
     * exactly at any width — a short line leaves the pane's background
     * showing through, an over-wide one garbles the row below.
     */
    public function testFillsExactlyTheWidthEvenBelowTheEllipsisWidth(): void
    {
        foreach (range(0, 12) as $width) {
            foreach ([' a title', '日本語のタイトル', '', 'x'] as $title) {
                $line = HeaderRenderer::render($title, $width, new Style(background: 237));

                $this->assertSame($width, AnsiUtils::visibleWidth($line), "{$title} @ {$width}");
            }
        }
    }
}
