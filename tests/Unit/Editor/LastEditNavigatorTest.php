<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\EditHistory;
use Porta\Editor\LastEditNavigator;
use Porta\I18n\Translator;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RecordingFileOpener;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;

/**
 * The verb's two refusals and its one shortcut, which an E2e cannot reach: it
 * always has a file open and always has an edit behind it.
 */
final class LastEditNavigatorTest extends TestCase
{
    use StatusReads;

    private StatusBarWidget $status;
    private CodeEditorWidget $editor;
    private RecordingFileOpener $files;
    private \Symfony\Component\Tui\Tui $tui;

    private function navigator(EditHistory $history): LastEditNavigator
    {
        $this->tui = TestTui::create(80, 24);
        $this->status = new StatusBarWidget();
        $this->editor = new CodeEditorWidget();
        $this->files = new RecordingFileOpener();

        return new LastEditNavigator(
            $history,
            $this->editor,
            $this->files,
            new Notifier($this->status, $this->tui),
            new Translator('en'),
            $this->tui,
        );
    }

    public function testWithNoEditsBehindYouItSaysSo(): void
    {
        // Silence would read as a broken key. The list is empty on a fresh
        // session, which is exactly when someone tries the chord to see what
        // it does.
        $this->navigator(new EditHistory())->goBack();

        self::assertSame('No edits to go back to', $this->flashText($this->status));
        self::assertSame([], $this->files->opened, 'and nothing was opened');
    }

    public function testWalkingPastTheOLDESTEditSaysSoToo(): void
    {
        $history = new EditHistory();
        $history->record('/a.php', line: 4);
        $navigator = $this->navigator($history);

        $navigator->goBack();
        $navigator->goBack(); // one press past the end

        self::assertSame('No edits to go back to', $this->flashText($this->status));
    }

    public function testAnEditInANOTHERFileOpensThatFile(): void
    {
        // The half that makes this different from navigate-back: the edit you
        // want may be in a file you have since left, and opening a file is not
        // a jump, so nothing pushed it onto the jump stack.
        $history = new EditHistory();
        $history->record('/b.php', line: 3);
        $navigator = $this->navigator($history);
        $this->editor->openFile('/a.php', "one\n");

        $navigator->goBack();

        self::assertSame([['path' => '/b.php', 'focus' => true]], $this->files->opened, 'opened, and focused');
    }

    public function testItLandsAtTheSTARTOfTheLineAndAsksForAFrame(): void
    {
        // Column 0 rather than the column the edit was at: a stale column on a
        // line since re-typed is a worse guess than the start of it. And both
        // halves of the repaint — the widget's own cache and the loop's frame —
        // because nothing else is watching.
        $history = new EditHistory();
        $history->record('/a.php', line: 2);
        $navigator = $this->navigator($history);
        $this->editor->openFile('/a.php', "aaaa\nbbbb\ncccc\n");
        $this->editor->getDocument()->setCursor(0, 3);
        $this->tui->tick(); // clears any pending render request
        $revision = $this->editor->getRenderRevision();

        $navigator->goBack();

        self::assertSame(2, $this->editor->getDocument()->getCursorLine());
        self::assertSame(0, $this->editor->getDocument()->getCursorCol(), 'the start of the line, not the old column');
        self::assertGreaterThan($revision, $this->editor->getRenderRevision(), 'the widget dropped its cached rows');
        self::assertTrue(Probe::get($this->tui, 'renderRequested'), 'and a frame was asked for');
    }

    public function testAnEditInTheFileYouAreALREADYInDoesNotReopenIt(): void
    {
        // Reopening would be visible: the tab flickers and the LSP is told
        // about a file it is already serving.
        $this->editor = new CodeEditorWidget();
        $history = new EditHistory();
        $history->record('/a.php', line: 6);
        $navigator = $this->navigator($history);
        $this->editor->openFile('/a.php', "1\n2\n3\n4\n5\n6\n7\n");

        $navigator->goBack();

        self::assertSame([], $this->files->opened, 'the file was not reopened');
        self::assertSame(6, $this->editor->getDocument()->getCursorLine());
    }
}
