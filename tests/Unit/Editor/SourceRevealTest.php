<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\PreviewMode;
use Porta\Editor\SourceReveal;
use Porta\Tests\Support\TestPane;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Tui;

/**
 * Click-to-source's landing: the caret on what a preview named, the keys to
 * the text only where it is on screen, and nothing for a tab with no text.
 */
final class SourceRevealTest extends TestCase
{
    private BufferManager $buffers;
    private CodeEditorWidget $editor;
    private Tui $tui;
    private SourceReveal $reveal;

    protected function setUp(): void
    {
        $this->buffers = new BufferManager();
        $this->editor = new CodeEditorWidget();
        $this->tui = TestTui::create();
        $this->reveal = new SourceReveal($this->buffers, $this->editor, $this->tui);
    }

    private function previewed(PreviewMode $mode): void
    {
        $buffer = $this->buffers->open('/p/a.md', "# Title\n\nsome text\n");
        $buffer->previewId = 'markdown';
        $buffer->previewMode = $mode;
        $this->editor->showDocument($buffer->path, $buffer->document);
    }

    public function testBesideThePreviewTheCaretMovesAndTheTextTakesTheKeys(): void
    {
        $this->previewed(PreviewMode::Split);
        $pane = new TestPane('markdown');
        $this->tui->add($pane);
        $this->tui->add($this->editor);
        $this->tui->setFocus($pane);

        $this->reveal->reveal(2, 5);

        $this->assertSame([2, 5], [$this->editor->getDocument()->cursorLine, $this->editor->getDocument()->cursorCol]);
        $this->assertSame($this->editor, $this->tui->getFocus());
    }

    public function testAlonePreviewKeepsTheKeysAndAPositionPastTheTextIsClamped(): void
    {
        $this->previewed(PreviewMode::Preview);
        $pane = new TestPane('markdown');
        $this->tui->add($pane);
        $this->tui->setFocus($pane);

        $this->reveal->reveal(99, 99);

        $this->assertSame([3, 0], [$this->editor->getDocument()->cursorLine, $this->editor->getDocument()->cursorCol], 'the last line, its end');
        $this->assertSame($pane, $this->tui->getFocus(), 'the editor is off screen: it cannot take them');

        $this->reveal->reveal(2, 99);
        $this->assertSame([2, 9], [$this->editor->getDocument()->cursorLine, $this->editor->getDocument()->cursorCol], 'a column past the line stops at its end');
    }

    public function testATabWithNoTextToGoToIsLeftAlone(): void
    {
        $this->reveal->reveal(1, 1); // nothing open

        $this->buffers->openPane('/p/g.dot', 'graph', 'digraph {}');
        $this->reveal->reveal(1, 1); // a viewer tab

        $this->buffers->open('/p/a.php', "<?php\n\necho 1;\n");
        $this->reveal->reveal(2, 2); // text, but nothing previews it

        $this->assertSame(0, $this->editor->getDocument()->cursorLine);
    }
}
