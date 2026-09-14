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
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TestBottomDock;
use Porta\Tests\Support\TestTui;
use Porta\Ui\Glyphs;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\ResultsPanelMode;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;

final class BookmarkActionsTest extends TestCase
{
    use StatusReads;

    private BufferManager $buffers;
    private Bookmarks $bookmarks;
    private BottomDock $dock;
    private ResultsPanelWidget $results;
    private CodeEditorWidget $editor;
    private StatusBarWidget $status;
    private ?Keybindings $keys = null;

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
            new BookmarkDecorations($this->bookmarks, $this->editor->decorations),
            new Notifier($this->status = new StatusBarWidget(), TestTui::create(100, 24)),
            $this->keys ?? new Keybindings(['toggle_bookmark' => ['f11']]),
        );
    }

    private function panel(): string
    {
        return AnsiUtils::stripAnsiCodes(\implode("\n", $this->results->render(new RenderContext(100, 12))));
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
        $this->assertStringNotContainsString(Glyphs::BOOKMARK, \implode("\n", $this->editor->render(new RenderContext(60, 8))), 'and unpaints it');
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

    public function testAnEmptyBookmarksPanelSaysHowToFillIt(): void
    {
        // The panel people reach first, showing `0 results` and teaching
        // nothing — while the verb that fills it lives on a key they have not
        // pressed. The key is RESOLVED: on a Mac F11 is a media key until the
        // keyboard setting says otherwise, so this is exactly the binding
        // somebody rebinds.
        $actions = $this->actions();

        $actions->show();

        $this->assertStringContainsString('No bookmarks yet', $this->flashText($this->status));
        $this->assertStringContainsString('F11', $this->flashText($this->status));
    }

    public function testWithNoKeyBoundItNamesTheActionInstead(): void
    {
        // A keymap file may name the action with no keys at all — the merge
        // replaces per action — and printing an empty chord would read as a
        // typo rather than as an unbound verb.
        $this->keys = new Keybindings(['toggle_bookmark' => []]);

        $this->actions()->show();

        $this->assertStringContainsString('Toggle Bookmark adds one', $this->flashText($this->status));
    }

    public function testWithBookmarksThePanelSpeaksForItself(): void
    {
        $actions = $this->actions();
        $this->buffers->open('/p/a.php', "one\ntwo\n");
        $actions->toggle();

        $actions->show();

        $this->assertSame('', \trim($this->flashText($this->status)), 'a list that has rows needs no instructions');
    }
}
