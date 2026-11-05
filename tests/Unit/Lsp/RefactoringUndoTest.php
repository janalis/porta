<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Event\FileRewritten;
use Porta\Filesystem\LocalFilesystem;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\RefactoringUndo;
use Porta\Lsp\Uri;
use Porta\Lsp\WorkspaceEditor;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\RecordingProjectFiles;
use Porta\Tests\Support\TempProject;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Taking back a whole multi-file refactoring.
 *
 * `Ctrl+Z` is per DOCUMENT, so a rename across twenty files was twenty undos
 * in the buffers that happened to be open and nothing at all for the rest.
 * Every test here is about the two things that make this safe rather than
 * clever: it spans both kinds of participant, and it REFUSES when anything has
 * moved on since.
 */
final class RefactoringUndoTest extends TestCase
{
    private BufferManager $buffers;
    private RefactoringUndo $undo;

    private function workspaceEditor(): WorkspaceEditor
    {
        $this->buffers = new BufferManager();
        $events = new EventDispatcher();
        $this->undo = new RefactoringUndo($this->buffers, $events);

        return new WorkspaceEditor(
            $this->buffers,
            new CodeEditorWidget(),
            new LspSessionPool(\sys_get_temp_dir(), new FixedActiveFile(), new Translator('en')),
            $events,
            $this->undo,
        );
    }

    /** @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string} */
    private static function edit(int $from, int $to, string $text): array
    {
        return [
            'range' => ['start' => ['line' => 0, 'character' => $from], 'end' => ['line' => 0, 'character' => $to]],
            'newText' => $text,
        ];
    }

    public function testNothingToUndoBeforeAnyRefactoring(): void
    {
        $this->workspaceEditor();

        $this->assertFalse($this->undo->hasPending());
    }

    /**
     * The point of the whole thing: an open buffer and a file with no buffer
     * come back TOGETHER. Either alone was already possible — the buffer
     * through `Ctrl+Z`, the file through local history — and neither is what a
     * rename needs.
     */
    public function testABufferAndADiskFileComeBackTogether(): void
    {
        $project = new TempProject(['closed.php' => "old closed\n"]);
        $we = $this->workspaceEditor();
        $open = $this->buffers->open('/p/open.php', "old open\n");

        $we->applyWorkspaceEdit([
            'changes' => [
                Uri::fromPath('/p/open.php') => [self::edit(0, 8, 'new open')],
                Uri::fromPath($project->path('closed.php')) => [self::edit(0, 10, 'new closed')],
            ],
        ]);
        $this->assertSame("new open\n", $open->document->getText());
        $this->assertSame("new closed\n", \file_get_contents($project->path('closed.php')));
        $this->assertTrue($this->undo->hasPending());

        $this->assertNull($this->undo->undo(), 'nothing blocked it');

        $this->assertSame("old open\n", $open->document->getText());
        $this->assertSame("old closed\n", \file_get_contents($project->path('closed.php')));
    }

    /**
     * One refactoring is remembered, so undoing it leaves nothing pending.
     *
     * The buffer is opened BEFORE the edit, which the first draft of this test
     * got backwards — with no buffer and no file on disk the edit recorded
     * nothing, so `hasPending()` was already false and the assertion passed
     * against a class that never cleared anything.
     */
    public function testUndoingClearsWhatItTookBack(): void
    {
        $we = $this->workspaceEditor();
        $this->buffers->open('/p/a.php', "abc\n");

        $we->applyWorkspaceEdit(['changes' => [Uri::fromPath('/p/a.php') => [self::edit(0, 1, 'X')]]]);
        $this->assertTrue($this->undo->hasPending(), 'something was recorded to take back');

        $this->undo->undo();

        $this->assertFalse($this->undo->hasPending(), 'and a second press has nothing to take back');
    }

    /**
     * A SECOND refactoring forgets the first. Only the last one is remembered,
     * so undoing takes back what you just did and not something from ten
     * minutes ago that you have since built on.
     */
    public function testANewRefactoringReplacesThePreviousOne(): void
    {
        $we = $this->workspaceEditor();
        $first = $this->buffers->open('/p/first.php', "one\n");
        $we->applyWorkspaceEdit(['changes' => [Uri::fromPath('/p/first.php') => [self::edit(0, 3, 'ONE')]]]);

        $second = $this->buffers->open('/p/second.php', "two\n");
        $we->applyWorkspaceEdit(['changes' => [Uri::fromPath('/p/second.php') => [self::edit(0, 3, 'TWO')]]]);

        $this->assertNull($this->undo->undo());

        $this->assertSame("two\n", $second->document->getText(), 'the second came back');
        $this->assertSame("ONE\n", $first->document->getText(), 'and the first is left where it was');
    }

    /** The same, through the `documentChanges` shape, which begins separately. */
    public function testANewDocumentChangesRefactoringAlsoReplacesThePreviousOne(): void
    {
        $we = $this->workspaceEditor();
        $first = $this->buffers->open('/p/first.php', "one\n");
        $we->applyWorkspaceEdit(['changes' => [Uri::fromPath('/p/first.php') => [self::edit(0, 3, 'ONE')]]]);

        $second = $this->buffers->open('/p/second.php', "two\n");
        $we->applyWorkspaceEdit([
            'documentChanges' => [
                ['textDocument' => ['uri' => Uri::fromPath('/p/second.php')], 'edits' => [self::edit(0, 3, 'TWO')]],
            ],
        ]);

        $this->assertNull($this->undo->undo());

        $this->assertSame("two\n", $second->document->getText());
        $this->assertSame("ONE\n", $first->document->getText(), 'the first is left where it was');
    }

    /**
     * An edit that changes NOTHING does not throw away the undo before it.
     *
     * Shipped broken once: the group was cleared eagerly at the start of every
     * application, so a second refactoring whose every file was refused —
     * overlapping edits — left the user with nothing to undo, for a keystroke
     * that had done nothing. Placing the eager clear after the resource-refusal
     * check only moved the hole to the next kind of refusal, which is deeper
     * and per-file. The group opens on the first thing RECORDED instead, so
     * "nothing recorded" and "nothing forgotten" are one condition whatever the
     * reason.
     */
    public function testAnEditThatAppliesNothingLeavesTheEarlierUndoAlone(): void
    {
        $we = $this->workspaceEditor();
        $first = $this->buffers->open('/p/first.php', "one\n");
        $we->applyWorkspaceEdit(['changes' => [Uri::fromPath('/p/first.php') => [self::edit(0, 3, 'ONE')]]]);

        $this->buffers->open('/p/second.php', "two\n");
        $we->applyWorkspaceEdit([
            'changes' => [
                Uri::fromPath('/p/second.php') => [
                    // Overlapping: refused per file, deep inside the walk.
                    self::edit(0, 3, 'X'),
                    self::edit(1, 3, 'Y'),
                ],
            ],
        ]);

        $this->assertTrue($this->undo->hasPending(), 'the first refactoring survives an edit that did nothing');
        $this->assertNull($this->undo->undo());
        $this->assertSame("one\n", $first->document->getText());
    }

    /**
     * A refactoring that CREATES, renames or deletes a file is not offered for
     * undo at all.
     *
     * Only text is remembered, so undoing an "extract to new class" would put
     * the words back and leave the new file standing — the partial state this
     * class exists to refuse. And the previous refactoring is not the answer
     * either: undo means the thing you just did, so it is forgotten too.
     */
    public function testAResourceOperationPutsTheEditBeyondUndo(): void
    {
        $project = new TempProject(['a.php' => "one\n"]);
        $we = $this->workspaceEditor();
        $this->buffers->open($project->path('a.php'), "one\n");
        $we->applyWorkspaceEdit(['changes' => [Uri::fromPath($project->path('a.php')) => [self::edit(0, 3, 'ONE')]]]);
        $this->assertTrue($this->undo->hasPending(), 'a plain text edit is undoable');

        $we->applyWorkspaceEdit([
            'documentChanges' => [
                ['kind' => 'create', 'uri' => Uri::fromPath($project->path('made.php'))],
                ['textDocument' => ['uri' => Uri::fromPath($project->path('made.php'))], 'edits' => [self::edit(0, 0, 'hello')]],
            ],
        ]);

        $this->assertFileExists($project->path('made.php'), 'the refactoring itself still happened');
        $this->assertFalse($this->undo->hasPending(), 'and nothing is offered to take it back');
    }

    /** The restore goes through the project's files, as the refactoring's writes did — in a remote project, to the server. */
    public function testARestoreGoesThroughTheProjectsFiles(): void
    {
        $project = new TempProject(['a.php' => 'after']);
        $files = new RecordingProjectFiles();
        $undo = new RefactoringUndo(new BufferManager(), new EventDispatcher(), $files);
        $undo->begin();
        $undo->recordFile($project->path('a.php'), 'before', 'after');

        $undo->undo();

        $this->assertSame(['write a.php'], $files->did);
        $this->assertSame('before', \file_get_contents($project->path('a.php')));
    }

    /**
     * A restore that cannot be written names the file rather than throwing.
     *
     * The same shape the FORWARD direction has for an unwritable file: the
     * check passed a moment ago and the write still failed (a full disk, a
     * read-only mount, a race). Uncaught it would escape into the event loop
     * with buffers already rolled back, which is the worst of both.
     */
    public function testAnUnwritableRestoreIsNamedRatherThanThrown(): void
    {
        $throwing = new class extends Filesystem {
            #[\Override]
            public function dumpFile(string $filename, $content): void
            {
                throw new IOException('disk full');
            }
        };
        // The file must be ON DISK holding what the refactoring wrote, or the
        // guard blocks first and the write is never attempted — which would
        // pass this test without exercising the catch at all.
        $project = new TempProject(['a.php' => 'after']);
        $undo = new RefactoringUndo(new BufferManager(), new EventDispatcher(), new LocalFilesystem($throwing));
        $undo->begin();
        $undo->recordFile($project->path('a.php'), 'before', 'after');

        $this->assertSame($project->path('a.php'), $undo->undo());
    }

    /**
     * Restoring a disk file announces what IT replaced — the refactored
     * version, which is about to exist nowhere. Without it, undoing a
     * refactoring loses the refactoring irrecoverably, the same hole the
     * forward direction was fixed for.
     */
    public function testUndoingADiskFileAnnouncesTheVersionItDiscards(): void
    {
        $project = new TempProject(['a.php' => "before\n"]);
        $seen = [];
        $events = new EventDispatcher();
        $this->buffers = new BufferManager();
        $this->undo = new RefactoringUndo($this->buffers, $events);
        $we = new WorkspaceEditor(
            $this->buffers,
            new CodeEditorWidget(),
            new LspSessionPool(\sys_get_temp_dir(), new FixedActiveFile(), new Translator('en')),
            $events,
            $this->undo,
        );
        $we->applyWorkspaceEdit(['changes' => [Uri::fromPath($project->path('a.php')) => [self::edit(0, 6, 'after!')]]]);
        $events->addListener(FileRewritten::class, static function (FileRewritten $e) use (&$seen): void {
            $seen[] = $e->previousText;
        });

        $this->undo->undo();

        $this->assertSame(["after!\n"], $seen, 'the refactored text, on its way out');
    }

    /**
     * A buffer typed into since blocks EVERYTHING, and names itself.
     *
     * Undoing it would take back the typing rather than the rename — the
     * document's undo stack has no idea which step was the refactoring — and a
     * partial undo of a refactoring is the state nobody can reason about.
     */
    public function testABufferEditedSinceBlocksTheWholeUndo(): void
    {
        $project = new TempProject(['closed.php' => "old closed\n"]);
        $we = $this->workspaceEditor();
        $open = $this->buffers->open('/p/open.php', "old open\n");

        $we->applyWorkspaceEdit([
            'changes' => [
                Uri::fromPath('/p/open.php') => [self::edit(0, 8, 'new open')],
                Uri::fromPath($project->path('closed.php')) => [self::edit(0, 10, 'new closed')],
            ],
        ]);

        $open->document->setCursor(0, 0);
        $open->document->insert('typed ');

        $this->assertSame('/p/open.php', $this->undo->undo(), 'it names the file that moved on');
        $this->assertStringContainsString('typed ', $open->document->getText(), 'the typing survives');
        $this->assertSame("new closed\n", \file_get_contents($project->path('closed.php')), 'and the OTHER file is untouched too');
    }

    /**
     * A disk file changed behind Porta's back blocks it just as hard —
     * restoring would discard whatever changed it, which may be a colleague's
     * commit or another tool.
     */
    public function testADiskFileChangedSinceBlocksTheWholeUndo(): void
    {
        $project = new TempProject(['closed.php' => "old closed\n"]);
        $we = $this->workspaceEditor();
        $open = $this->buffers->open('/p/open.php', "old open\n");

        $we->applyWorkspaceEdit([
            'changes' => [
                Uri::fromPath('/p/open.php') => [self::edit(0, 8, 'new open')],
                Uri::fromPath($project->path('closed.php')) => [self::edit(0, 10, 'new closed')],
            ],
        ]);

        \file_put_contents($project->path('closed.php'), data: "someone else\n");

        $this->assertSame(\realpath($project->path('closed.php')), $this->undo->undo());
        $this->assertSame("someone else\n", \file_get_contents($project->path('closed.php')), 'their content survives');
        $this->assertSame("new open\n", $open->document->getText(), 'and the buffer was not rolled back either');
    }

    /**
     * When the ONLY participant has been closed there is nothing to offer.
     *
     * A closed buffer is deliberately skipped by the undo — it has nothing left
     * to take back — so counting it as pending promised a restore that could
     * not happen: refactor one file, close its tab, press the key, and the
     * answer was "Refactoring undone" over a workspace nothing had touched.
     * What is pending is what could actually be restored.
     */
    public function testClosingTheOnlyParticipantLeavesNothingToUndo(): void
    {
        $we = $this->workspaceEditor();
        $this->buffers->open('/p/a.php', "one\n");
        $we->applyWorkspaceEdit(['changes' => [Uri::fromPath('/p/a.php') => [self::edit(0, 3, 'ONE')]]]);
        $this->assertTrue($this->undo->hasPending());

        $this->buffers->close('/p/a.php');

        $this->assertFalse($this->undo->hasPending(), 'and the verb says so instead of claiming success');
    }

    /**
     * A buffer CLOSED since is not a blocker.
     *
     * There is nothing left to undo for it — the close either saved it or
     * discarded it — and refusing the whole refactoring because a tab was shut
     * would make the verb useless in exactly the workflow it is for.
     */
    public function testAClosedBufferDoesNotBlockTheRest(): void
    {
        $project = new TempProject(['closed.php' => "old closed\n"]);
        $we = $this->workspaceEditor();
        $this->buffers->open('/p/open.php', "old open\n");

        $we->applyWorkspaceEdit([
            'changes' => [
                Uri::fromPath('/p/open.php') => [self::edit(0, 8, 'new open')],
                Uri::fromPath($project->path('closed.php')) => [self::edit(0, 10, 'new closed')],
            ],
        ]);

        $this->buffers->close('/p/open.php');

        $this->assertNull($this->undo->undo());
        $this->assertSame("old closed\n", \file_get_contents($project->path('closed.php')), 'the file still came back');
    }

    /**
     * A REFUSED edit does not throw away the undo of the one before it: nothing
     * was applied, so the previous refactoring is still the last one.
     */
    public function testARefusedEditLeavesTheEarlierUndoAlone(): void
    {
        $we = $this->workspaceEditor();
        $open = $this->buffers->open('/p/a.php', "old\n");
        $we->applyWorkspaceEdit([
            'documentChanges' => [
                ['textDocument' => ['uri' => Uri::fromPath('/p/a.php')], 'edits' => [self::edit(0, 3, 'new')]],
            ],
        ]);
        $this->assertSame("new\n", $open->document->getText());

        // A rename of a file that is not there cannot be honoured.
        $refused = $we->applyWorkspaceEdit([
            'documentChanges' => [
                ['kind' => 'rename', 'oldUri' => Uri::fromPath('/p/gone.php'), 'newUri' => Uri::fromPath('/p/other.php')],
            ],
        ]);
        $this->assertTrue($refused->isRefused());

        $this->assertTrue($this->undo->hasPending(), 'the earlier one survives');
        $this->assertNull($this->undo->undo());
        $this->assertSame("old\n", $open->document->getText());
    }
}
