<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\DocumentReplacer;
use Porta\Editor\EditorInvalidator;
use Porta\Editor\SelectionMode;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;

/**
 * The one place a whole-document swap is written. Project replace-all wrote it
 * twice and a plugin needs it a third time; the ways to get it wrong are
 * repainting a buffer nobody is looking at and skipping the didChange.
 */
final class DocumentReplacerTest extends TestCase
{
    private BufferManager $buffers;
    private object $invalidations;

    private function replacer(): DocumentReplacer
    {
        $this->buffers = new BufferManager();
        $this->invalidations = new class implements EditorInvalidator {
            public int $count = 0;

            public function invalidate(): void
            {
                ++$this->count;
            }
        };

        return new DocumentReplacer(
            $this->buffers,
            $this->invalidations,
            new LspSessionPool('/p', new FixedActiveFile(), new Translator('en')),
        );
    }

    public function testTheSwapIsOneUndoStepAndKeepsTheCaretInTheNewText(): void
    {
        $replacer = $this->replacer();
        $buffer = $this->buffers->open('/p/a.php', content: "one\ntwo\nthree\n");
        $buffer->document->setCursor(2, col: 4);

        $this->assertTrue($replacer->replace($buffer, text: "new\n"));
        $this->assertSame("new\n", $buffer->document->getText());
        $this->assertSame(1, $buffer->document->cursorLine, 'the caret is clamped into the shorter text, not dumped at the end');

        $buffer->document->undo();
        $this->assertSame("one\ntwo\nthree\n", $buffer->document->getText(), 'exactly one undo puts it all back');
    }

    public function testIdenticalTextIsASilentNoOp(): void
    {
        // A tool that changed nothing must not look like it did: no undo entry,
        // no modified flag, no didChange, no repaint.
        $replacer = $this->replacer();
        $buffer = $this->buffers->open('/p/a.php', content: "same\n");
        $buffer->document->markSaved();

        $this->assertFalse($replacer->replace($buffer, text: "same\n"));
        $this->assertFalse($buffer->document->isDirty(), 'the buffer is not marked modified');

        $buffer->document->undo();
        $this->assertSame("same\n", $buffer->document->getText(), 'and there was nothing to undo');
    }

    public function testAnEmptyReplacementEmptiesTheBuffer(): void
    {
        // The bug the old selectAll()+insert() spelling carried: Document::insert()
        // early-returns on '', so restoring a snapshot of an empty file did
        // nothing while reporting success.
        $replacer = $this->replacer();
        $buffer = $this->buffers->open('/p/a.php', content: "was not empty\n");

        $this->assertTrue($replacer->replace($buffer, text: ''));
        $this->assertSame('', $buffer->document->getText());
    }

    public function testOnlyTheBufferOnScreenRepaints(): void
    {
        // Counted, not eyeballed: an assertion that the editor "still renders"
        // would pass whether or not the parked swap invalidated it.
        $replacer = $this->replacer();
        $shown = $this->buffers->open('/p/a.php', content: "a\n");
        $parked = $this->buffers->open('/p/b.php', content: "b\n");
        $this->buffers->setActive($shown->path);

        $replacer->replace($parked, text: "changed\n");
        $this->assertSame(0, $this->invalidations->count, 'a buffer nobody is looking at does not repaint the editor');

        $replacer->replace($shown, text: "changed\n");
        $this->assertSame(1, $this->invalidations->count, 'the one on screen does');
    }

    public function testTheActiveSwapRefusesWhenThereIsNoTextBufferToSwap(): void
    {
        $replacer = $this->replacer();
        $this->assertFalse($replacer->replaceActive("x\n"), 'nothing open');

        // A viewer tab's document is neutralized — writing into it would edit a
        // buffer whose real content is an image.
        $viewer = $this->buffers->openPane('/p/img.png', paneId: 'image', payload: 'pixels');
        $this->buffers->setActive($viewer->path);
        $this->assertFalse($replacer->replaceActive("x\n"), 'a viewer pane holds the slot');

        $text = $this->buffers->open('/p/a.php', content: "old\n");
        $this->buffers->setActive($text->path);
        $this->assertTrue($replacer->replaceActive("new\n"));
        $this->assertSame("new\n", $text->document->getText());
    }

    public function testABufferThatNowMATCHESDiskIsNotModified(): void
    {
        // A formatter rewrites the file and applies the same bytes here. Saying
        // "modified" then is a lie, and it costs more than a wrong dot: the 1s
        // poller sees an unrecognised stamp, reloads identical content and
        // flashes "Reloaded a.php" after every format.
        $project = new TempProject(['a.php' => "formatted\n"]);
        $replacer = $this->replacer();
        $buffer = $this->buffers->open($project->path('a.php'), content: "unformatted\n");

        $this->assertTrue($replacer->replace($buffer, text: "formatted\n"));
        $this->assertFalse($buffer->document->isDirty(), 'the buffer IS what is on disk');
        $this->assertSame($buffer->diskStampNow(), Probe::get($buffer, property: 'diskStamp'), 'and the poller will recognise it');
    }

    /** A formatter that wrote a CRLF, BOM file: its text is the buffer's, in the file's own shape. */
    public function testACrlfFileWithAMarkMatchesItsBufferToo(): void
    {
        $project = new TempProject(['a.php' => "\u{FEFF}formatted\r\n"]);
        $replacer = $this->replacer();
        $buffer = $this->buffers->open($project->path('a.php'), content: "\u{FEFF}unformatted\r\n");

        $this->assertTrue($replacer->replace($buffer, text: "formatted\n"));
        $this->assertFalse($buffer->document->isDirty(), 'the buffer IS what is on disk');
    }

    public function testABufferThatDivergesFromDiskStaysModified(): void
    {
        // The project-replace case: the buffer is edited, disk is not.
        $project = new TempProject(['a.php' => "on disk\n"]);
        $replacer = $this->replacer();
        $buffer = $this->buffers->open($project->path('a.php'), content: "on disk\n");
        $buffer->document->markSaved();

        $this->assertTrue($replacer->replace($buffer, text: "edited\n"));
        $this->assertTrue($buffer->document->isDirty());
    }

    public function testARangeIsOneUndoStepFollowedByTheSameThreeAsASwap(): void
    {
        $project = new TempProject(['a.php' => "one\nTWO\n"]);
        $replacer = $this->replacer();
        $buffer = $this->buffers->open($project->path('a.php'), content: "one\ntwo\nthree\n");
        $this->buffers->setActive($buffer->path);

        $replacer->replaceRange($buffer, line: 1, column: 0, endLine: 2, endColumn: 5, text: 'TWO');

        $this->assertSame("one\nTWO\n", $buffer->document->getText());
        $this->assertSame([0, 0], [$buffer->document->cursorLine, $buffer->document->cursorCol], 'the caret before the range stays');
        $this->assertFalse($buffer->document->isDirty(), 'it now IS the disk copy');
        $this->assertSame(1, $this->invalidations->count, 'the buffer on screen repaints');

        $replacer->replaceRange($buffer, line: 0, column: 3, endLine: 0, endColumn: 3, text: '!');
        $this->assertSame("one!\nTWO\n", $buffer->document->getText(), 'an empty range inserts');
        $buffer->document->undo();
        $buffer->document->undo();
        $this->assertSame("one\ntwo\nthree\n", $buffer->document->getText(), 'one undo each');
    }

    public function testTheUsersCaretAndSelectionStayWithTheTextTheySatIn(): void
    {
        $replacer = $this->replacer();
        $buffer = $this->buffers->open('/p/a.php', content: "abcdef\nghij\nklm\n");
        $document = $buffer->document;
        $at = static fn (): array => [$document->cursorLine, $document->cursorCol];

        $document->setCursor(1, col: 3); // "ghi|j"
        $replacer->replaceRange($buffer, line: 0, column: 2, endLine: 1, endColumn: 1, text: "X\nYZ"); // "ab[cdef\ng]hij" → "abX\nYZhij"
        $this->assertSame("abX\nYZhij\nklm\n", $document->getText());
        $this->assertSame([1, 4], $at(), 'on the range\'s last line: shifted by columns too');

        $replacer->replaceRange($buffer, line: 0, column: 0, endLine: 0, endColumn: 1, text: '');
        $this->assertSame([1, 4], $at(), 'an edit on an earlier line leaves the columns');

        $document->setCursor(2, col: 1);
        $replacer->replaceRange($buffer, line: 0, column: 0, endLine: 0, endColumn: 0, text: "new\n");
        $this->assertSame([3, 1], $at(), 'a line inserted above moves it down');

        $document->setCursor(1, col: 1);
        $replacer->replaceRange($buffer, line: 1, column: 0, endLine: 2, endColumn: 2, text: 'Q');
        $this->assertSame("new\nQhij\nklm\n", $document->getText());
        $this->assertSame([1, 1], $at(), 'swallowed by the range: at the end of the new text');

        $document->setCursor(2, col: 3);
        $document->beginSelect();
        $document->setCursor(2, col: 1, selection: SelectionMode::Keep); // selected "lm", backwards
        $replacer->replaceRange($buffer, line: 2, column: 0, endLine: 2, endColumn: 0, text: '>>');
        $this->assertSame(['startLine' => 2, 'startCol' => 3, 'endLine' => 2, 'endCol' => 5], $document->getSelectionRange(), 'the selection moved with its text');
        $this->assertSame([2, 3], $at(), 'and the caret is still at the end it was at');

        $document->setCursor(2, col: 5);
        $document->beginSelect();
        $document->setCursor(1, col: 0, selection: SelectionMode::Keep);
        $replacer->replaceRange($buffer, line: 0, column: 0, endLine: 0, endColumn: 3, text: 'NEW');
        $this->assertSame(['startLine' => 1, 'startCol' => 0, 'endLine' => 2, 'endCol' => 5], $document->getSelectionRange(), 'forwards or backwards, across lines');
    }

    public function testTheServerHearsAboutTheSwapAsADidChange(): void
    {
        // The other half of the contract this class exists to keep: a whole-
        // document swap must reach the language server, or its copy silently
        // drifts from ours.
        $this->buffers = new BufferManager();
        $client = null;
        $lsp = new LspSessionPool(
            '/p',
            new FixedActiveFile(),
            new Translator('en'),
            static function () use (&$client): FakeLspClient {
                return $client = new FakeLspClient();
            },
        );
        $lsp->configure(
            ['php' => [['command' => ['php-ls'], 'name' => 'php-ls']]],
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );
        $invalidations = new class implements EditorInvalidator {
            public function invalidate(): void {}
        };
        $replacer = new DocumentReplacer($this->buffers, $invalidations, $lsp);
        $buffer = $this->buffers->open('/p/a.php', content: "one\n");
        $lsp->notifyDidOpen('/p/a.php', text: "one\n", languageId: 'php', version: 1); // starts the session

        $replacer->replace($buffer, text: "two\n");

        $this->assertInstanceOf(FakeLspClient::class, $client);
        $this->assertSame(
            ['textDocument/didOpen', 'textDocument/didChange'],
            \array_column($client->notifications, 'method'),
        );
    }

    public function testSeveralRangesAreOneUndoStepAndTheCaretShiftsThroughEach(): void
    {
        $replacer = $this->replacer();
        $buffer = $this->buffers->open('/p/a.php', content: "one\ntwo\nthree\n");
        $document = $buffer->document;
        $document->setCursor(2, col: 2); // "th|ree"
        $document->beginSelect();
        $document->setCursor(2, col: 4, selection: SelectionMode::Keep); // "ee" selected, the caret after it

        $replacer->replaceRanges($buffer, [
            ['line' => 0, 'column' => 0, 'endLine' => 0, 'endColumn' => 3, 'text' => "ONE\nAND A HALF"],
            ['line' => 1, 'column' => 3, 'endLine' => 1, 'endColumn' => 3, 'text' => '!'],
            ['line' => 2, 'column' => 0, 'endLine' => 2, 'endColumn' => 1, 'text' => ''],
        ]);

        $this->assertSame("ONE\nAND A HALF\ntwo!\nhree\n", $document->getText(), 'each range lands on the text it named');
        $this->assertSame([3, 3], [$document->cursorLine, $document->cursorCol], 'the caret moved down a line and left a column');
        $this->assertSame(['startLine' => 3, 'startCol' => 1, 'endLine' => 3, 'endCol' => 3], $document->getSelectionRange(), 'the selection kept its text');

        $document->undo();
        $this->assertSame("one\ntwo\nthree\n", $document->getText(), 'one undo takes all three back');
    }
}
