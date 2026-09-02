<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BookmarkActions;
use Porta\Editor\BookmarkDecorations;
use Porta\Editor\BookmarkRows;
use Porta\Editor\Bookmarks;
use Porta\Editor\BufferManager;
use Porta\Editor\LineTextReader;
use Porta\I18n\Translator;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\TestBottomDock;
use Porta\Tests\Support\TestTui;
use Porta\Ui\Glyphs;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\ResultsPanelMode;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class BookmarkActionsTest extends TestCase
{
    private BufferManager $buffers;
    private Bookmarks $bookmarks;
    private BottomDock $dock;
    private ResultsPanelWidget $results;
    private CodeEditorWidget $editor;

    private function actions(): BookmarkActions
    {
        $this->buffers = new BufferManager();
        $this->bookmarks = new Bookmarks();
        $this->results = new ResultsPanelWidget();
        $this->editor = new CodeEditorWidget();
        $this->dock = TestBottomDock::create(TestTui::create(100, 24), new TerminalPanelWidget(), $this->results, new DebugPanelWidget());

        return new BookmarkActions(
            $this->bookmarks,
            new BookmarkRows($this->bookmarks, new LineTextReader($this->buffers), new ProjectPaths('/p')),
            $this->buffers,
            $this->dock,
            new Translator('en'),
            new BookmarkDecorations($this->bookmarks, $this->editor->decorations()),
        );
    }

    private function panel(): string
    {
        return AnsiUtils::stripAnsiCodes(implode("\n", $this->results->render(new RenderContext(100, 12))));
    }

    public function testToggleMarksTheCaretLineAndPaintsTheGutter(): void
    {
        $actions = $this->actions();
        $buffer = $this->buffers->open('/p/src/A.php', "<?php\n\$a = 1;\n");
        $this->buffers->setActive('/p/src/A.php');
        $this->editor->showDocument('/p/src/A.php', $buffer->document);
        $buffer->document->setCursor(1, 0);

        $actions->toggle();

        $this->assertSame([1], $this->bookmarks->linesFor('/p/src/A.php'));
        $lines = $this->editor->render(new RenderContext(60, 8));
        $this->assertStringContainsString(Glyphs::BOOKMARK, $lines[1], 'the mark is painted without waiting for a buffer switch');

        $actions->toggle();
        $this->assertSame([], $this->bookmarks->linesFor('/p/src/A.php'), 'the same key takes it off again');
        $this->assertStringNotContainsString(Glyphs::BOOKMARK, implode("\n", $this->editor->render(new RenderContext(60, 8))), 'and unpaints it');
    }

    public function testToggleRefreshesAStandingBookmarksTabButNeverOpensOne(): void
    {
        // Marking a line must not throw a panel over what you are reading.
        $actions = $this->actions();
        $buffer = $this->buffers->open('/p/src/A.php', "<?php\nmarked line;\n");
        $this->buffers->setActive('/p/src/A.php');
        $buffer->document->setCursor(1, 0);

        $actions->toggle();
        $this->assertFalse($this->dock->hasList(ResultsPanelMode::Bookmarks), 'no tab was standing, so none was opened');

        $actions->show();
        $this->assertStringContainsString('marked line;', $this->panel());

        $buffer->document->setCursor(0, 0);
        $actions->toggle();
        $this->assertStringContainsString('<?php', $this->panel(), 'the standing tab picked up the new mark in place');
    }

    public function testShowListsEveryMarkAcrossFilesUnderItsOwnMode(): void
    {
        $actions = $this->actions();
        $buffer = $this->buffers->open('/p/src/A.php', "<?php\nfirst;\n");
        $this->buffers->setActive('/p/src/A.php');
        $buffer->document->setCursor(1, 0);
        $actions->toggle();
        $this->bookmarks->toggle('/p/src/B.php', 4);

        $actions->show();

        $this->assertSame(ResultsPanelMode::Bookmarks, $this->dock->mode());
        $panel = $this->panel();
        $this->assertStringContainsString('first;', $panel);
        $this->assertStringContainsString('src/B.php', $panel, 'a mark in a closed file is listed too');
    }

    public function testAViewerTabHasNoLinesToBookmark(): void
    {
        $actions = $this->actions();
        $this->buffers->openPane('/p/graph.dot', 'graph', payload: null);
        $this->buffers->setActive('/p/graph.dot');

        $actions->toggle();

        $this->assertSame([], $this->bookmarks->all());
    }

    public function testWithNoBufferAtAllToggleIsANoOp(): void
    {
        $actions = $this->actions();

        $actions->toggle();

        $this->assertSame([], $this->bookmarks->all());
    }
}
