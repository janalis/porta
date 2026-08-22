<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Porta\Debug\BreakpointStore;
use Porta\Debug\DebugSessionState;
use Porta\Debug\EditorDecorations;
use Porta\Editor\Buffer;
use Porta\Editor\Document;
use Porta\Event\BufferSwitched;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Render\RenderContext;

final class EditorDecorationsTest extends TestCase
{
    public function testApplyPaintsBothTheBreakpointDotAndTheStoppedLine(): void
    {
        $editor = new CodeEditorWidget();
        $editor->showDocument('/p/a.php', new Document("<?php\n\$a = 1;\n"));

        $breakpoints = new BreakpointStore();
        $breakpoints->toggle('/p/a.php', 1);
        $state = new DebugSessionState();
        $state->stopAt('/p/a.php', 1);

        new EditorDecorations($breakpoints, $state, $editor)->apply('/p/a.php');

        $lines = $editor->render(new RenderContext(40, 6));
        $this->assertStringContainsString('▶', $lines[1], 'the stopped-line marker was applied, not just the breakpoint dot');
    }

    public function testOnBufferSwitchedAppliesDecorationsButSkipsViewerTabs(): void
    {
        $editor = new CodeEditorWidget();
        $editor->showDocument('/p/a.php', new Document("<?php\n\$a = 1;\n"));

        $breakpoints = new BreakpointStore();
        $breakpoints->toggle('/p/a.php', 1);
        $decorations = new EditorDecorations($breakpoints, new DebugSessionState(), $editor);

        $decorations->onBufferSwitched(new BufferSwitched(new Buffer('/p/a.php', new Document(''))));
        $this->assertStringContainsString('●', implode("\n", $editor->render(new RenderContext(40, 6))), 'a plain text buffer switch applies decorations');

        $editor->setBreakpointLines([]); // reset before the viewer case, isolating its effect
        $decorations->onBufferSwitched(new BufferSwitched(new Buffer('/p/a.php', new Document(''), paneId: 'graph')));
        $this->assertStringNotContainsString('●', implode("\n", $editor->render(new RenderContext(40, 6))), 'a viewer tab has no gutter, so it is skipped');
    }
}
