<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Input;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\Document;
use Porta\Editor\OpenFocus;
use Porta\Input\DragDropProtocol;
use Porta\Input\DropTarget;
use Porta\Input\EditorLayoutPanes;
use Porta\Input\HitTester;
use Porta\Input\PasteDrop;
use Porta\Tests\Support\BootsIde;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Ui\TerminalRestorer;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Tui;

/**
 * A drop that knows where it is. kitty's drag-and-drop protocol, spoken byte
 * for byte: the hover answers, the spring-loaded folders, the caret following
 * the drag, the data request and its chunks. And Ghostty's way: a paste of the
 * paths, then the pointer that let them go.
 *
 * Coordinates are found on the rendered frame rather than written down: the
 * protocol speaks screen cells, and the tree's rows are wherever the layout
 * put them.
 */
final class PointerDropTest extends TestCase
{
    use BootsIde;
    use PumpsLoop;
    use ScreenReads;

    private TempProject $outside;

    protected function setUp(): void
    {
        $this->outside = new TempProject(['x.php' => "<?php\n// x\n", 'a b.txt' => '']);
        $this->bootIde(['composer.json' => '{}', 'src/A.php' => "<?php\n", 'lib/B.php' => "<?php\n", 'top.php' => "<?php\n", 'lines.php' => "<?php\n// one\n// two\n// three\n"]);
        $this->frame($this->tui());
        $this->terminal->clearOutput();
    }

    public function testTheBootAsksForDropsAndTheTeardownStopsAsking(): void
    {
        $this->assertStringContainsString(DragDropProtocol::OPT_OUT, TerminalRestorer::RESTORE);
        $this->assertSame("\x1b]72;t=a;text/uri-list text/plain\x1b\\", DragDropProtocol::OPT_IN);
    }

    public function testAHoverOverTheTreeIsAcceptedAndHighlightsTheFolderUnderIt(): void
    {
        [$row, $col] = $this->treeCell('src');

        $this->send("t=m:x={$col}:y={$row}:o=3");

        $this->assertSame("\x1b]72;t=m:o=1;text/uri-list text/plain\x1b\\", $this->replies());
        $this->assertSame($this->project->path('src'), Probe::get($this->tree(), 'dropTarget'));
    }

    public function testAFolderHoveredLongEnoughSpringsOpenAndClosesOnceTheDragMovesOff(): void
    {
        [$row, $col] = $this->treeCell('src');
        $this->send("t=m:x={$col}:y={$row}");
        $this->pump(0.6);
        $this->assertTrue(Probe::get($this->tree(), 'expanded')[$this->project->path('src')] ?? false, 'sprung open');

        $this->frame($this->tui());
        [$row, $col] = $this->treeCell('src/A.php');
        $this->send("t=m:x={$col}:y={$row}");
        $this->assertTrue(Probe::get($this->tree(), 'expanded')[$this->project->path('src')], 'a row inside it keeps it open');

        [$row, $col] = $this->treeCell('lib');
        $this->send("t=m:x={$col}:y={$row}");
        $this->assertFalse(Probe::get($this->tree(), 'expanded')[$this->project->path('src')], 'moving off closes it');
        $this->assertSame($this->project->path('lib'), Probe::get($this->tree(), 'dropTarget'), 'and the new folder is the destination');
    }

    public function testLeavingTheWindowClearsTheHighlightAndClosesWhatSprangOpen(): void
    {
        [$row, $col] = $this->treeCell('src');
        $this->send("t=m:x={$col}:y={$row}");
        $this->pump(0.6);

        $this->send('t=m:x=-1:y=-1');

        $this->assertNull(Probe::get($this->tree(), 'dropTarget'));
        $this->assertFalse(Probe::get($this->tree(), 'expanded')[$this->project->path('src')]);
    }

    public function testADropOnAFolderCopiesTheFilesInChunksAndKeepsTheFolderOpen(): void
    {
        [$row, $col] = $this->treeCell('src');
        $this->send("t=m:x={$col}:y={$row}");
        $this->pump(0.6);
        $this->replies();
        $data = \base64_encode("# kitty\r\nfile://".\str_replace(search: ' ', replace: '%20', subject: $this->outside->path('x.php'))."\r\n");

        $this->send("t=M:x={$col}:y={$row}:o=1;text/plain text/uri-list");
        $this->assertSame("\x1b]72;t=r:x=2\x1b\\", $this->replies(), 'asks for the URI list, by its place in the offer');
        $this->send('t=r:x=2:m=1;'.\substr($data, offset: 0, length: 5));
        $this->assertFileDoesNotExist($this->project->path('src/x.php'), 'more to come');
        $this->send('t=r:x=2:m=0;'.\substr($data, offset: 5));

        $this->assertSame("\x1b]72;t=r:o=1\x1b\\", $this->replies());
        $this->assertSame("<?php\n// x\n", \file_get_contents($this->project->path('src/x.php')));
        $this->assertTrue(Probe::get($this->tree(), 'expanded')[$this->project->path('src')], 'the folder it landed in stays open');
        $this->assertNull(Probe::get($this->tree(), 'dropTarget'));
    }

    public function testADropBelowTheLastRowLandsInTheProjectRoot(): void
    {
        $rect = $this->hit()->rect($this->tree());
        $this->assertInstanceOf(WidgetRect::class, $rect);
        $row = $rect->row + $rect->rows - 2;
        $this->assertNull($this->tree()->nodeAtRow($rect->rows - 2), 'the fixture leaves blank rows under the tree');

        $this->drop($row, $rect->col + 2, $this->outside->path('x.php'));

        $this->assertFileExists($this->project->path('x.php'));
    }

    public function testPlainTextIsTakenWhenNoUriListIsOffered(): void
    {
        [$row, $col] = $this->treeCell('lib');

        $this->send("t=M:x={$col}:y={$row};text/plain");
        $this->assertSame("\x1b]72;t=r:x=1\x1b\\", $this->replies());
        $this->send('t=r:x=1;'.\base64_encode(\str_replace(search: ' ', replace: '\ ', subject: $this->outside->path('x.php'))));

        $this->assertFileExists($this->project->path('lib/x.php'));
    }

    public function testADropOnTheTabStripOpensTheFile(): void
    {
        $panes = Probe::get($this->dropTarget(), 'panes');
        $this->assertInstanceOf(EditorLayoutPanes::class, $panes);
        $rect = $this->hit()->rect($panes->tabBar);
        $this->assertInstanceOf(WidgetRect::class, $rect);

        $this->drop($rect->row, $rect->col + 2, $this->outside->path('x.php'));

        $this->assertSame((string) \realpath($this->outside->path('x.php')), $this->buffers()->active()?->path);
    }

    public function testADropOnAnOpenFileAsks(): void
    {
        Services::resolve($this->ide, BufferService::class)->openFile($this->project->path('top.php'), OpenFocus::Editor);
        $this->frame($this->tui());
        [$row, $col] = $this->editorCell();

        $this->drop($row, $col, $this->outside->path('x.php'));

        $this->assertTrue($this->editor()->isAnyPickerOpen());
    }

    public function testADropOnAnEmptyEditorOpensTheFile(): void
    {
        [$row, $col] = $this->editorCell();

        $this->drop($row, $col, $this->outside->path('x.php'));

        $this->assertSame((string) \realpath($this->outside->path('x.php')), $this->buffers()->active()?->path);
    }

    public function testTheCaretFollowsADragOverTheTextAndStaysWhereItLands(): void
    {
        $doc = $this->openLines();
        $doc->setCursor(0, 0);

        [$row, $col] = $this->textCell(2, 3);
        $this->send("t=m:x={$col}:y={$row}");
        $this->assertSame([2, 3], [$doc->cursorLine, $doc->cursorCol], 'the caret is under the pointer');

        $this->drop($row, $col, $this->outside->path('a b.txt'));
        $this->tui()->handleInput("\r"); // Insert Path

        $this->assertSame('// '.$this->outside->path('a b.txt').'two', $doc->getLine(2), 'inserted where it was let go');

        [$treeRow, $treeCol] = $this->treeCell('lib');
        $this->send("t=m:x={$treeCol}:y={$treeRow}");
        $this->assertSame(2, $doc->cursorLine, 'the next drag passing by has no older caret to put back');
    }

    public function testADragThatMovesOnPutsTheCaretAndSelectionBack(): void
    {
        $doc = $this->openLines();
        $doc->setSelectionRange(1, 0, 1, 5);
        [$row, $col] = $this->textCell(3, 2);

        $this->send("t=m:x={$col}:y={$row}");
        $this->assertSame(3, $doc->cursorLine);
        [$treeRow, $treeCol] = $this->treeCell('lib');
        $this->send("t=m:x={$treeCol}:y={$treeRow}");

        $this->assertSame(['startLine' => 1, 'startCol' => 0, 'endLine' => 1, 'endCol' => 5], $doc->getSelectionRange(), 'back where the user left it');

        $this->send("t=m:x={$col}:y={$row}");
        $this->send('t=m:x=-1:y=-1');
        $this->assertSame([1, 5], [$doc->cursorLine, $doc->cursorCol], 'and when the drag leaves the window');
    }

    public function testABareCaretIsPutBackToo(): void
    {
        $doc = $this->openLines();
        $doc->setCursor(1, 4);
        [$row, $col] = $this->textCell(3, 2);

        $this->send("t=m:x={$col}:y={$row}");
        $this->send('t=m:x=-1:y=-1');

        $this->assertSame([1, 4], [$doc->cursorLine, $doc->cursorCol]);
        $this->assertFalse($doc->hasSelection());
    }

    public function testAFileSwitchedAwayFromGetsItsOwnCaretBack(): void
    {
        $lines = $this->openLines();
        $lines->setCursor(1, 1);
        [$row, $col] = $this->textCell(3, 2);
        $this->send("t=m:x={$col}:y={$row}");
        Services::resolve($this->ide, BufferService::class)->openFile($this->project->path('top.php'), OpenFocus::Editor);
        $top = $this->editor()->getDocument();
        $top->setCursor(0, 3);

        $this->send('t=m:x=-1:y=-1');

        $this->assertSame([1, 1], [$lines->cursorLine, $lines->cursorCol], 'put back where it was taken from');
        $this->assertSame([0, 3], [$top->cursorLine, $top->cursorCol], 'and the file now shown keeps its own');
    }

    public function testADragOverTheErrorStripeLeavesTheCaretAlone(): void
    {
        $doc = $this->openLines();
        $doc->setCursor(1, 2);
        $rect = $this->hit()->rect($this->editor());
        $this->assertInstanceOf(WidgetRect::class, $rect);
        $stripe = $rect->col + $this->editor()->viewport()->stripeCol;

        $this->send("t=m:x={$stripe}:y=".($rect->row + 3));

        $this->assertSame([1, 2], [$doc->cursorLine, $doc->cursorCol]);
    }

    public function testAUrlDroppedOnAFieldIsTypedThere(): void
    {
        $typed = null;
        $this->editor()->openPrompt('Name', '', static function (string $value) use (&$typed): void {
            $typed = $value;
        });
        [$row, $col] = $this->editorCell();

        $this->send("t=M:x={$col}:y={$row};text/uri-list text/plain");
        $this->assertSame("\x1b]72;t=r:x=2\x1b\\", $this->replies(), 'a field asks for the plain text first');
        $this->send('t=r:x=2;'.\base64_encode("https://example.com/a\n"));
        $this->tui()->handleInput("\r");

        $this->assertSame("\x1b]72;t=r:o=1\x1b\\", $this->replies());
        $this->assertSame('https://example.com/a', $typed, 'any text is text to a field');
    }

    public function testAPastedDropLandsInTheTreeFolderUnderThePointer(): void
    {
        $this->tree()->revealPath($this->project->path('src/A.php'));
        $this->tui()->setFocus($this->tree());
        [$row, $col] = $this->treeCell('lib');

        $this->paste($this->outside->path('x.php'));
        $this->assertFileDoesNotExist($this->project->path('src/x.php'), 'held for the pointer');
        $this->tui()->handleInput($this->motion($row, $col));

        $this->assertFileExists($this->project->path('lib/x.php'), 'where it was let go, not the selected folder');
        $this->assertFileDoesNotExist($this->project->path('src/x.php'));
    }

    public function testAPastedDropOnAnOpenFileInsertsWhereItWasLetGo(): void
    {
        $doc = $this->openLines();
        $doc->setCursor(0, 0);
        [$row, $col] = $this->textCell(2, 3);

        $this->paste(\str_replace(search: ' ', replace: '\ ', subject: $this->outside->path('a b.txt')));
        $this->tui()->handleInput($this->motion($row, $col));
        $this->tui()->handleInput("\r"); // Insert Path

        $this->assertSame('// '.$this->outside->path('a b.txt').'two', $doc->getLine(2));
    }

    public function testAPastedDropOverNoZoneFallsBackToTheFocus(): void
    {
        $this->tree()->revealPath($this->project->path('src/A.php'));
        $this->tui()->setFocus($this->tree());

        $this->paste($this->outside->path('x.php'));
        $this->tui()->handleInput($this->motion($this->rows - 1, 0)); // the status bar

        $this->assertFileExists($this->project->path('src/x.php'), 'the selected folder, as with no pointer at all');
    }

    public function testASecondPastedDropLetsTheFirstLandFirst(): void
    {
        $this->tree()->revealPath($this->project->path('src/A.php'));
        $this->tui()->setFocus($this->tree());

        $this->paste($this->outside->path('x.php'));
        $this->paste(\str_replace(search: ' ', replace: '\ ', subject: $this->outside->path('a b.txt')));
        $this->frame($this->tui()); // the first copy added a row: find lib where it is now
        [$row, $col] = $this->treeCell('lib');
        $this->tui()->handleInput($this->motion($row, $col));

        $this->assertFileExists($this->project->path('src/x.php'), 'the first, by the focus, before the second took its place');
        $this->assertFileExists($this->project->path('lib/a b.txt'), 'the second, under the pointer');
    }

    public function testAKeyAfterAPastedDropLetsTheDropLandFirst(): void
    {
        $this->tree()->revealPath($this->project->path('src/A.php'));
        $this->tui()->setFocus($this->tree());

        $this->paste($this->outside->path('x.php'));
        $this->tui()->handleInput("\x1b[B");

        $this->assertFileExists($this->project->path('src/x.php'), 'by the focus, before the key moved the selection');
    }

    public function testAPastedDropWithNoPointerLandsByTheFocusOnceTheWaitIsOver(): void
    {
        $this->tree()->revealPath($this->project->path('src/A.php'));
        $this->tui()->setFocus($this->tree());

        $this->paste($this->outside->path('x.php'));
        $this->pump(PasteDrop::WAIT * 2);

        $this->assertFileExists($this->project->path('src/x.php'));
    }

    public function testTheWelcomeCardTakesADropAnywhere(): void
    {
        Services::resolve($this->ide, ViewRegistry::class)->open('welcome');

        $this->send('t=m:x=0:y=0');

        $this->assertSame("\x1b]72;t=m:o=1;text/uri-list text/plain\x1b\\", $this->replies());
    }

    public function testTheStatusBarIsNoDropZone(): void
    {
        $this->send('t=m:x=0:y='.($this->rows - 1));

        $this->assertSame("\x1b]72;t=m:o=0\x1b\\", $this->replies());
    }

    public function testAModalViewTakesADropAnywhereAsText(): void
    {
        Services::resolve($this->ide, ViewRegistry::class)->open('settings');

        $this->send('t=m:x=0:y='.($this->rows - 1));

        $this->assertSame("\x1b]72;t=m:o=1;text/uri-list text/plain\x1b\\", $this->replies(), 'even over what would be the status bar');
    }

    public function testADropOnAnOpenPickerIsTypedIntoItPlainly(): void
    {
        $typed = null;
        $this->editor()->openPrompt('Name', '', static function (string $value) use (&$typed): void {
            $typed = $value;
        });
        [$row, $col] = $this->treeCell('src');

        $this->drop($row, $col, \str_replace(search: ' ', replace: '%20', subject: $this->outside->path('a b.txt')));
        $this->tui()->handleInput("\r");

        $this->assertSame($this->outside->path('a b.txt'), $typed, 'decoded, unquoted, as dropped');
        $this->assertFileDoesNotExist($this->project->path('src/a b.txt'), 'the tree under the picker copied nothing');
    }

    public function testADropNowhereOrOfNoUsableTypeIsRefused(): void
    {
        $this->send('t=M:x=0:y='.($this->rows - 1).';text/uri-list');
        $this->assertSame("\x1b]72;t=r:o=0\x1b\\", $this->replies());

        [$row, $col] = $this->editorCell();
        $this->send("t=M:x={$col}:y={$row};image/png");
        $this->assertSame("\x1b]72;t=r:o=0\x1b\\", $this->replies());

        $this->send('t=M:x=-1:y=-1;text/uri-list');
        $this->assertSame("\x1b]72;t=r:o=0\x1b\\", $this->replies());

        $this->send('t=r:x=1;'.\base64_encode('file:///etc'));
        $this->assertSame('', $this->replies(), 'data for no pending drop is ignored');
    }

    public function testDataThatNamesNoFileIsRefusedOutLoud(): void
    {
        [$row, $col] = $this->editorCell();

        $this->drop($row, $col, '/no/such/file');

        $this->assertSame("\x1b]72;t=r:x=1\x1b\\\x1b]72;t=r:o=0\x1b\\", $this->replies());
        $this->assertSame('Only files and folders can be dropped here', Toasts::last($this->ide));
    }

    public function testTheTerminalsErrorEndsTheDrop(): void
    {
        [$row, $col] = $this->editorCell();
        $this->send("t=M:x={$col}:y={$row};text/uri-list");
        $this->replies();

        $this->send('t=R:x=1;EPERM:not allowed');
        $this->send('t=r:x=1;'.\base64_encode('file://'.$this->outside->path('x.php')));

        $this->assertSame("\x1b]72;t=r:o=0\x1b\\", $this->replies(), 'cancelled, and the late data finds nothing pending');
        $this->assertSame('The drop failed: EPERM:not allowed', Toasts::last($this->ide));
        $this->assertNull($this->buffers()->active());
    }

    public function testABelTerminatedSequenceAndAnUnknownTypeAreSwallowedQuietly(): void
    {
        $this->tui()->handleInput("\x1b]72;t=q:i=x\x07");
        $this->tui()->handleInput("\x1b]72;t=m\x07");

        $this->assertSame('', $this->replies(), 'nothing answered to a query nobody asked, and a move without a cell is a leave');
    }

    /** Hover, drop and deliver $path as a one-chunk URI list at a screen cell. */
    private function drop(int $row, int $col, string $path): void
    {
        $this->send("t=M:x={$col}:y={$row};text/uri-list");
        $this->send('t=r:x=1;'.\base64_encode('file://'.$path));
    }

    private function paste(string $text): void
    {
        $this->tui()->handleInput("\x1b[200~".$text."\x1b[201~");
    }

    /** A bare pointer motion at a screen cell, as SGR reports it: 1-based. */
    private function motion(int $row, int $col): string
    {
        return \sprintf("\x1b[<35;%d;%dM", $col + 1, $row + 1);
    }

    private function send(string $body): void
    {
        $this->tui()->handleInput("\x1b]72;".$body."\x1b\\");
    }

    /** What the protocol wrote back since the last read: only its own OSC 72s. */
    private function replies(): string
    {
        \preg_match_all('/\x1b\]72;.*?\x1b\\\\/s', $this->terminal->consumeOutput(), $matches);

        return \implode('', $matches[0]);
    }

    /** @return array{int, int} the screen row and column of the tree row showing $relative */
    private function treeCell(string $relative): array
    {
        $rect = $this->hit()->rect($this->tree());
        $this->assertInstanceOf(WidgetRect::class, $rect);
        for ($relRow = 0; $relRow < $rect->rows; ++$relRow) {
            if ($this->tree()->nodeAtRow($relRow)?->path === $this->project->path($relative)) {
                return [$rect->row + $relRow, $rect->col + 2];
            }
        }
        $this->fail("{$relative} is not on screen");
    }

    private function openLines(): Document
    {
        Services::resolve($this->ide, BufferService::class)->openFile($this->project->path('lines.php'), OpenFocus::Editor);
        $this->frame($this->tui());

        return $this->editor()->getDocument();
    }

    /** @return array{int, int} the screen cell showing byte $col of document line $line */
    private function textCell(int $line, int $col): array
    {
        $rect = $this->hit()->rect($this->editor());
        $this->assertInstanceOf(WidgetRect::class, $rect);
        for ($relRow = 0; $relRow < $rect->rows; ++$relRow) {
            if ($this->editor()->documentLineAt($relRow) === $line) {
                return [$rect->row + $relRow, $rect->col + $this->editor()->gutterWidth() + $col];
            }
        }
        $this->fail("line {$line} is not on screen");
    }

    /** @return array{int, int} a screen cell in the middle of the editor */
    private function editorCell(): array
    {
        $rect = $this->hit()->rect($this->editor());
        $this->assertInstanceOf(WidgetRect::class, $rect);

        return [$rect->row + \intdiv($rect->rows, num2: 2), $rect->col + \intdiv($rect->columns, num2: 2)];
    }

    private function dropTarget(): DropTarget
    {
        $target = Probe::get(Services::resolve($this->ide, DragDropProtocol::class), 'target');
        $this->assertInstanceOf(DropTarget::class, $target);

        return $target;
    }

    private function tui(): Tui
    {
        return Services::resolve($this->ide, Tui::class);
    }

    private function hit(): HitTester
    {
        // Inlined into its consumers, so reached through one of them.
        $hit = Probe::get($this->dropTarget(), 'hit');
        $this->assertInstanceOf(HitTester::class, $hit);

        return $hit;
    }

    private function tree(): FileTreeWidget
    {
        return Services::resolve($this->ide, FileTreeWidget::class);
    }

    private function editor(): CodeEditorWidget
    {
        return Services::resolve($this->ide, CodeEditorWidget::class);
    }

    private function buffers(): BufferManager
    {
        return Services::resolve($this->ide, BufferManager::class);
    }
}
