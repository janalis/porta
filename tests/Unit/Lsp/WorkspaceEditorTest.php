<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\Document;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\Uri;
use Porta\Lsp\WorkspaceEditor;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;
use Porta\Widget\CodeEditorWidget;

final class WorkspaceEditorTest extends TestCase
{
    private BufferManager $buffers;
    private CodeEditorWidget $editor;

    private function workspaceEditor(): WorkspaceEditor
    {
        $this->buffers = new BufferManager();
        $this->editor = new CodeEditorWidget();

        return new WorkspaceEditor($this->buffers, $this->editor, new LspSessionPool(sys_get_temp_dir(), new FixedActiveFile(), new Translator('en')));
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

        $touched = $we->applyWorkspaceEdit([
            'changes' => [
                Uri::fromPath('/p/a.php') => [self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]], 'X')],
            ],
        ]);

        $this->assertSame(1, $touched);
        $this->assertSame("X123456789\n", $buffer->document->getText());
    }

    public function testDocumentChangesMergeWithChangesForTheSameUri(): void
    {
        $we = $this->workspaceEditor();
        $buffer = $this->buffers->open('/p/a.php', "0123456789\n");
        $uri = Uri::fromPath('/p/a.php');

        $touched = $we->applyWorkspaceEdit([
            'changes' => [$uri => [self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]], 'A')]],
            'documentChanges' => [
                ['textDocument' => ['uri' => $uri], 'edits' => [self::edit(['start' => ['line' => 0, 'character' => 9], 'end' => ['line' => 0, 'character' => 10]], 'B')]],
            ],
        ]);

        $this->assertSame(1, $touched, 'both sources target the same file — one file touched');
        $this->assertSame("A12345678B\n", $buffer->document->getText(), 'edits from both changes and documentChanges landed on the same document');
    }

    public function testMalformedDocumentChangesEntriesAreSkippedWithoutAbortingTheRest(): void
    {
        $we = $this->workspaceEditor();
        $a = $this->buffers->open('/p/a.php', "0123456789\n");
        $b = $this->buffers->open('/p/b.php', "0123456789\n");
        $uriA = Uri::fromPath('/p/a.php');
        $uriB = Uri::fromPath('/p/b.php');
        $validEdit = [self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]], 'X')];

        $touched = $we->applyWorkspaceEdit([
            'documentChanges' => [
                ['edits' => $validEdit], // missing textDocument/uri entirely
                ['textDocument' => ['uri' => $uriA]], // missing edits entirely
                ['textDocument' => ['uri' => null], 'edits' => $validEdit], // uri explicitly null
                ['textDocument' => ['uri' => $uriB], 'edits' => $validEdit], // valid, placed LAST
            ],
        ]);

        $this->assertSame(1, $touched, 'only the one well-formed entry, at the end, was applied');
        $this->assertSame('X123456789'."\n", $b->document->getText());
        $this->assertSame("0123456789\n", $a->document->getText(), 'a.php was never a valid target here');
    }

    public function testByUriSkipsAnEmptyOrUnresolvableTargetWithoutAbortingTheRest(): void
    {
        $we = $this->workspaceEditor();
        $b = $this->buffers->open('/p/b.php', "0123456789\n");
        $validEdit = [self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]], 'X')];

        $touched = $we->applyWorkspaceEdit([
            'changes' => [
                'untitled:scratch' => $validEdit, // Uri::toPath rejects a non-file scheme → null target
                Uri::fromPath('/p/empty.php') => [], // resolvable target, but nothing to apply
                Uri::fromPath('/p/b.php') => $validEdit, // valid, placed LAST
            ],
        ]);

        $this->assertSame(1, $touched);
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

        $touched = $we->applyWorkspaceEdit(['changes' => [Uri::fromPath($project->path('a.php')) => $edit]]);

        $this->assertSame(1, $touched);
        $this->assertSame("X123456789\n", file_get_contents($project->path('a.php')));
    }

    public function testClosedUnwritableFileIsSkipped(): void
    {
        $project = new TempProject(['a.php' => "0123456789\n"]);
        $path = $project->path('a.php');
        chmod($path, permissions: 0o444);
        $we = $this->workspaceEditor();
        $edit = [self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]], 'X')];

        try {
            $touched = $we->applyWorkspaceEdit(['changes' => [Uri::fromPath($path) => $edit]]);
        } finally {
            chmod($path, permissions: 0o644);
        }

        $this->assertSame(0, $touched, 'an unwritable file is not touched');
        $this->assertSame("0123456789\n", file_get_contents($path), 'and its content is left alone');
    }

    public function testBufferLookupFallsBackToARealpathMatch(): void
    {
        $project = new TempProject(['a.php' => "0123456789\n"]);
        $canonical = realpath($project->dir).'/a.php';
        $this->assertNotSame($project->dir, realpath($project->dir), 'precondition: the temp dir itself has a non-canonical alias to resolve through (e.g. /var vs /private/var)');
        // The buffer is opened under the CANONICAL path (as our own editor
        // always resolves it), but the incoming edit's URI — as a real LSP
        // server would send it — carries the non-canonical alias raw, with no
        // realpath() applied. Only the fallback in WorkspaceEditor unifies them.
        $we = $this->workspaceEditor();
        $buffer = $this->buffers->open($canonical, "0123456789\n");
        $edit = [self::edit(['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]], 'X')];
        $nonCanonicalUri = 'file://'.$project->dir.'/a.php';

        $touched = $we->applyWorkspaceEdit(['changes' => [$nonCanonicalUri => $edit]]);

        $this->assertSame(1, $touched);
        $this->assertSame("X123456789\n", $buffer->document->getText(), 'the realpath-equivalent buffer was found and edited in place');
        $this->assertSame("0123456789\n", (string) file_get_contents($canonical), 'an open buffer is never also written straight to disk');
    }
}
