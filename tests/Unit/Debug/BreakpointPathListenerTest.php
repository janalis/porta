<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Porta\Debug\BreakpointPathListener;
use Porta\Debug\BreakpointStore;
use Porta\Debug\DebugSessionState;
use Porta\Debug\EditorDecorations;
use Porta\Editor\Document;
use Porta\Event\FileDeleted;
use Porta\Event\FileRenamed;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Render\RenderContext;

final class BreakpointPathListenerTest extends TestCase
{
    public function testBreakpointsFollowTheRenameAndAreRepaintedOnTheNewPath(): void
    {
        $editor = new CodeEditorWidget();
        $editor->showDocument('/p/New.php', new Document("<?php\n\$a = 1;\n"));
        $breakpoints = new BreakpointStore();
        $breakpoints->toggle('/p/Old.php', 1);
        $decorations = new EditorDecorations($breakpoints, new DebugSessionState(), $editor->decorations());
        // Showing the new path first proves the repaint below is the listener's
        // doing: nothing has painted a dot for it yet.
        $decorations->apply('/p/New.php');
        $this->assertStringNotContainsString('●', implode("\n", $editor->render(new RenderContext(40, 6))));

        new BreakpointPathListener($breakpoints, $decorations)->onFileRenamed(new FileRenamed('/p/Old.php', '/p/New.php'));

        $this->assertSame([1], $breakpoints->linesFor('/p/New.php'), 'the breakpoint came with the file');
        $this->assertSame([], $breakpoints->linesFor('/p/Old.php'), 'and is gone from the dead path');
        $this->assertStringContainsString('●', implode("\n", $editor->render(new RenderContext(40, 6))), 'the gutter shows it without waiting for a buffer switch');
    }

    public function testADeletedFilesBreakpointsAreRetired(): void
    {
        // Not cosmetic: the surviving set is what the next launch hands the
        // adapter, and a path that no longer exists is a breakpoint the
        // debugger can reject.
        $breakpoints = new BreakpointStore();
        $breakpoints->toggle('/p/gone.php', 1);
        $breakpoints->toggle('/p/kept.php', 2);
        $decorations = new EditorDecorations($breakpoints, new DebugSessionState(), new CodeEditorWidget()->decorations());

        new BreakpointPathListener($breakpoints, $decorations)->onFileDeleted(new FileDeleted('/p/gone.php'));

        $this->assertSame([], $breakpoints->linesFor('/p/gone.php'));
        $this->assertSame([2], $breakpoints->linesFor('/p/kept.php'));
    }
}
