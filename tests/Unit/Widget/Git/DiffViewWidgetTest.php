<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Git;

use PHPUnit\Framework\TestCase;
use Porta\Git\DiffDocument;
use Porta\Widget\Git\DiffViewWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class DiffViewWidgetTest extends TestCase
{
    private const string RAW = <<<'DIFF'
        diff --git a/src/demo.php b/src/demo.php
        --- a/src/demo.php
        +++ b/src/demo.php
        @@ -3,3 +3,4 @@ function demo()
         $keep = 1;
        -$old = 2;
        +$new = 2;
        +$extra = 3;
        @@ -10,1 +11,1 @@
        -tail
        +tail!
        DIFF;

    private function widget(): DiffViewWidget
    {
        $widget = new DiffViewWidget();
        $widget->setDocument(DiffDocument::parse(self::RAW), 'src/demo.php');

        return $widget;
    }

    /** @return list<string> */
    private function plainLines(DiffViewWidget $widget, int $cols = 80, int $rows = 12): array
    {
        return array_map(AnsiUtils::stripAnsiCodes(...), $widget->render(new RenderContext($cols, $rows)));
    }

    public function testRendersGuttersHunkHeadersAndBodies(): void
    {
        $lines = $this->plainLines($this->widget());

        $this->assertStringContainsString('src/demo.php', $lines[0]);
        $this->assertStringContainsString('@@ -3,3 +3,4 @@ function demo()', $lines[1]);
        $this->assertMatchesRegularExpression('/^\s+3\s+3 │ \$keep = 1;/', $lines[2], 'context: both line numbers');
        $this->assertMatchesRegularExpression('/^\s+4\s+ │-\$old = 2;/', $lines[3], 'deletion: old side only');
        $this->assertMatchesRegularExpression('/^\s+4 │\+\$new = 2;/', $lines[4], 'addition: new side only');
        $this->assertStringContainsString('@@ -10,1 +11,1 @@', $lines[6]);
    }

    public function testColorsMarkRowKinds(): void
    {
        $raw = $this->widget()->render(new RenderContext(80, 12));

        $this->assertStringContainsString("\x1b[38;5;75m", $raw[1], 'hunk header cyan');
        $this->assertStringContainsString("\x1b[38;5;203m", $raw[3], 'deletion red');
        $this->assertStringContainsString("\x1b[38;5;114m", $raw[4], 'addition green');
    }

    public function testAddedAndContextLinesKeepSyntaxHighlighting(): void
    {
        $raw = $this->widget()->render(new RenderContext(80, 12));

        // tempest/highlight colors PHP variables; the raw added row must carry
        // SGR codes beyond the plain green marker (i.e. highlighted body).
        $addRow = AnsiUtils::stripAnsiCodes($raw[4]);
        $this->assertStringContainsString('$new = 2;', $addRow);
        $this->assertGreaterThan(
            substr_count($raw[3], "\x1b["),
            substr_count($raw[4], "\x1b["),
            'the addition carries syntax SGR spans, the deletion stays plain',
        );
    }

    public function testPlaceholderWithoutADocument(): void
    {
        $widget = new DiffViewWidget();
        $widget->setDocument(null, null, 'Select a file');

        $lines = $this->plainLines($widget, 40, 5);
        $this->assertStringContainsString('Select a file', $lines[1]);
        $this->assertCount(5, $lines, 'pads to the full height');
    }

    public function testScrollingClampsAndAnnotatesTheHeader(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
        $this->plainLines($widget, 80, 4); // a first frame teaches the widget its viewport height

        $widget->handleInput("\x1b[B"); // down
        $lines = $this->plainLines($widget, 80, 4);
        $this->assertStringContainsString('[scrolled +1]', $lines[0]);
        $this->assertStringContainsString('$keep = 1;', $lines[1], 'first visible row moved');

        $widget->handleInput("\x1b[6~"); // page down clamps at the end
        $widget->handleInput("\x1b[6~");
        $this->assertStringContainsString('tail!', implode("\n", $this->plainLines($widget, 80, 4)));

        $widget->handleInput("\x1b[5~"); // page up ×2 (step 2) + one line up
        $widget->handleInput("\x1b[5~");
        $widget->handleInput("\x1b[A");
        $lines = $this->plainLines($widget, 80, 4);
        $this->assertStringContainsString('@@ -3,3', $lines[1], 'clamped back to the top');
        $this->assertStringNotContainsString('[scrolled', $lines[0]);
    }

    public function testTabAndEscapeFireTheirHooks(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
        $events = [];
        $widget->onFocusNext(function () use (&$events): void {
            $events[] = 'next';
        });
        $widget->onExit(function () use (&$events): void {
            $events[] = 'exit';
        });

        $widget->handleInput("\t");
        $widget->handleInput("\x1b");

        $this->assertSame(['next', 'exit'], $events);
    }

    public function testInputHookConsumesFirst(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
        $this->plainLines($widget, 80, 4);
        $widget->onInput(static fn (string $data): bool => true);

        $widget->handleInput("\x1b[B"); // swallowed
        $this->assertStringNotContainsString('[scrolled', $this->plainLines($widget, 80, 4)[0]);
    }

    public function testReadOnlyFlagRoundTrips(): void
    {
        $widget = $this->widget();
        $this->assertFalse($widget->isReadOnly());
        $widget->setReadOnly(true);
        $this->assertTrue($widget->isReadOnly());
    }

    public function testNoNewlineMarkerRendersDim(): void
    {
        $widget = new DiffViewWidget();
        $widget->setDocument(DiffDocument::parse("@@ -1,1 +1,1 @@\n-old\n\\ No newline at end of file\n+new\n"), 'x.txt');

        $lines = $this->plainLines($widget, 60, 6);
        $this->assertStringContainsString('No newline at end of file', $lines[3]);
    }
}
