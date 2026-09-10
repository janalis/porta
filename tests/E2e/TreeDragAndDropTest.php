<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BookmarkActions;
use Porta\Editor\Bookmarks;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;

/**
 * Dragging a file in the tree, end to end, through the real mouse bytes.
 *
 * The press/drag/release triple is the established one — `\x1b[<0;C;RM`,
 * `\x1b[<32;C;RM`, `\x1b[<0;C;Rm`, columns 1-based — and a frame is forced
 * first so the tree has a live rect to hit-test against.
 *
 * The point of every case is the same: a drop is a MOVE, and a move is the
 * event the seven path-keyed stores already listen to. If the drop performed
 * its own rename none of them would follow.
 */
final class TreeDragAndDropTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use ScreenReads;

    /** Held so the destructor does not remove the fixture mid-test. */
    private TempProject $project;

    /**
     * Rows under the tree header, with `src/` expanded by the reveal of the
     * opened file: 1 = src/, 2 = src/A.php, 3 = top.php.
     */
    private function project(): TempProject
    {
        return $this->project = new TempProject([
            'src/A.php' => "<?php\n// a\n",
            'top.php' => "<?php\n// t\n",
        ]);
    }

    public function testDraggingAFileOntoAFolderMovesItAndTakesItsMarksAlong(): void
    {
        putenv('PATH=/nonexistent');
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('top.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->frame($tui);
                    // A bookmark on the file about to move: the assertion below
                    // is about it SURVIVING, which only happens if the drop
                    // dispatched FileRenamed rather than renaming by hand.
                    Services::resolve($ide, BookmarkActions::class)->toggle();
                    $this->assertSame(
                        [(string) realpath($project->path('top.php')) => [0]],
                        Services::resolve($ide, Bookmarks::class)->all(),
                    );
                },
                300 => function ($tui): void {
                    $rows = $this->treeRows($tui);
                    // Row 0 is the header, 1 is `▸ src/` (collapsed — a drop
                    // does not need it open), 2 is top.php.
                    $this->assertSame('top.php', trim($rows[2] ?? ''), 'the fixture rows are where the drag expects them');
                    // The tree sits below the menu bar, so a widget-relative
                    // row N is SGR row N + 2 (one for the bar, one for 1-based).
                    $tui->handleInput("\x1b[<0;3;4M"); // press top.php (relative row 2)
                    $tui->handleInput("\x1b[<32;3;3M"); // drag onto src/ (relative row 1)
                    $tui->handleInput("\x1b[<0;3;3m"); // release there
                },
                420 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->assertFileDoesNotExist($project->path('top.php'), 'the file left its old home');
                    $this->assertFileExists($project->path('src/top.php'), 'and landed in the folder it was dropped on');
                    $this->assertSame(
                        [(string) realpath($project->path('src/top.php')) => [0]],
                        Services::resolve($ide, Bookmarks::class)->all(),
                        'the bookmark followed, which only FileRenamed can do',
                    );
                },
            ],
            columns: 120,
        );
    }

    /** A press and release without motion is the click that already ran, not a drop. */
    public function testAClickInTheTreeIsStillJustAClick(): void
    {
        putenv('PATH=/nonexistent');
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function ($tui): void {
                    $this->frame($tui);
                    $tui->handleInput("\x1b[<0;3;4M"); // press top.php
                    $tui->handleInput("\x1b[<0;3;4m"); // release, no motion
                },
                320 => function ($tui) use ($project): void {
                    $this->assertFileExists($project->path('src/A.php'), 'nothing moved');
                    $this->assertFileExists($project->path('top.php'));
                },
            ],
            columns: 120,
        );
    }

    /**
     * A folder cannot go inside itself. Nothing else in the codebase guards
     * this because nothing else can reach it — the tree's Rename is confined
     * to `dirname($old)`, and a language server does not aim at its own
     * subtree.
     */
    public function testAFolderDroppedOnItsOwnChildIsRefused(): void
    {
        putenv('PATH=/nonexistent');
        $this->project = new TempProject(['src/deep/A.php' => "<?php\n", 'keep.php' => "<?php\n"]);
        $project = $this->project;

        $this->runIde(
            $project,
            $project->path('keep.php'),
            [
                200 => function ($tui): void {
                    $this->frame($tui);
                    // A real click is a press AND a release: a folder's toggle
                    // waits for the release now, so that a drag off it does not
                    // move the rows out from under the pointer.
                    $tui->handleInput("\x1b[<0;3;3M");
                    $tui->handleInput("\x1b[<0;3;3m");
                },
                300 => function ($tui): void {
                    $rows = $this->treeRows($tui);
                    $this->assertSame('▾ src/', trim($rows[1] ?? ''), 'src/ is open');
                    $this->assertSame('▸ deep/', trim($rows[2] ?? ''), 'and deep/ is its child');
                    $tui->handleInput("\x1b[<0;3;3M"); // press src/
                    $tui->handleInput("\x1b[<32;3;4M"); // drag onto its own child
                    $tui->handleInput("\x1b[<0;3;4m"); // release
                },
                420 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->assertDirectoryExists($project->path('src/deep'), 'the child is where it was');
                    $this->assertDirectoryDoesNotExist($project->path('src/deep/src'), 'and src/ did not go inside it');
                    $this->assertStringContainsString('into itself', implode(' | ', Toasts::all($ide)), 'the refusal says why');
                },
            ],
            columns: 120,
        );
    }

    /**
     * A whole FOLDER dragged into another one, which is the case with the most
     * moving parts and the one only its refusal was tested for.
     *
     * Everything under it moves, so every store keyed by the FILES has to ask
     * `ProjectPaths::moved()` rather than compare paths — the bookmark below is
     * on a file two levels inside the folder being dragged, and it following is
     * the only evidence that the subtree half of `FileRenamed`'s contract was
     * honoured rather than the folder being renamed by hand.
     */
    public function testDraggingAFolderTakesItsSubtreeAndTheOpenBufferInsideIt(): void
    {
        putenv('PATH=/nonexistent');
        $this->project = new TempProject(['pkg/A.php' => "<?php\n// a\n", 'dest/keep.php' => "<?php\n"]);
        $project = $this->project;

        $this->runIde(
            $project,
            $project->path('pkg/A.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->frame($tui);
                    Services::resolve($ide, BookmarkActions::class)->toggle();
                    $this->assertSame(
                        [(string) realpath($project->path('pkg/A.php')) => [0]],
                        Services::resolve($ide, Bookmarks::class)->all(),
                        'the mark starts on the file inside the folder',
                    );
                },
                300 => function ($tui): void {
                    $rows = $this->treeRows($tui);
                    // dest/ sorts before pkg/. Both stay COLLAPSED — the file
                    // inside pkg/ is open in the editor without its folder
                    // being expanded, which is the point: the buffer has to
                    // follow whether or not the tree is showing it.
                    $this->assertSame('▸ dest/', trim($rows[1] ?? ''), 'dest/ is row 1');
                    $this->assertSame('▸ pkg/', trim($rows[2] ?? ''), 'and pkg/ is row 2');
                    $tui->handleInput("\x1b[<0;3;4M"); // press pkg/
                    $tui->handleInput("\x1b[<32;3;3M"); // drag onto dest/
                    $tui->handleInput("\x1b[<0;3;3m"); // release
                },
                460 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->assertDirectoryDoesNotExist($project->path('pkg'), 'the folder left its old place');
                    $this->assertFileExists($project->path('dest/pkg/A.php'), 'and arrived whole');
                    $this->assertStringEndsWith(
                        'dest/pkg/A.php',
                        (string) $tui->getById('editor')->getFilePath(),
                        'the buffer that was open inside it followed',
                    );
                    $this->assertSame(
                        [(string) realpath($project->path('dest/pkg/A.php')) => [0]],
                        Services::resolve($ide, Bookmarks::class)->all(),
                        'and so did the mark, two levels down',
                    );
                },
            ],
            columns: 120,
        );
    }

    /**
     * Cancel on the collision prompt leaves BOTH files exactly where they were.
     *
     * The third answer, and the only one whose branch is an absence — the
     * closure simply falls off its two `if`s. Coverage cannot tell it apart
     * from never being chosen at all, and what it decides is whether a file is
     * overwritten, so it is worth its own case.
     */
    public function testACollisionAnsweredWithCancelMovesNothing(): void
    {
        putenv('PATH=/nonexistent');
        $this->project = new TempProject([
            'src/dup.php' => "<?php\n// the one already there\n",
            'dup.php' => "<?php\n// the one being dragged\n",
        ]);
        $project = $this->project;

        $this->runIde(
            $project,
            $project->path('src/dup.php'),
            [
                200 => function ($tui): void {
                    $this->frame($tui);
                    $tui->handleInput("\x1b[<0;3;4M"); // press the root dup.php
                    $tui->handleInput("\x1b[<32;3;3M"); // drag onto src/
                    $tui->handleInput("\x1b[<0;3;3m"); // release
                },
                320 => function ($tui, $terminal): void {
                    $screen = implode("\n", $this->screenGrid($tui, $terminal));
                    $this->assertStringContainsString('already exists', $screen, 'the prompt is up');
                    $tui->handleInput("\x1b[B"); // → Rename…
                    $tui->handleInput("\x1b[B"); // → Cancel
                    $tui->handleInput("\r");
                },
                460 => function ($tui) use ($project): void {
                    $this->assertStringContainsString(
                        'the one being dragged',
                        (string) file_get_contents($project->path('dup.php')),
                        'the dragged file stayed put, with its own contents',
                    );
                    $this->assertStringContainsString(
                        'the one already there',
                        (string) file_get_contents($project->path('src/dup.php')),
                        'and the one it would have replaced is untouched',
                    );
                },
            ],
            columns: 120,
        );
    }

    /**
     * Dropped on the editor instead of a folder: the file OPENS and nothing
     * moves. The second drop target, and the only one that touches no disk.
     */
    public function testADropOnTheEditorOpensTheFileAndMovesNothing(): void
    {
        putenv('PATH=/nonexistent');
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function ($tui): void {
                    $this->frame($tui);
                    $tui->handleInput("\x1b[<0;3;4M"); // press top.php
                    $tui->handleInput("\x1b[<32;80;10M"); // drag out over the editor
                    $tui->handleInput("\x1b[<0;80;10m"); // release there
                },
                380 => function ($tui) use ($project): void {
                    $this->assertFileExists($project->path('top.php'), 'nothing moved');
                    $this->assertStringContainsString('top.php', $this->statusText($tui), 'and it is the file on screen now');
                },
            ],
            columns: 120,
        );
    }

    /**
     * The name is taken. PhpStorm asks; so does this — refusing would make a
     * drop unrecoverable, and a move is deliberately outside RefactoringUndo.
     */
    public function testACollisionAsksAndOverwriteAnswersIt(): void
    {
        putenv('PATH=/nonexistent');
        $this->project = new TempProject([
            'src/dup.php' => "<?php\n// the one already there\n",
            'dup.php' => "<?php\n// the one being dragged\n",
        ]);
        $project = $this->project;

        $this->runIde(
            $project,
            $project->path('src/dup.php'),
            [
                200 => function ($tui): void {
                    $this->frame($tui);
                    $rows = $this->treeRows($tui);
                    $this->assertSame('dup.php', trim($rows[2] ?? ''), 'the dragged file is row 2');
                    $tui->handleInput("\x1b[<0;3;4M"); // press the root dup.php
                    $tui->handleInput("\x1b[<32;3;3M"); // drag onto src/
                    $tui->handleInput("\x1b[<0;3;3m"); // release
                },
                320 => function ($tui, $terminal) use ($project): void {
                    $this->assertFileExists($project->path('dup.php'), 'nothing moved while the question is open');
                    $screen = implode("\n", $this->screenGrid($tui, $terminal));
                    $this->assertStringContainsString('already exists', $screen, 'the prompt names the collision');
                    $tui->handleInput("\r"); // the first choice is Overwrite
                },
                460 => function ($tui) use ($project): void {
                    $this->assertFileDoesNotExist($project->path('dup.php'), 'the drag completed after the answer');
                    $this->assertStringContainsString(
                        'the one being dragged',
                        (string) file_get_contents($project->path('src/dup.php')),
                        'and the dragged file won',
                    );
                },
            ],
            columns: 120,
        );
    }

    /** A drop back into the folder a file already lives in is a slip, not an instruction. */
    public function testADropIntoItsOwnFolderDoesNothing(): void
    {
        putenv('PATH=/nonexistent');
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('top.php'),
            [
                200 => function ($tui): void {
                    $this->frame($tui);
                    $tui->handleInput("\x1b[<0;3;3M"); // click src/ open
                    $tui->handleInput("\x1b[<0;3;3m");
                },
                300 => function ($tui): void {
                    $rows = $this->treeRows($tui);
                    $this->assertSame('A.php', trim($rows[2] ?? ''), 'src/A.php is row 2');
                    $tui->handleInput("\x1b[<0;3;4M"); // press src/A.php
                    $tui->handleInput("\x1b[<32;3;3M"); // drag onto src/, where it already is
                    $tui->handleInput("\x1b[<0;3;3m");
                },
                420 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->assertFileExists($project->path('src/A.php'), 'still there');
                    $this->assertSame([], Toasts::all($ide), 'and nothing was announced — a no-op says nothing');
                    // Nor a QUESTION. Without the already-there filter the file
                    // collides with itself and the collision prompt opens, which
                    // is silent in every other respect and would pass the two
                    // assertions above.
                    $this->assertStringNotContainsString('already exists', implode("\n", $this->screenGrid($tui, $terminal)));
                },
            ],
            columns: 120,
        );
    }

    /**
     * Several files, several collisions: ONE question naming the count, not a
     * modal per file, and no Rename… — it has no single answer for a set.
     */
    public function testSeveralCollisionsAskOnce(): void
    {
        putenv('PATH=/nonexistent');
        $this->project = new TempProject([
            'src/a.php' => "<?php\n// theirs\n",
            'src/b.php' => "<?php\n// theirs\n",
            'a.php' => "<?php\n// mine\n",
            'b.php' => "<?php\n// mine\n",
        ]);
        $project = $this->project;
        $project = $this->project;

        $this->runIde(
            $project,
            $project->path('src/a.php'),
            [
                200 => function ($tui): void {
                    $this->frame($tui);
                    $tui->handleInput("\x1b[<0;3;4M"); // click a.php
                    $tui->handleInput("\x1b[<0;3;4m");
                    $tui->handleInput("\x1b[<16;3;5M"); // Ctrl+click b.php — adds to the selection
                    $tui->handleInput("\x1b[<16;3;5m");
                },
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[<0;3;4M"); // press a.php, which is in the selection
                    $tui->handleInput("\x1b[<32;3;3M"); // drag both onto src/
                    $tui->handleInput("\x1b[<0;3;3m");
                },
                420 => function ($tui, $terminal) use ($project): void {
                    $screen = implode("\n", $this->screenGrid($tui, $terminal));
                    $this->assertStringContainsString('2 files already exist', $screen, 'one question, naming the count');
                    $this->assertStringNotContainsString('Rename', $screen, 'and no Rename… — it has no single answer for a set');
                    $this->assertFileExists($project->path('a.php'), 'nothing moved while the question is open');
                },
            ],
            columns: 120,
        );
    }

    /** Rename… chains into the name prompt and moves under the typed name. */
    public function testACollisionAnsweredWithRenameMovesUnderTheNewName(): void
    {
        putenv('PATH=/nonexistent');
        $this->project = new TempProject([
            'src/dup.php' => "<?php\n// theirs\n",
            'dup.php' => "<?php\n// mine\n",
        ]);
        $project = $this->project;
        $project = $this->project;

        $this->runIde(
            $project,
            $project->path('src/dup.php'),
            [
                200 => function ($tui): void {
                    $this->frame($tui);
                    $tui->handleInput("\x1b[<0;3;4M");
                    $tui->handleInput("\x1b[<32;3;3M");
                    $tui->handleInput("\x1b[<0;3;3m");
                },
                320 => static function ($tui): void {
                    $tui->handleInput("\x1b[B"); // past Overwrite, onto Rename…
                    $tui->handleInput('
');
                },
                420 => static function ($tui): void {
                    // The prompt is prefilled with the name; replace it.
                    for ($i = 0; $i < 7; ++$i) {
                        $tui->handleInput("\x7f");
                    }
                    $tui->handleInput('moved.php');
                    $tui->handleInput('
');
                },
                560 => function ($tui) use ($project): void {
                    $this->assertFileExists($project->path('src/moved.php'), 'it landed under the typed name');
                    $this->assertFileDoesNotExist($project->path('dup.php'), 'and left its old home');
                    $this->assertStringContainsString(
                        'theirs',
                        (string) file_get_contents($project->path('src/dup.php')),
                        'the file that was already there is untouched',
                    );
                },
            ],
            columns: 120,
        );
    }

    /** Alt on the RELEASE copies instead of moving, and the original stays put. */
    public function testAltOnTheReleaseCopiesInsteadOfMoving(): void
    {
        putenv('PATH=/nonexistent');
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function ($tui): void {
                    $this->frame($tui);
                    $tui->handleInput("\x1b[<0;3;4M"); // press top.php
                    $tui->handleInput("\x1b[<32;3;3M"); // drag onto src/
                    $tui->handleInput("\x1b[<8;3;3m"); // release with Alt held (SGR bit 8)
                },
                380 => function ($tui) use ($project): void {
                    $this->assertFileExists($project->path('top.php'), 'a copy leaves the original');
                    $this->assertFileExists($project->path('src/top.php'), 'and puts one in the folder');
                },
            ],
            columns: 120,
        );
    }

    /**
     * Unsaved edits survive the move.
     *
     * The move closes the buffer and re-opens it from disk, and
     * `BufferManager::close()` has no dirty check — the guarded prompt lives
     * only in `closeActiveBuffer()`. So whatever was typed and not saved was
     * simply gone. Renaming is a deliberate act you might think twice about;
     * dragging is a flick of the wrist, which is why this is worth the save.
     */
    public function testUnsavedEditsSurviveADrag(): void
    {
        putenv('PATH=/nonexistent');
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('top.php'),
            [
                200 => function ($tui): void {
                    $this->frame($tui);
                    $tui->handleInput('EDIT');
                    $this->assertTrue($tui->getById('editor')->getDocument()->isDirty(), 'the buffer is dirty');
                },
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[<0;3;4M"); // press top.php
                    $tui->handleInput("\x1b[<32;3;3M"); // drag onto src/
                    $tui->handleInput("\x1b[<0;3;3m");
                },
                460 => function ($tui) use ($project): void {
                    $this->assertFileExists($project->path('src/top.php'));
                    $this->assertStringContainsString(
                        'EDIT',
                        (string) file_get_contents($project->path('src/top.php')),
                        'the unsaved edit went with the file rather than being dropped on the floor',
                    );
                },
            ],
            columns: 120,
        );
    }

    /** The status bar's left segment, ANSI stripped — what file the editor is showing. */
    private function statusText(mixed $tui): string
    {
        return \Symfony\Component\Tui\Ansi\AnsiUtils::stripAnsiCodes(
            $tui->getById('status')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1))[0],
        );
    }

    /** @return list<string> the tree's rows, ANSI stripped */
    private function treeRows(mixed $tui): array
    {
        $rows = [];
        foreach ($tui->getById('tree')->render(new \Symfony\Component\Tui\Render\RenderContext(30, 12)) as $line) {
            $rows[] = rtrim(\Symfony\Component\Tui\Ansi\AnsiUtils::stripAnsiCodes($line));
        }

        return $rows;
    }
}
