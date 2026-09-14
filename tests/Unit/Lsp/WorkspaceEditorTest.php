<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\Document;
use Porta\Event\BufferRepointRequested;
use Porta\Event\FileDeleted;
use Porta\Event\FileRenamed;
use Porta\Event\FileRewritten;
use Porta\Event\WorkingTreeChanged;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\RefactoringUndo;
use Porta\Lsp\Uri;
use Porta\Lsp\WorkspaceEditor;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final class WorkspaceEditorTest extends TestCase
{
    use StatusReads;

    private BufferManager $buffers;
    private CodeEditorWidget $editor;
    private StatusBarWidget $statusBar;

    private function workspaceEditor(?EventDispatcher $events = null): WorkspaceEditor
    {
        $this->buffers = new BufferManager();
        $this->editor = new CodeEditorWidget();

        return new WorkspaceEditor(
            $this->buffers,
            $this->editor,
            new LspSessionPool(\sys_get_temp_dir(), new FixedActiveFile(), new Translator('en')),
            $events ?? new EventDispatcher(),
            new RefactoringUndo(new BufferManager(), new EventDispatcher()),
            notifier: new Notifier($this->statusBar = new StatusBarWidget(), TestTui::create()),
        );
    }

    /** @param array{start: array{line?: int, character?: int}, end: array{line?: int, character?: int}} $range */
    private static function edit(array $range, string $newText): array
    {
        return ['range' => $range, 'newText' => $newText];
    }

    public function testMultiEditRenameIsASingleUndoStep(): void
    {
        $doc = new Document("foo();\nfoo();\nfoo();\n");
        // Rename every `foo` → `bar` (three separate text edits).
        $edits = [];
        for ($line = 0; $line < 3; ++$line) {
            $edits[] = [
                'range' => ['start' => ['line' => $line, 'character' => 0], 'end' => ['line' => $line, 'character' => 3]],
                'newText' => 'bar',
            ];
        }

        $this->workspaceEditor()->applyTextEdits($doc, $edits);
        $this->assertSame("bar();\nbar();\nbar();\n", $doc->getText());

        $this->assertTrue($doc->undo());
        $this->assertSame("foo();\nfoo();\nfoo();\n", $doc->getText(), 'one undo reverts the whole rename');
        $this->assertFalse($doc->undo(), 'the rename was a single undo step');
    }

    public function testEditsApplyBottomUpSoEarlierOffsetsStayValid(): void
    {
        $doc = new Document("0123456789\n");
        // Deliberately unsorted, and length-changing: applying top-down would
        // shift the second edit's byte offsets before it runs.
        $edits = [
            self::edit(['start' => ['line' => 0, 'character' => 1], 'end' => ['line' => 0, 'character' => 3]], 'AAAA'), // "12" -> "AAAA" (+2)
            self::edit(['start' => ['line' => 0, 'character' => 6], 'end' => ['line' => 0, 'character' => 8]], 'B'), // "67" -> "B" (-1)
        ];

        $this->workspaceEditor()->applyTextEdits($doc, $edits);

        $this->assertSame("0AAAA345B89\n", $doc->getText(), 'the rightmost edit lands first so the leftmost edit does not shift its offsets');
    }

    public function testMissingRangePositionsDefaultToLineAndCharacterZero(): void
    {
        $doc = new Document("0123456789\n");
        $edits = [
            // Explicit, mid-line: nothing to default.
            self::edit(['start' => ['line' => 0, 'character' => 5], 'end' => ['line' => 0, 'character' => 5]], 'X'),
            // Both start keys entirely absent: must default to (0, 0) — the very
            // start of the line — and therefore sort BEFORE the edit above.
            self::edit(['start' => [], 'end' => ['line' => 0, 'character' => 0]], 'Y'),
            // 'line' present, 'character' absent on both ends: must default to
            // column 0, landing at the start of the (only) line too.
            self::edit(['start' => ['line' => 0], 'end' => ['line' => 0]], 'Z'),
        ];

        $this->workspaceEditor()->applyTextEdits($doc, $edits);

        $this->assertSame("ZY01234X56789\n", $doc->getText());
    }

    public function testMissingLineOnAMultiLineDocumentTargetsLineZeroNotLineOne(): void
    {
        $doc = new Document("AAAA\nBBBB\nCCCC\n");
        // 'line' entirely absent on both start and end: a wrong default of 1
        // (instead of 0) would edit the second line, not the first.
        $edits = [self::edit(['start' => ['character' => 0], 'end' => ['character' => 4]], 'X')];

        $this->workspaceEditor()->applyTextEdits($doc, $edits);

        $this->assertSame("X\nBBBB\nCCCC\n", $doc->getText());
    }

    public function testMissingCharacterOnAMultiCharLineTargetsColumnZeroNotOne(): void
    {
        $doc = new Document("0123456789\n");
        // 'character' entirely absent on start: a wrong default of 1 (instead
        // of 0) would insert after the leading digit instead of before it.
        $edits = [self::edit(['start' => ['line' => 0], 'end' => ['line' => 0, 'character' => 0]], 'X')];

        $this->workspaceEditor()->applyTextEdits($doc, $edits);

        $this->assertSame("X0123456789\n", $doc->getText());
    }

    public function testPureInsertionDoesNotDeleteTheFollowingCharacter(): void
    {
        $doc = new Document("0123456789\n");
        $edits = [self::edit(['start' => ['line' => 0, 'character' => 3], 'end' => ['line' => 0, 'character' => 3]], 'X')];

        $this->workspaceEditor()->applyTextEdits($doc, $edits);

        $this->assertSame("012X3456789\n", $doc->getText(), 'a zero-width range only inserts — it must not delete the character at the cursor');
    }

    public function testReplacementDeletesTheRangeBeforeInserting(): void
    {
        $doc = new Document("0123456789\n");
        $edits = [self::edit(['start' => ['line' => 0, 'character' => 3], 'end' => ['line' => 0, 'character' => 6]], 'X')];

        $this->workspaceEditor()->applyTextEdits($doc, $edits);

        $this->assertSame("012X6789\n", $doc->getText(), 'a non-empty range is replaced, not just inserted into');
    }

    public function testChangesMapEditsAnOpenBufferInPlace(): void
    {
        $we = $this->workspaceEditor();
        $buffer = $this->buffers->open('/p/a.php', "0123456789\n");

        $outcome = $we->applyWorkspaceEdit([
            'changes' => [
                Uri::fromPath('/p/a.php') => [self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]], 'X')],
            ],
        ]);

        $this->assertSame(1, $outcome->touched);
        $this->assertSame("X123456789\n", $buffer->document->getText());
    }

    /**
     * The spec's rule: a server that sends BOTH sends the same edit twice, once
     * per shape, for clients that only understand one. Merging them applied it
     * twice — `A12345678B` here, where only the `B` was ever meant.
     */
    public function testDocumentChangesWinOutrightOverChangesForTheSameUri(): void
    {
        $we = $this->workspaceEditor();
        $buffer = $this->buffers->open('/p/a.php', "0123456789\n");
        $uri = Uri::fromPath('/p/a.php');

        $outcome = $we->applyWorkspaceEdit([
            'changes' => [$uri => [self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]], 'A')]],
            'documentChanges' => [
                ['textDocument' => ['uri' => $uri], 'edits' => [self::edit(['start' => ['line' => 0, 'character' => 9], 'end' => ['line' => 0, 'character' => 10]], 'B')]],
            ],
        ]);

        $this->assertSame(1, $outcome->touched);
        $this->assertSame("012345678B\n", $buffer->document->getText(), 'the legacy changes map was ignored, not merged');
    }

    public function testMalformedDocumentChangesEntriesAreSkippedWithoutAbortingTheRest(): void
    {
        $we = $this->workspaceEditor();
        $a = $this->buffers->open('/p/a.php', "0123456789\n");
        $b = $this->buffers->open('/p/b.php', "0123456789\n");
        $uriA = Uri::fromPath('/p/a.php');
        $uriB = Uri::fromPath('/p/b.php');
        $validEdit = [self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]], 'X')];

        $outcome = $we->applyWorkspaceEdit([
            'documentChanges' => [
                ['edits' => $validEdit], // missing textDocument/uri entirely
                ['textDocument' => ['uri' => $uriA]], // missing edits entirely
                ['textDocument' => ['uri' => null], 'edits' => $validEdit], // uri explicitly null
                ['textDocument' => ['uri' => $uriB], 'edits' => $validEdit], // valid, placed LAST
            ],
        ]);

        $this->assertSame(1, $outcome->touched, 'only the one well-formed entry, at the end, was applied');
        $this->assertSame('X123456789'."\n", $b->document->getText());
        $this->assertSame("0123456789\n", $a->document->getText(), 'a.php was never a valid target here');
    }

    public function testByUriSkipsAnEmptyOrUnresolvableTargetWithoutAbortingTheRest(): void
    {
        $we = $this->workspaceEditor();
        $b = $this->buffers->open('/p/b.php', "0123456789\n");
        $validEdit = [self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]], 'X')];

        $outcome = $we->applyWorkspaceEdit([
            'changes' => [
                'untitled:scratch' => $validEdit, // Uri::toPath rejects a non-file scheme → null target
                Uri::fromPath('/p/empty.php') => [], // resolvable target, but nothing to apply
                Uri::fromPath('/p/b.php') => $validEdit, // valid, placed LAST
            ],
        ]);

        $this->assertSame(1, $outcome->touched);
        $this->assertSame('X123456789'."\n", $b->document->getText());
    }

    public function testInvalidatesTheEditorOnlyWhenTheEditedBufferIsActive(): void
    {
        $we = $this->workspaceEditor();
        $active = $this->buffers->open('/p/active.php', "0123456789\n");
        $inactive = $this->buffers->open('/p/inactive.php', "0123456789\n");
        $this->buffers->setActive('/p/active.php');
        $edit = [self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]], 'X')];

        $revisionBefore = Probe::get($this->editor, 'renderRevision');
        $we->applyWorkspaceEdit(['changes' => [Uri::fromPath('/p/inactive.php') => $edit]]);
        $this->assertSame($revisionBefore, Probe::get($this->editor, 'renderRevision'), 'editing a buffer that is not active does not invalidate the editor');

        $we->applyWorkspaceEdit(['changes' => [Uri::fromPath('/p/active.php') => $edit]]);
        $this->assertGreaterThan($revisionBefore, Probe::get($this->editor, 'renderRevision'), 'editing the active buffer invalidates the editor');

        $this->assertSame("X123456789\n", $active->document->getText());
        $this->assertSame("X123456789\n", $inactive->document->getText());
    }

    public function testClosedWritableFileIsEditedOnDisk(): void
    {
        $project = new TempProject(['a.php' => "0123456789\n"]);
        $we = $this->workspaceEditor();
        $edit = [self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]], 'X')];

        $outcome = $we->applyWorkspaceEdit(['changes' => [Uri::fromPath($project->path('a.php')) => $edit]]);

        $this->assertSame(1, $outcome->touched);
        $this->assertSame("X123456789\n", \file_get_contents($project->path('a.php')));
    }

    /**
     * A file rewritten on disk announces what it REPLACED.
     *
     * This is the only moment the previous content exists anywhere: the file
     * has no buffer, so no undo stack and no `BufferSaved`, and a twenty-file
     * rename left eighteen files changed with no recovery at all. The payload
     * is the OLD text on purpose — the new content is already on disk.
     */
    public function testAFileRewrittenOnDiskAnnouncesWhatItReplaced(): void
    {
        $project = new TempProject(['a.php' => "0123456789\n"]);
        $seen = [];
        $events = new EventDispatcher();
        $events->addListener(FileRewritten::class, static function (FileRewritten $e) use (&$seen): void {
            $seen[] = [$e->path, $e->previousText];
        });
        $we = $this->workspaceEditor($events);

        $we->applyWorkspaceEdit([
            'changes' => [
                Uri::fromPath($project->path('a.php')) => [self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]], 'X')],
            ],
        ]);

        $this->assertSame([[\realpath($project->path('a.php')), "0123456789\n"]], $seen);
        $this->assertSame("X123456789\n", \file_get_contents($project->path('a.php')), 'and the new text is on disk');
    }

    /**
     * An OPEN buffer does not announce it: it has its own undo stack, and its
     * save will snapshot through the usual path. Announcing both would put two
     * versions in local history for one change.
     */
    public function testAnOpenBufferIsNotAnnouncedAsRewritten(): void
    {
        $seen = 0;
        $events = new EventDispatcher();
        $events->addListener(FileRewritten::class, static function () use (&$seen): void {
            ++$seen;
        });
        $we = $this->workspaceEditor($events);
        $this->buffers->open('/p/a.php', "0123456789\n");

        $we->applyWorkspaceEdit([
            'changes' => [
                Uri::fromPath('/p/a.php') => [self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]], 'X')],
            ],
        ]);

        $this->assertSame(0, $seen);
    }

    /** A write that FAILED replaced nothing, so it announces nothing. */
    public function testAnUnwritableFileAnnouncesNothing(): void
    {
        $project = new TempProject(['a.php' => "0123456789\n"]);
        $path = $project->path('a.php');
        \chmod($path, permissions: 0o444);
        $seen = 0;
        $events = new EventDispatcher();
        $events->addListener(FileRewritten::class, static function () use (&$seen): void {
            ++$seen;
        });
        $we = $this->workspaceEditor($events);

        try {
            $we->applyWorkspaceEdit([
                'changes' => [
                    Uri::fromPath($path) => [self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]], 'X')],
                ],
            ]);
        } finally {
            \chmod($path, permissions: 0o644);
        }

        $this->assertSame(0, $seen);
    }

    public function testClosedUnwritableFileIsSkipped(): void
    {
        $project = new TempProject(['a.php' => "0123456789\n"]);
        $path = $project->path('a.php');
        \chmod($path, permissions: 0o444);
        $we = $this->workspaceEditor();
        $edit = [self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]], 'X')];

        try {
            $outcome = $we->applyWorkspaceEdit(['changes' => [Uri::fromPath($path) => $edit]]);
        } finally {
            \chmod($path, permissions: 0o644);
        }

        $this->assertSame(0, $outcome->touched, 'an unwritable file is not touched');
        $this->assertSame("0123456789\n", \file_get_contents($path), 'and its content is left alone');
    }

    /**
     * Overlapping edits skip their file and NAME it, whether that file is open
     * or on disk.
     *
     * Both paths, because they are separate code: an open buffer is edited in
     * place and a closed file is read, edited and written back, and a refusal
     * wired into one of them only would corrupt the other. And named rather
     * than silently dropped, which is the rule the whole outcome type exists
     * for.
     */
    public function testOverlappingEditsSkipAnOpenBufferAndSayWhich(): void
    {
        $we = $this->workspaceEditor();
        $buffer = $this->buffers->open('/p/a.php', "0123456789\n");

        $outcome = $we->applyWorkspaceEdit([
            'changes' => [
                Uri::fromPath('/p/a.php') => [
                    self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 5]], 'X'),
                    self::edit(['start' => ['line' => 0, 'character' => 2], 'end' => ['line' => 0, 'character' => 7]], 'Y'),
                ],
            ],
        ]);

        $this->assertSame(0, $outcome->touched);
        $this->assertSame(['/p/a.php'], $outcome->skipped, 'named, not silently dropped');
        $this->assertSame("0123456789\n", $buffer->document->getText(), 'untouched');
    }

    public function testOverlappingEditsSkipAClosedFileOnDisk(): void
    {
        $project = new TempProject(['a.php' => "0123456789\n"]);
        $we = $this->workspaceEditor();

        $outcome = $we->applyWorkspaceEdit([
            'changes' => [
                Uri::fromPath($project->path('a.php')) => [
                    self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 5]], 'X'),
                    self::edit(['start' => ['line' => 0, 'character' => 2], 'end' => ['line' => 0, 'character' => 7]], 'Y'),
                ],
            ],
        ]);

        $this->assertSame(0, $outcome->touched);
        // realpath'd: the URI is canonicalised on the way in, and on macOS
        // the temp dir is a symlink.
        $this->assertSame([\realpath($project->path('a.php'))], $outcome->skipped);
        $this->assertSame("0123456789\n", \file_get_contents($project->path('a.php')), 'nothing was written');
    }

    /**
     * And ONE bad file does not cost the others. This is the tolerance rule the
     * class docblock argues for, applied to a new reason for skipping: refusing
     * a 20-file rename because the 20th is malformed would be worse than doing
     * the 19.
     */
    public function testAFileWithOverlappingEditsDoesNotStopTheRest(): void
    {
        $we = $this->workspaceEditor();
        $good = $this->buffers->open('/p/good.php', "0123456789\n");
        $this->buffers->open('/p/bad.php', "0123456789\n");

        $outcome = $we->applyWorkspaceEdit([
            'changes' => [
                Uri::fromPath('/p/bad.php') => [
                    self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 5]], 'X'),
                    self::edit(['start' => ['line' => 0, 'character' => 2], 'end' => ['line' => 0, 'character' => 7]], 'Y'),
                ],
                Uri::fromPath('/p/good.php') => [
                    self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]], 'Z'),
                ],
            ],
        ]);

        $this->assertSame(1, $outcome->touched);
        $this->assertSame(['/p/bad.php'], $outcome->skipped);
        $this->assertSame("Z123456789\n", $good->document->getText());
    }

    public function testBufferLookupFallsBackToARealpathMatch(): void
    {
        $project = new TempProject(['a.php' => "0123456789\n"]);
        $canonical = \realpath($project->dir).'/a.php';
        $this->assertNotSame($project->dir, \realpath($project->dir), 'precondition: the temp dir itself has a non-canonical alias to resolve through (e.g. /var vs /private/var)');
        // The buffer is opened under the CANONICAL path (as our own editor
        // always resolves it), but the incoming edit's URI — as a real LSP
        // server would send it — carries the non-canonical alias raw, with no
        // realpath() applied. Only the fallback in WorkspaceEditor unifies them.
        $we = $this->workspaceEditor();
        $buffer = $this->buffers->open($canonical, "0123456789\n");
        $edit = [self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]], 'X')];
        $nonCanonicalUri = 'file://'.$project->dir.'/a.php';

        $outcome = $we->applyWorkspaceEdit(['changes' => [$nonCanonicalUri => $edit]]);

        $this->assertSame(1, $outcome->touched);
        $this->assertSame("X123456789\n", $buffer->document->getText(), 'the realpath-equivalent buffer was found and edited in place');
        $this->assertSame("0123456789\n", (string) \file_get_contents($canonical), 'an open buffer is never also written straight to disk');
    }

    /**
     * The bug this class was reworked for: a server-side "extract to new class"
     * sends a CreateFile beside the edits that reference it. Dropping the
     * operation left the references rewritten and the file absent — a
     * half-applied refactoring, reported as a success.
     */
    public function testACreateOperationMakesTheFileTheFollowingEditsTargetIt(): void
    {
        $project = new TempProject(['a.php' => "<?php\n"]);
        $we = $this->workspaceEditor();
        $new = $project->path('Extracted.php');

        $outcome = $we->applyWorkspaceEdit([
            'documentChanges' => [
                ['kind' => 'create', 'uri' => Uri::fromPath($new)],
                [
                    'textDocument' => ['uri' => Uri::fromPath($new)],
                    'edits' => [
                        self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]], "<?php\nclass Extracted {}\n"),
                    ],
                ],
            ],
        ]);

        $this->assertFalse($outcome->isRefused());
        $this->assertFileExists($new);
        $this->assertSame("<?php\nclass Extracted {}\n", (string) \file_get_contents($new));
        $this->assertSame(1, $outcome->touched);
    }

    /**
     * A refactoring that lands on disk announces that the working tree moved.
     *
     * The same silence {@see \Porta\Tree\TreeOperations::withFs()} had: the
     * file tree's version-control marks come from a whole-repository `git
     * status`, refreshed only when something says the tree moved. A server-side
     * rename or create moved it as surely as `git add` does, so without this
     * the moved file sat unmarked until an unrelated save happened to trigger
     * a refresh.
     *
     * Once for the whole edit, not once per operation: a refactoring may rename
     * twenty files, and twenty `git status` runs answer the same question.
     */
    public function testAnAppliedEditAnnouncesThatTheWorkingTreeMoved(): void
    {
        $project = new TempProject(['old.php' => "<?php\n"]);
        $events = new EventDispatcher();
        $moved = 0;
        $events->addListener(WorkingTreeChanged::class, static function () use (&$moved): void {
            ++$moved;
        });
        $we = $this->workspaceEditor($events);

        $outcome = $we->applyWorkspaceEdit([
            'documentChanges' => [
                ['kind' => 'rename', 'oldUri' => Uri::fromPath($project->path('old.php')), 'newUri' => Uri::fromPath($project->path('new.php'))],
                ['kind' => 'create', 'uri' => Uri::fromPath($project->path('made.php'))],
            ],
        ]);

        $this->assertFalse($outcome->isRefused());
        $this->assertSame(1, $moved, 'two operations, one announcement');
    }

    /**
     * A REFUSED edit changed nothing, and says nothing.
     *
     * The one case where silence is right: `refuseUnhonourable()` returns
     * before any operation runs, so there is no half-applied state for git to
     * be asked about. This is what stops the announcement from degrading into
     * "applyWorkspaceEdit was called".
     */
    public function testARefusedEditAnnouncesNothing(): void
    {
        $events = new EventDispatcher();
        $moved = 0;
        $events->addListener(WorkingTreeChanged::class, static function () use (&$moved): void {
            ++$moved;
        });
        $we = $this->workspaceEditor($events);

        $outcome = $we->applyWorkspaceEdit([
            'documentChanges' => [
                ['kind' => 'rename', 'oldUri' => 'file:///nope/missing.php', 'newUri' => 'file:///nope/other.php'],
            ],
        ]);

        $this->assertTrue($outcome->isRefused());
        $this->assertSame(0, $moved);
    }

    public function testARenameOperationMovesTheFileAndAsksForTheBufferToFollow(): void
    {
        $project = new TempProject(['old.php' => "<?php\n"]);
        $events = new EventDispatcher();
        $seen = [];
        $events->addListener(BufferRepointRequested::class, static function (BufferRepointRequested $e) use (&$seen): void {
            $seen[] = [$e->from, $e->to];
        });
        $renamed = [];
        $events->addListener(FileRenamed::class, static function (FileRenamed $e) use (&$renamed): void {
            $renamed[] = [$e->from, $e->to];
        });
        $we = $this->workspaceEditor($events);
        $old = $project->path('old.php');
        $new = $project->path('new.php');

        $outcome = $we->applyWorkspaceEdit([
            'documentChanges' => [
                ['kind' => 'rename', 'oldUri' => Uri::fromPath($old), 'newUri' => Uri::fromPath($new)],
            ],
        ]);

        $this->assertFalse($outcome->isRefused());
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($new);
        $this->assertCount(1, $seen, 'a buffer showing the old path is told to follow it');
        // The paths travel as the URI named them (canonicalised); what matters
        // is that the pair points from the old file to the new one.
        $this->assertStringEndsWith('/old.php', $seen[0][0]);
        $this->assertStringEndsWith('/new.php', $seen[0][1]);

        // And the FACT, so everything path-keyed follows too — bookmarks and
        // breakpoints, which a refactoring's rename would otherwise strand.
        // Its contract is CANONICAL paths, and by now the old file is gone, so
        // the dispatcher must have resolved it through its directory.
        $this->assertCount(1, $renamed);
        $this->assertSame(\realpath($project->dir).'/old.php', $renamed[0][0], 'the old path, canonical despite no longer existing');
        $this->assertSame(\realpath($new), $renamed[0][1]);
    }

    /**
     * A SERVER's URI, not one this client built: `Uri::fromPath()` resolves as
     * it encodes, so a locally-built URI hides this case entirely. A server
     * spells paths from its own view of the workspace root, and on macOS a
     * temp root is a symlink — so `/var/…` arrives where buffers are keyed
     * `/private/var/…`. The old file is gone by the time the fact is built, so
     * only its DIRECTORY can be resolved.
     */
    public function testARenameFactCanonicalisesAServerSpelledOldPath(): void
    {
        $project = new TempProject(['old.php' => "<?php\n"]);
        $unresolved = $project->path('old.php');
        $this->assertNotSame(\realpath($project->dir), $project->dir, 'the fixture root must be a symlink for this to test anything');

        $events = new EventDispatcher();
        $renamed = [];
        $events->addListener(FileRenamed::class, static function (FileRenamed $e) use (&$renamed): void {
            $renamed[] = [$e->from, $e->to];
        });

        $this->workspaceEditor($events)->applyWorkspaceEdit([
            'documentChanges' => [[
                'kind' => 'rename',
                // Hand-built, deliberately UNRESOLVED — what a server sends.
                'oldUri' => 'file://'.$unresolved,
                'newUri' => 'file://'.$project->path('new.php'),
            ]],
        ]);

        $this->assertCount(1, $renamed);
        $this->assertSame(\realpath($project->dir).'/old.php', $renamed[0][0], 'canonical despite the file already being gone');
        $this->assertSame(\realpath($project->path('new.php')), $renamed[0][1]);
    }

    public function testADeleteOperationRemovesTheFile(): void
    {
        $project = new TempProject(['gone.php' => "<?php\n"]);
        $we = $this->workspaceEditor();
        $path = $project->path('gone.php');

        $outcome = $we->applyWorkspaceEdit([
            'documentChanges' => [
                ['kind' => 'delete', 'uri' => Uri::fromPath($path)],
            ],
        ]);

        $this->assertFalse($outcome->isRefused());
        $this->assertFileDoesNotExist($path);
    }

    /**
     * The delete fact is canonical, and resolved BEFORE the file goes.
     *
     * Same symlinked-root trap as the rename above: a server spells the path
     * its own way, while every path-keyed store is keyed the resolved way. And
     * the ordering is the whole test — resolve after `remove()` and there is
     * nothing left to resolve, so the fact would carry the server's spelling
     * and every listener would look up a key it does not have.
     */
    public function testADeleteFactCanonicalisesAServerSpelledPathBeforeTheFileGoes(): void
    {
        $project = new TempProject(['gone.php' => "<?php\n"]);
        $unresolved = $project->path('gone.php');
        $this->assertNotSame(\realpath($project->dir), $project->dir, 'the fixture root must be a symlink for this to test anything');
        $expected = \realpath($unresolved);

        $events = new EventDispatcher();
        $deleted = [];
        $events->addListener(FileDeleted::class, static function (FileDeleted $e) use (&$deleted): void {
            $deleted[] = $e->path;
        });

        $this->workspaceEditor($events)->applyWorkspaceEdit([
            'documentChanges' => [['kind' => 'delete', 'uri' => 'file://'.$unresolved]],
        ]);

        $this->assertSame([$expected], $deleted);
        $this->assertFileDoesNotExist($unresolved);
    }

    /**
     * All-or-nothing: an operation that cannot be honoured refuses the WHOLE
     * edit, because half a refactoring is worse than none. The edit below would
     * otherwise rewrite the reference and never create the file.
     */
    public function testAnUnhonourableOperationRefusesTheEntireEditAndWritesNothing(): void
    {
        $project = new TempProject(['a.php' => "<?php\nold\n", 'taken.php' => "<?php\n"]);
        $we = $this->workspaceEditor();

        $outcome = $we->applyWorkspaceEdit([
            'documentChanges' => [
                // No overwrite, no ignoreIfExists, and the file is already there.
                ['kind' => 'create', 'uri' => Uri::fromPath($project->path('taken.php'))],
                [
                    'textDocument' => ['uri' => Uri::fromPath($project->path('a.php'))],
                    'edits' => [
                        self::edit(['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 3]], 'new'),
                    ],
                ],
            ],
        ]);

        $this->assertTrue($outcome->isRefused());
        $this->assertStringContainsString('taken.php', (string) $outcome->refused);
        $this->assertSame(0, $outcome->touched);
        $this->assertSame("<?php\nold\n", (string) \file_get_contents($project->path('a.php')), 'the edit beside it was not applied either');
    }

    /** @return \Generator<string, array{list<array<string, mixed>>, string}> */
    public static function unhonourableOperations(): \Generator
    {
        yield 'renaming what is not there' => [[['kind' => 'rename', 'oldUri' => 'file:///nope/missing.php', 'newUri' => 'file:///nope/other.php']], 'missing.php'];
        yield 'deleting what is not there' => [[['kind' => 'delete', 'uri' => 'file:///nope/missing.php']], 'missing.php'];
        yield 'an operation we do not know' => [[['kind' => 'teleport', 'uri' => 'file:///nope/a.php']], 'teleport'];
        // A plain text edit carries no `kind`, so the validation loop skips it.
        // Skipping is right; STOPPING there is not, and a `break` in its place
        // left every case above green — each of them leads with the resource
        // operation. A server sending edits before operations, which the spec
        // allows and several do, would have had the operation applied unchecked.
        yield 'an operation behind a text edit' => [
            [
                ['textDocument' => ['uri' => 'file:///nope/a.php'], 'edits' => []],
                ['kind' => 'delete', 'uri' => 'file:///nope/missing.php'],
            ],
            'missing.php',
        ];
    }

    /** @param list<array<string, mixed>> $changes */
    #[DataProvider('unhonourableOperations')]
    public function testEachUnhonourableOperationNamesItselfInTheRefusal(array $changes, string $needle): void
    {
        $outcome = $this->workspaceEditor()->applyWorkspaceEdit(['documentChanges' => $changes]);

        $this->assertTrue($outcome->isRefused());
        $this->assertStringContainsString($needle, (string) $outcome->refused);
    }

    /** The options are what make a repeat run of the same refactoring survivable. */
    public function testIgnoreIfExistsAndOverwriteMakeACreateHonourable(): void
    {
        $project = new TempProject(['there.php' => "<?php\nkeep\n"]);
        $uri = Uri::fromPath($project->path('there.php'));

        $ignored = $this->workspaceEditor()->applyWorkspaceEdit([
            'documentChanges' => [
                ['kind' => 'create', 'uri' => $uri, 'options' => ['ignoreIfExists' => true]],
            ],
        ]);
        $this->assertFalse($ignored->isRefused());
        $this->assertSame("<?php\nkeep\n", (string) \file_get_contents($project->path('there.php')), 'ignoreIfExists leaves the file alone');

        $overwritten = $this->workspaceEditor()->applyWorkspaceEdit([
            'documentChanges' => [
                ['kind' => 'create', 'uri' => $uri, 'options' => ['overwrite' => true]],
            ],
        ]);
        $this->assertFalse($overwritten->isRefused());
    }

    /** A file the edit named but could not write is reported, not dropped from the count. */
    public function testAnUnwritableFileIsNamedInTheOutcome(): void
    {
        $outcome = $this->workspaceEditor()->applyWorkspaceEdit([
            'changes' => ['file:///does/not/exist.php' => [
                self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]], 'x'),
            ]],
        ]);

        $this->assertSame(0, $outcome->touched);
        $this->assertSame(['/does/not/exist.php'], $outcome->skipped);
    }

    /** @return \Generator<string, array{list<array<string, mixed>>}> */
    public static function operationsWithNoUsablePath(): \Generator
    {
        yield 'create' => [[['kind' => 'create', 'uri' => 'untitled:scratch']]];
        yield 'rename source' => [[['kind' => 'rename', 'oldUri' => 'untitled:scratch', 'newUri' => 'file:///tmp/x.php']]];
        yield 'delete' => [[['kind' => 'delete', 'uri' => 'untitled:scratch']]];
    }

    /**
     * A URI we cannot turn into a path is refused rather than skipped: skipping
     * it would apply the edits around it, which is the half-application this
     * whole pass exists to stop.
     *
     * @param list<array<string, mixed>> $changes
     */
    #[DataProvider('operationsWithNoUsablePath')]
    public function testAnOperationWithNoUsablePathRefusesTheEdit(array $changes): void
    {
        $outcome = $this->workspaceEditor()->applyWorkspaceEdit(['documentChanges' => $changes]);

        $this->assertTrue($outcome->isRefused());
        $this->assertStringContainsString('no usable path', (string) $outcome->refused);
    }

    public function testARenameOntoAnExistingFileIsRefusedWithoutOverwrite(): void
    {
        $project = new TempProject(['from.php' => "a\n", 'onto.php' => "b\n"]);

        $outcome = $this->workspaceEditor()->applyWorkspaceEdit([
            'documentChanges' => [
                ['kind' => 'rename', 'oldUri' => Uri::fromPath($project->path('from.php')), 'newUri' => Uri::fromPath($project->path('onto.php'))],
            ],
        ]);

        $this->assertTrue($outcome->isRefused());
        $this->assertStringContainsString('onto.php', (string) $outcome->refused);
        $this->assertSame("b\n", (string) \file_get_contents($project->path('onto.php')), 'and it was not clobbered');
    }

    /** A deleted file's buffer goes with it — nothing should be left showing a file that is gone. */
    public function testDeletingAFileClosesTheBufferShowingIt(): void
    {
        $project = new TempProject(['gone.php' => "<?php\n"]);
        $we = $this->workspaceEditor();
        $path = (string) \realpath($project->path('gone.php'));
        $this->buffers->open($path, "<?php\n");

        $we->applyWorkspaceEdit(['documentChanges' => [['kind' => 'delete', 'uri' => Uri::fromPath($path)]]]);

        $this->assertFalse($this->buffers->has($path));
    }

    /**
     * The writability check passed and the write still failed — a full disk, a
     * read-only mount, a race. Uncaught, this escaped the LSP response callback
     * into the event loop with earlier files already written.
     */
    public function testAWriteThatFailsAfterTheCheckIsReportedRatherThanThrown(): void
    {
        $project = new TempProject(['a.php' => "abc\n"]);
        $throwing = new class extends Filesystem {
            #[\Override]
            public function dumpFile(string $filename, $content): void
            {
                throw new IOException('disk full');
            }
        };
        $we = new WorkspaceEditor(
            new BufferManager(),
            new CodeEditorWidget(),
            new LspSessionPool(\sys_get_temp_dir(), new FixedActiveFile(), new Translator('en')),
            new EventDispatcher(),
            new RefactoringUndo(new BufferManager(), new EventDispatcher()),
            $throwing,
        );

        $outcome = $we->applyWorkspaceEdit([
            'changes' => [
                Uri::fromPath($project->path('a.php')) => [
                    self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]], 'X'),
                ],
            ],
        ]);

        $this->assertSame(0, $outcome->touched);
        $this->assertCount(1, $outcome->skipped, 'reported like any other unwritable file');
    }

    /**
     * A rename whose options ALLOW overwriting is permitted by the refusal
     * pass, so the apply pass has to actually overwrite — a rename that fails
     * on the existing target would leave the edit half-applied, which is the
     * one outcome this class exists to prevent.
     */
    public function testARenameDeclaringOverwriteReplacesTheFileAlreadyThere(): void
    {
        $project = new TempProject(['from.php' => "<?php // the survivor\n", 'onto.php' => "<?php // replaced\n"]);

        $outcome = $this->workspaceEditor()->applyWorkspaceEdit([
            'documentChanges' => [[
                'kind' => 'rename',
                'oldUri' => Uri::fromPath($project->path('from.php')),
                'newUri' => Uri::fromPath($project->path('onto.php')),
                'options' => ['overwrite' => true],
            ]],
        ]);

        $this->assertFalse($outcome->isRefused(), (string) $outcome->refused);
        $this->assertFileDoesNotExist($project->path('from.php'));
        $this->assertStringContainsString('the survivor', (string) \file_get_contents($project->path('onto.php')));
    }

    /**
     * The refusal pass walks a SIMULATED view of the tree, because the answer
     * for a later operation depends on the earlier ones. These four sequences
     * are the whole reason it is a simulation rather than four independent
     * file_exists() calls — each is honourable (or not) only in order.
     *
     * @param list<array<string, mixed>> $changes
     */
    #[DataProvider('operationSequences')]
    public function testTheRefusalPassSimulatesEarlierOperations(array $changes, bool $honourable, string $because): void
    {
        $project = new TempProject(['there.php' => "<?php\n"]);
        $resolve = static fn (array $c): array => \array_map(
            static fn (mixed $v): mixed => \is_string($v) && \str_starts_with($v, 'file://')
                ? 'file://'.$project->path(\substr($v, offset: 7))
                : $v,
            $c,
        );

        $outcome = $this->workspaceEditor()->applyWorkspaceEdit(['documentChanges' => \array_map($resolve, $changes)]);

        $this->assertSame($honourable, !$outcome->isRefused(), $because.' — refusal said: '.(string) $outcome->refused);
    }

    /** @return \Generator<string, array{list<array<string, mixed>>, bool, string}> */
    public static function operationSequences(): \Generator
    {
        yield 'create then rename what was created' => [
            [
                ['kind' => 'create', 'uri' => 'file://made.php'],
                ['kind' => 'rename', 'oldUri' => 'file://made.php', 'newUri' => 'file://moved.php'],
            ],
            true,
            'the create makes the rename honourable',
        ];

        yield 'rename then create the vacated name' => [
            [
                ['kind' => 'rename', 'oldUri' => 'file://there.php', 'newUri' => 'file://gone-to.php'],
                ['kind' => 'create', 'uri' => 'file://there.php'],
            ],
            true,
            'the rename frees the old name for the create',
        ];

        yield 'delete then rename what was deleted' => [
            [
                ['kind' => 'delete', 'uri' => 'file://there.php'],
                ['kind' => 'rename', 'oldUri' => 'file://there.php', 'newUri' => 'file://nowhere.php'],
            ],
            false,
            'the delete leaves nothing to rename',
        ];

        yield 'create onto the name a rename just took' => [
            [
                ['kind' => 'rename', 'oldUri' => 'file://there.php', 'newUri' => 'file://taken.php'],
                ['kind' => 'create', 'uri' => 'file://taken.php'],
            ],
            false,
            'the rename occupied that name',
        ];

        yield 'delete the name a rename just took' => [
            [
                ['kind' => 'rename', 'oldUri' => 'file://there.php', 'newUri' => 'file://taken.php'],
                ['kind' => 'delete', 'uri' => 'file://taken.php'],
            ],
            true,
            'the rename put a file there to delete',
        ];

        yield 'rename the same file twice' => [
            [
                ['kind' => 'rename', 'oldUri' => 'file://there.php', 'newUri' => 'file://first.php'],
                ['kind' => 'rename', 'oldUri' => 'file://there.php', 'newUri' => 'file://second.php'],
            ],
            false,
            'the first rename moved it away',
        ];
    }

    /**
     * Deleting a file the user has unsaved edits in takes those edits with it,
     * and there is no autosave to have caught them. It is still applied —
     * refusing would leave the workspace edit HALF APPLIED, which this codebase
     * treats as the worse outcome — but it may not happen in silence.
     */
    public function testDeletingAFileWithUnsavedEditsSaysSo(): void
    {
        $project = new TempProject(['gone.php' => "<?php\n"]);
        $we = $this->workspaceEditor();
        $path = (string) \realpath($project->path('gone.php'));
        $this->buffers->open($path, "<?php\n")->document->insert('// edited');

        $we->applyWorkspaceEdit(['documentChanges' => [['kind' => 'delete', 'uri' => Uri::fromPath($path)]]]);

        $this->assertFileDoesNotExist($path, 'the refactoring still applied');
        $this->assertStringContainsString('unsaved changes in it are gone', $this->flashText($this->statusBar));
    }

    /** And a CLEAN buffer deleted the same way says nothing — there was nothing to lose. */
    public function testDeletingAFileWithNoUnsavedEditsIsQuiet(): void
    {
        $project = new TempProject(['gone.php' => "<?php\n"]);
        $we = $this->workspaceEditor();
        $path = (string) \realpath($project->path('gone.php'));
        $this->buffers->open($path, "<?php\n");

        $we->applyWorkspaceEdit(['documentChanges' => [['kind' => 'delete', 'uri' => Uri::fromPath($path)]]]);

        $this->assertSame('', $this->flashText($this->statusBar));
    }
}
