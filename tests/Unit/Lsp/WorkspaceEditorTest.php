<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\Document;
use Porta\Event\BufferRepointRequested;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\Uri;
use Porta\Lsp\WorkspaceEditor;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final class WorkspaceEditorTest extends TestCase
{
    private BufferManager $buffers;
    private CodeEditorWidget $editor;

    private function workspaceEditor(?EventDispatcher $events = null): WorkspaceEditor
    {
        $this->buffers = new BufferManager();
        $this->editor = new CodeEditorWidget();

        return new WorkspaceEditor($this->buffers, $this->editor, new LspSessionPool(sys_get_temp_dir(), new FixedActiveFile(), new Translator('en')), $events ?? new EventDispatcher());
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
            $outcome = $we->applyWorkspaceEdit(['changes' => [Uri::fromPath($path) => $edit]]);
        } finally {
            chmod($path, permissions: 0o644);
        }

        $this->assertSame(0, $outcome->touched, 'an unwritable file is not touched');
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

        $outcome = $we->applyWorkspaceEdit(['changes' => [$nonCanonicalUri => $edit]]);

        $this->assertSame(1, $outcome->touched);
        $this->assertSame("X123456789\n", $buffer->document->getText(), 'the realpath-equivalent buffer was found and edited in place');
        $this->assertSame("0123456789\n", (string) file_get_contents($canonical), 'an open buffer is never also written straight to disk');
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
        $this->assertSame("<?php\nclass Extracted {}\n", (string) file_get_contents($new));
        $this->assertSame(1, $outcome->touched);
    }

    public function testARenameOperationMovesTheFileAndAsksForTheBufferToFollow(): void
    {
        $project = new TempProject(['old.php' => "<?php\n"]);
        $events = new EventDispatcher();
        $seen = [];
        $events->addListener(BufferRepointRequested::class, static function (BufferRepointRequested $e) use (&$seen): void {
            $seen[] = [$e->from, $e->to];
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
        $this->assertSame("<?php\nold\n", (string) file_get_contents($project->path('a.php')), 'the edit beside it was not applied either');
    }

    /** @return \Generator<string, array{list<array<string, mixed>>, string}> */
    public static function unhonourableOperations(): \Generator
    {
        yield 'renaming what is not there' => [[['kind' => 'rename', 'oldUri' => 'file:///nope/missing.php', 'newUri' => 'file:///nope/other.php']], 'missing.php'];
        yield 'deleting what is not there' => [[['kind' => 'delete', 'uri' => 'file:///nope/missing.php']], 'missing.php'];
        yield 'an operation we do not know' => [[['kind' => 'teleport', 'uri' => 'file:///nope/a.php']], 'teleport'];
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
        $this->assertSame("<?php\nkeep\n", (string) file_get_contents($project->path('there.php')), 'ignoreIfExists leaves the file alone');

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
        $this->assertSame("b\n", (string) file_get_contents($project->path('onto.php')), 'and it was not clobbered');
    }

    /** A deleted file's buffer goes with it — nothing should be left showing a file that is gone. */
    public function testDeletingAFileClosesTheBufferShowingIt(): void
    {
        $project = new TempProject(['gone.php' => "<?php\n"]);
        $we = $this->workspaceEditor();
        $path = (string) realpath($project->path('gone.php'));
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
            new LspSessionPool(sys_get_temp_dir(), new FixedActiveFile(), new Translator('en')),
            new EventDispatcher(),
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
}
