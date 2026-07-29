<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\EditorState;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * Manual horizontal scrolling (horizontal wheel / shift+wheel): the view
 * slides without moving the caret and survives parked-caret renders; any
 * caret movement snaps the view back to the caret (VSCode-style).
 */
final class CodeEditorHScrollTest extends TestCase
{
    private function editor(): CodeEditorWidget
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('x.php', str_repeat('x', times: 40).'NEEDLE'.str_repeat('y', times: 10)."\nshort\n");

        return $editor;
    }

    private function screen(CodeEditorWidget $editor): string
    {
        return implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $editor->render(new RenderContext(40, 5))));
    }

    private function hScroll(CodeEditorWidget $editor): int
    {
        return EditorState::scroll($editor)->hScroll();
    }

    public function testScrollHBySlidesTheViewAndSurvivesRenders(): void
    {
        $editor = $this->editor();
        $this->assertStringNotContainsString('NEEDLE', $this->screen($editor), 'the needle starts past the viewport');

        $editor->scrollHBy(20);
        $this->assertStringContainsString('NEEDLE', $this->screen($editor), 'the view slid right without the caret');
        $this->assertStringContainsString('NEEDLE', $this->screen($editor), 'a parked-caret re-render does not snap back');
        $this->assertSame(20, $this->hScroll($editor));

        // Moving the caret snaps the view back to it.
        $editor->getDocument()->setCursor(0, 1);
        $this->assertStringNotContainsString('NEEDLE', $this->screen($editor), 'a caret move re-follows');
        $this->assertSame(0, $this->hScroll($editor));
    }

    public function testScrollHByClampsToTheWidestVisibleLine(): void
    {
        $editor = $this->editor();
        $this->screen($editor); // teach the viewport

        $editor->scrollHBy(-50);
        $this->assertSame(0, $this->hScroll($editor), 'clamped at the left edge');

        $editor->scrollHBy(500);
        $this->assertSame(46, $this->hScroll($editor), 'clamped to the widest visible line (56) - 10');
    }
}
