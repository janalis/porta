<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Widget\Render\HeaderRenderer;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class HeaderRendererTest extends TestCase
{
    public function testDefaultBackgroundBoldAndPaddedToWidth(): void
    {
        $line = HeaderRenderer::render('Title', 10);

        $this->assertStringStartsWith("\x1b[48;5;237m\x1b[1m", $line);
        $this->assertStringEndsWith("\x1b[0m", $line);
        // Padded to the full width.
        $this->assertSame(10, AnsiUtils::visibleWidth($line));
        $this->assertSame('Title     ', AnsiUtils::stripAnsiCodes($line));
    }

    public function testCustomBackground(): void
    {
        $line = HeaderRenderer::render('X', 5, bg: 235);

        $this->assertStringStartsWith("\x1b[48;5;235m\x1b[1m", $line);
    }

    public function testForegroundIsEmittedBeforeBold(): void
    {
        $line = HeaderRenderer::render('X', 5, bg: 237, fg: 231);

        $this->assertStringStartsWith("\x1b[48;5;237m\x1b[38;5;231m\x1b[1m", $line);
    }

    public function testTruncatesWithEllipsis(): void
    {
        $line = HeaderRenderer::render('A very long header', 6);

        $this->assertSame(6, AnsiUtils::visibleWidth($line));
        $this->assertStringContainsString('…', AnsiUtils::stripAnsiCodes($line));
    }
}
