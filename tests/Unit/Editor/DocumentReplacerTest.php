<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\DocumentReplacer;
use Porta\Editor\EditorInvalidator;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
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
        $buffer = $this->buffers->open('/p/a.php', "one\ntwo\nthree\n");
        $buffer->document->setCursor(2, 4);

        $this->assertTrue($replacer->replace($buffer, "new\n"));
        $this->assertSame("new\n", $buffer->document->getText());
        $this->assertSame(1, $buffer->document->getCursorLine(), 'the caret is clamped into the shorter text, not dumped at the end');

        $buffer->document->undo();
        $this->assertSame("one\ntwo\nthree\n", $buffer->document->getText(), 'exactly one undo puts it all back');
    }

    public function testIdenticalTextIsASilentNoOp(): void
    {
        // A tool that changed nothing must not look like it did: no undo entry,
        // no modified flag, no didChange, no repaint.
        $replacer = $this->replacer();
        $buffer = $this->buffers->open('/p/a.php', "same\n");
        $buffer->document->markSaved();

        $this->assertFalse($replacer->replace($buffer, "same\n"));
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
        $buffer = $this->buffers->open('/p/a.php', "was not empty\n");

        $this->assertTrue($replacer->replace($buffer, ''));
        $this->assertSame('', $buffer->document->getText());
    }

    public function testOnlyTheBufferOnScreenRepaints(): void
    {
        // Counted, not eyeballed: an assertion that the editor "still renders"
        // would pass whether or not the parked swap invalidated it.
        $replacer = $this->replacer();
        $shown = $this->buffers->open('/p/a.php', "a\n");
        $parked = $this->buffers->open('/p/b.php', "b\n");
        $this->buffers->setActive($shown->path);

        $replacer->replace($parked, "changed\n");
        $this->assertSame(0, $this->invalidations->count, 'a buffer nobody is looking at does not repaint the editor');

        $replacer->replace($shown, "changed\n");
        $this->assertSame(1, $this->invalidations->count, 'the one on screen does');
    }

    public function testTheActiveSwapRefusesWhenThereIsNoTextBufferToSwap(): void
    {
        $replacer = $this->replacer();
        $this->assertFalse($replacer->replaceActive("x\n"), 'nothing open');

        // A viewer tab's document is neutralized — writing into it would edit a
        // buffer whose real content is an image.
        $viewer = $this->buffers->openPane('/p/img.png', 'image', payload: 'pixels');
        $this->buffers->setActive($viewer->path);
        $this->assertFalse($replacer->replaceActive("x\n"), 'a viewer pane holds the slot');

        $text = $this->buffers->open('/p/a.php', "old\n");
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
        $buffer = $this->buffers->open($project->path('a.php'), "unformatted\n");

        $this->assertTrue($replacer->replace($buffer, "formatted\n"));
        $this->assertFalse($buffer->document->isDirty(), 'the buffer IS what is on disk');
        $this->assertSame($buffer->diskStampNow(), Probe::get($buffer, 'diskStamp'), 'and the poller will recognise it');
    }

    public function testABufferThatDivergesFromDiskStaysModified(): void
    {
        // The project-replace case: the buffer is edited, disk is not.
        $project = new TempProject(['a.php' => "on disk\n"]);
        $replacer = $this->replacer();
        $buffer = $this->buffers->open($project->path('a.php'), "on disk\n");
        $buffer->document->markSaved();

        $this->assertTrue($replacer->replace($buffer, "edited\n"));
        $this->assertTrue($buffer->document->isDirty());
    }
}
