<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Ide;
use Porta\Onboarding\OnboardingState;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Filesystem and session edges: unopenable files, external
 * changes, save/quit flows, local history, session restore.
 * Split out of the oversized IdeEdgeCasesTest.
 */
final class IdeFilesystemSessionTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use ScreenReads;

    /** Held so its destructor does not remove the project while the Ide still points at it. */
    private TempProject $quitProject;

    /**
     * Boot on a file, press Ctrl+Q a fifth of a second in, and run to a stop.
     *
     * The watchdog is the point of doing this in one place: a quit that does
     * NOT stop the app would otherwise hang the suite rather than fail it, so
     * it stops the loop and throws, which turns a hang into a named failure.
     *
     * The terminal is the caller's because one case reads the escape sequences
     * off it; the Tui comes back because the other asks whether it stopped.
     */
    private function runUntilCtrlQ(VirtualTerminal $terminal): Tui
    {
        \putenv('PATH=/nonexistent');
        $this->quitProject = new TempProject(['a.php' => "<?php\n"]);
        new OnboardingState($this->quitProject->dir)->markWelcomeSeen();
        $ide = new Ide($this->quitProject->dir, $this->quitProject->path('a.php'), $terminal);
        $tui = Services::resolve($ide, Tui::class);

        EventLoop::delay(0.2, static fn () => $tui->handleInput("\x11")); // Ctrl+Q
        $watchdog = EventLoop::delay(3.0, static function () use ($tui): never {
            $tui->stop();
            throw new \RuntimeException('quit did not stop the app');
        });
        $ide->run();
        EventLoop::cancel($watchdog);

        return $tui;
    }

    public function testStartupWithoutInitialFileFocusesTree(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            null,
            [
                300 => function ($tui): void {
                    $this->assertSame('tree', $tui->getFocus()?->getId());
                },
            ],
        );
    }

    public function testConfigWarningsAreFlashed(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => \json_encode(['keymap' => ['not_a_real_action' => ['f9']]]),
            'a.php' => "<?php\n",
        ]);

        $terminal = $this->runIde($project, $project->path('a.php'), [], 0.6);

        $this->assertStringContainsString('not_a_real_action', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testUnopenableFiles(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['ok.php' => "<?php\n"]);
        $unreadable = $project->write('secret.php', 'nope');
        \chmod($unreadable, permissions: 0o000);
        $project->write('exactly.bin', \str_repeat("x\n", 1024 * 1024)); // exactly MAX_TEXT_BYTES, many short lines
        $project->write('huge.bin', \str_repeat('x', (2 * 1024 * 1024) + 1));
        $project->write('latin1.txt', "caf\xE9");

        $terminal = $this->runIde(
            $project,
            $project->path('ok.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    // A file at exactly the cap still opens (boundary is >, not
                    // >=) — checked directly, independent of the tree's
                    // click-navigation flow below.
                    Services::resolve($ide, BufferService::class)->openFile($project->path('exactly.bin'), OpenFocus::Editor);
                    $this->assertStringEndsWith('exactly.bin', (string) $tui->getById('editor')->getFilePath(), 'a file at exactly the cap still opens');

                    $tui->handleInput("\x14"); // focus tree: entries exactly.bin, huge.bin, latin1.txt, ok.php, secret.php
                    $tui->handleInput("\x1b[B"); // skip past exactly.bin
                    $tui->handleInput("\r"); // huge.bin
                    $tui->tick();
                    // huge.bin is no longer REFUSED: it opens read-only, which
                    // takes focus into the preview pane. Every keystroke below
                    // is meant for the tree, so take focus back — before this,
                    // they went to the pane and the walk never reached
                    // secret.php at all.
                    $this->awaitPainted($tui, $terminal, 'Read-only');
                    $tui->handleInput("\x14");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r"); // latin1.txt
                    $tui->tick();
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r"); // secret.php
                    // Last of the two refusals the post-run assertions read.
                    $this->awaitPainted($tui, $terminal, 'Cannot read secret.php');
                },
            ],
        );

        \chmod($unreadable, permissions: 0o644);
        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        // Too large is no longer a refusal: the file opens read-only, and the
        // banner naming its real size is what the user gets instead of a flash.
        $this->assertStringContainsString('Read-only', $screen);
        $this->assertStringContainsString('huge.bin', $screen);
        $this->assertStringNotContainsString('File too large', $screen, 'the old refusal is gone');
        $this->assertStringContainsString('Not a UTF-8 text file', $screen);
        $this->assertStringContainsString('Cannot read secret.php', $screen);
    }

    public function testNoFileOpenShowsPlaceholder(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n"]);

        $terminal = $this->runIde(
            $project,
            null, // start with no file and no session → the editor has nothing open
            [
                250 => function ($tui, $terminal): void {
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('No file open', $screen, 'the empty pane shows the placeholder');
                    $this->assertNull($tui->getById('editor')->getFilePath(), 'no file is open');
                },
            ],
        );

        $this->assertStringContainsString('No file open', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testQuitWithASingleDirtyBufferWarnsInTheSingular(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput('E'); // dirty the single open buffer
                    $tui->handleInput("\x11"); // ctrl+q -> first press warns
                },
                400 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, '1 file has edits');
                    $this->assertStringContainsString('1 file has edits', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->assertStringContainsString('Unsaved changes', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testSessionSavedOnQuitAndRestoredOnStartup(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "aaa\nbbb\n", 'B.php' => "ccc\n"]);

        // First run: open A and B, move the cursor, quit.
        $terminal = new VirtualTerminal(columns: 100, rows: 24);
        new OnboardingState($project->dir)->markWelcomeSeen();
        $ide = new Ide($project->dir, $project->path('A.php'), $terminal);
        $tui = Services::resolve($ide, Tui::class);
        EventLoop::delay(0.3, static function () use ($tui): void {
            $tui->handleInput("\x14"); // tree
            $tui->handleInput("\x1b[B"); // B.php
            $tui->handleInput("\r"); // open B.php
        });
        EventLoop::delay(0.5, static function () use ($tui): void {
            $tui->getById('editor')->getDocument()->setCursor(0, 2);
            $tui->handleInput("\x11"); // ctrl+q (clean buffers -> quits)
        });
        $watchdog = EventLoop::delay(3.0, static function () use ($tui): never {
            $tui->stop();
            throw new \RuntimeException('did not quit');
        });
        $ide->run();
        EventLoop::cancel($watchdog);

        $this->assertFileExists($project->path('.porta/session.json'));

        // Second run with NO file argument: the session reopens.
        $terminal2 = new VirtualTerminal(columns: 100, rows: 24);
        new OnboardingState($project->dir)->markWelcomeSeen();
        $ide2 = new Ide($project->dir, null, $terminal2);
        $tui2 = Services::resolve($ide2, Tui::class);
        EventLoop::delay(0.3, function () use ($tui2): void {
            $editor = $tui2->getById('editor');
            $this->assertNotNull($editor->getFilePath(), 'a buffer was restored');
            $this->assertSame('editor', $tui2->getFocus()?->getId());
            $tui2->stop();
        });
        $ide2->run();

        $this->addToAssertionCount(1);
    }

    public function testSaveAllAndSaveAndCloseDialog(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n", 'b.php' => "<?php // b\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b\x13"); // ctrl+alt+s with nothing dirty
                    $tui->tick();
                    $tui->handleInput('X'); // dirty a.php
                },
                350 => static function ($tui): void {
                    $tui->handleInput("\x14"); // tree
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r"); // open b.php
                },
                500 => static function ($tui): void {
                    $tui->handleInput('Y'); // dirty b.php
                    $tui->handleInput("\x1b\x13"); // ctrl+alt+s -> saves both
                },
                650 => function ($tui, $terminal) use ($project): void {
                    $this->assertStringContainsString('Saved 2 files', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertStringContainsString('X', (string) \file_get_contents($project->path('a.php')));
                    $this->assertStringContainsString('Y', (string) \file_get_contents($project->path('b.php')));
                    // Now the Save & Close dialog path
                    $tui->handleInput('Z'); // dirty b.php again
                    $tui->handleInput("\x1bw"); // dialog
                    $tui->handleInput("\r"); // Save & Close (first option)
                },
                800 => function ($tui) use ($project): void {
                    $this->assertStringEndsWith('a.php', (string) $tui->getById('editor')->getFilePath());
                    $this->assertStringContainsString('Z', (string) \file_get_contents($project->path('b.php')), 'saved before closing');
                },
            ],
        );

        $this->assertStringContainsString('Nothing to save', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testSaveFailureAndSaveWithoutFile(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['dir/locked.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('dir/locked.php'),
            [
                200 => static function ($tui) use ($project): void {
                    $tui->handleInput('x'); // dirty it
                    \chmod($project->path('dir/locked.php'), permissions: 0o444);
                    \chmod($project->path('dir'), permissions: 0o555);
                    $tui->handleInput("\x13"); // ctrl+s fails
                },
                400 => static function ($tui) use ($project): void {
                    \chmod($project->path('dir'), permissions: 0o755);
                    \chmod($project->path('dir/locked.php'), permissions: 0o644);
                    $tui->handleInput("\x1a"); // undo -> clean
                    $tui->handleInput("\x1bw"); // close buffer
                    $tui->handleInput("\x13"); // save with no file: no-op
                },
            ],
        );

        $this->assertStringContainsString('Save failed', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testSaveAllDoesNotClaimNothingToSaveWhenEverySaveFails(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['dir/locked.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('dir/locked.php'),
            [
                200 => static function ($tui) use ($project): void {
                    $tui->handleInput('x'); // dirty it
                    \chmod($project->path('dir/locked.php'), permissions: 0o444);
                    \chmod($project->path('dir'), permissions: 0o555);
                    $tui->handleInput("\x1b\x13"); // ctrl+alt+s: save all, the only dirty buffer fails
                },
                400 => static function ($tui) use ($project): void {
                    \chmod($project->path('dir'), permissions: 0o755);
                    \chmod($project->path('dir/locked.php'), permissions: 0o644);
                },
            ],
        );

        $output = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('Save failed', $output);
        $this->assertStringNotContainsString('Nothing to save', $output, 'every dirty buffer already flashed its own failure — the aggregate must not claim there was nothing to do');
    }

    public function testQuitWithoutDirtyBuffersStopsImmediately(): void
    {
        $terminal = new VirtualTerminal(columns: 80, rows: 20);
        $tui = $this->runUntilCtrlQ($terminal);

        $this->assertFalse($tui->isRunning());
    }

    public function testQuitRestoresTerminalViaAlternateScreen(): void
    {
        $terminal = new VirtualTerminal(columns: 80, rows: 20);
        $this->runUntilCtrlQ($terminal);

        $out = $terminal->getOutput();
        // Entered the alternate screen on start and left it on quit → the terminal
        // is restored to its pre-launch state (no leftover IDE frame).
        $this->assertStringContainsString("\x1b[?1049h", $out, 'entered the alternate screen');
        $this->assertStringContainsString("\x1b[?1049l", $out, 'left the alternate screen on quit');
        $this->assertGreaterThan(\strpos($out, needle: "\x1b[?1049h"), \strrpos($out, needle: "\x1b[?1049l"), 'leave comes after enter');
        $this->assertStringContainsString("\x1b[?1000l", $out, 'mouse tracking disabled on quit');

        // And the cursor is homed the moment the alternate screen is entered.
        // `CSI ?1049h` does not reset the cursor ROW, the first frame is
        // APPENDED from wherever it is, and ScreenWriter counts rows from zero
        // while repainting relatively — so launching from a prompt partway down
        // the screen costs that many rows off the top and puts every later
        // repaint the same distance off, for the whole session. Measured in a
        // real pty with eight lines printed first: nine rows of scroll without
        // this, none with it.
        $entered = \strpos($out, needle: "\x1b[?1049h");
        $homed = \strpos($out, needle: "\x1b[2J\x1b[H");
        $this->assertNotFalse($homed, 'the screen is cleared and the cursor homed');
        $this->assertGreaterThan($entered, $homed, 'and that happens after entering the alternate screen');
    }

    public function testSigtermStopsTheSessionAndRestoresTheTerminal(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);
        $terminal = new VirtualTerminal(columns: 80, rows: 20);
        new OnboardingState($project->dir)->markWelcomeSeen();
        $ide = new Ide($project->dir, $project->path('a.php'), $terminal);
        $tui = Services::resolve($ide, Tui::class);

        // An outside kill, not an in-process call: the real delivery path.
        // `kill` is a shell builtin, so the poisoned PATH above cannot hide it.
        EventLoop::delay(0.2, static function (): void {
            \exec('kill -s TERM '.\getmypid());
        });
        $watchdog = EventLoop::delay(5.0, static function () use ($tui): never {
            $tui->stop();
            throw new \RuntimeException('SIGTERM did not stop the app');
        });
        $ide->run();
        EventLoop::cancel($watchdog);

        $out = $terminal->getOutput();
        // The signal unwound through the ordinary teardown: alternate screen
        // left, mouse reporting off — the user's shell is intact.
        $this->assertStringContainsString("\x1b[?1049l", $out, 'left the alternate screen on SIGTERM');
        $this->assertStringContainsString("\x1b[?1003l", $out, 'mouse tracking disabled on SIGTERM');
        $this->assertFileExists($project->dir.'/.porta/session.json', 'the session was saved on the way out');
    }

    public function testInitialLineJumpsCaretOnBoot(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "l0\nl1\nl2\nl3\nl4\nl5\n"]);
        $terminal = new VirtualTerminal(columns: 80, rows: 24);
        new OnboardingState($project->dir)->markWelcomeSeen();
        $ide = new Ide($project->dir, $project->path('A.php'), $terminal, null, initialLine: 4);
        $tui = Services::resolve($ide, Tui::class);

        $timer = EventLoop::delay(0.2, function () use ($tui): void {
            $tui->tick();
            $this->assertSame(3, $tui->getById('editor')->getDocument()->getCursorLine(), 'CLI line 4 → 0-based line 3');
            $tui->stop();
        });
        try {
            $ide->run();
        } finally {
            EventLoop::cancel($timer);
        }

        $this->addToAssertionCount(1);
    }

    public function testExternalChangeReloadsCleanBuffer(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n// original\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $tui->tick();
                    $this->assertStringContainsString('original', $tui->getById('editor')->getDocument()->getText());
                    $project->write('A.php', "<?php\n// changed on disk\n"); // external edit
                    $ide->pollFilesystem();
                },
                300 => function ($tui): void {
                    $doc = $tui->getById('editor')->getDocument();
                    $this->assertStringContainsString('changed on disk', $doc->getText(), 'clean buffer reloaded');
                    $this->assertFalse($doc->isDirty(), 'reloaded buffer is not dirty');
                    $this->assertStringContainsString('Reloaded A.php', AnsiUtils::stripAnsiCodes($tui->getTerminal()->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testExternalChangeToUnreadableContentLeavesBufferUntouched(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n// original\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui, $terminal, $ide) use ($project): void {
                    $tui->tick();
                    // The file grows beyond the 2 MB text limit → the poll must not
                    // try to load it, leaving the open buffer as-is.
                    $project->write('A.php', \str_repeat('x', (2 * 1024 * 1024) + 1));
                    $ide->pollFilesystem();
                },
                300 => function ($tui): void {
                    $doc = $tui->getById('editor')->getDocument();
                    $this->assertStringContainsString('original', $doc->getText(), 'oversized change ignored, buffer kept');
                    $this->assertFalse($doc->isDirty());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testExternalChangeKeepsDirtyBufferAndWarns(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui, $terminal, $ide) use ($project): void {
                    $tui->tick();
                    $tui->getById('editor')->getDocument()->setCursor(0, 0);
                    $tui->getById('editor')->handleInput('X'); // unsaved edit → dirty
                    $project->write('A.php', "<?php\n// external\n");
                    $ide->pollFilesystem();
                },
                300 => function ($tui): void {
                    $doc = $tui->getById('editor')->getDocument();
                    $this->assertStringStartsWith('X', $doc->getText(), 'the unsaved edit was kept, not clobbered');
                    $this->assertTrue($doc->isDirty());
                },
            ],
        );

        $this->assertStringContainsString('A.php changed on disk', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testDeletedFileKeepsBufferAndWarns(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n// keep me\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui, $terminal, $ide) use ($project): void {
                    $tui->tick();
                    \unlink($project->path('A.php')); // deleted on disk
                    $ide->pollFilesystem();
                    $ide->pollFilesystem(); // second poll must not re-flash
                },
                300 => function ($tui): void {
                    $this->assertStringContainsString('keep me', $tui->getById('editor')->getDocument()->getText(), 'buffer retained');
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('A.php deleted on disk', $screen);
        $this->assertSame(1, \substr_count($screen, needle: 'deleted on disk'), 'warns exactly once');
    }

    public function testOwnSaveDoesNotTriggerReload(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    $tui->getById('editor')->handleInput('Y'); // dirty
                    $tui->handleInput("\x13"); // ctrl+s → save (records our own disk stamp)
                    $ide->pollFilesystem();
                },
                300 => function ($tui): void {
                    $screen = AnsiUtils::stripAnsiCodes($tui->getTerminal()->getOutput());
                    $this->assertStringNotContainsString('Reloaded', $screen, 'own save must not look like an external change');
                    $this->assertStringNotContainsString('changed on disk', $screen);
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testNewFileOnDiskAppearsInTreeAfterPoll(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, $ide) use ($project): void {
                    $tui->tick();
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNull($this->rowOf($grid, 'fresh.php'), 'not there yet');
                    $project->write('fresh.php', "<?php\n"); // created by an external tool
                    $ide->pollFilesystem();
                    $tui->tick();
                },
                300 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($grid, 'fresh.php'), 'poll refreshed the tree');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * A file that arrives with a UTF-8 BOM leaves with one.
     *
     * The round trip, which is the whole reason the document strips the mark
     * rather than tolerating it: it is off the text everywhere the width
     * arithmetic, the digest and the language server can see it, and back on
     * the bytes at the one place that writes them. Nothing in this tree handled
     * a BOM before — `mb_strwidth()` counts U+FEFF as a column that no terminal
     * draws, so line 0 of such a file measured one wider than it rendered.
     */
    public function testAFileWithAByteOrderMarkKeepsItAcrossAnEdit(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['marked.php' => "\u{FEFF}<?php\n"]);

        $this->runIde(
            $project,
            $project->path('marked.php'),
            [
                200 => function ($tui) use ($project): void {
                    $this->assertSame(
                        '<?php',
                        $tui->getById('editor')->getDocument()->getLine(0),
                        'the mark is off the text the editor holds',
                    );
                    $tui->getById('editor')->getDocument()->setCursor(0, 0);
                    $tui->handleInput('X');
                    $tui->handleInput("\x13"); // ctrl+s
                },
                400 => function ($tui) use ($project): void {
                    $saved = (string) \file_get_contents($project->path('marked.php'));
                    $this->assertStringStartsWith("\u{FEFF}", $saved, 'the mark went back on the bytes');
                    $this->assertSame("\u{FEFF}X<?php\n", $saved, 'and the edit landed after it, not in front of it');
                },
            ],
        );
    }

    /** A CRLF file edited and saved keeps its line endings. */
    public function testACrlfFileKeepsItsLineEndingsAcrossAnEdit(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['win.php' => "<?php\r\n\r\necho 1;\r\n"]);

        $this->runIde(
            $project,
            $project->path('win.php'),
            [
                200 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(0, 5);
                    $tui->handleInput('X');
                    $tui->handleInput("\x13"); // ctrl+s
                },
                400 => function ($tui) use ($project): void {
                    $saved = (string) \file_get_contents($project->path('win.php'));
                    $this->assertStringContainsString("\r\n", $saved, 'the file still uses CRLF');
                    $this->assertSame("<?phpX\r\n\r\necho 1;\r\n", $saved);
                },
            ],
        );
    }

    /**
     * Save All saves a dirty buffer sitting behind a clean one.
     *
     * The loop skips a buffer with nothing to write, and turning that
     * `continue` into a `break` left the suite green: the existing Save All
     * case dirties BOTH its buffers, so there is no clean one in front to stop
     * at. Open a file, read it, open another and edit that — the ordinary way
     * anyone works — and Ctrl+Alt+S would have written nothing while reporting
     * success.
     */
    public function testSaveAllReachesADirtyBufferBehindACleanOne(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n// a\n", 'b.php' => "<?php\n// b\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x14"); // focus the tree
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r"); // open b.php, leaving a.php clean
                },
                400 => static function ($tui): void {
                    $tui->handleInput('Y'); // dirty b.php only
                    $tui->handleInput("\x1b\x13"); // ctrl+alt+s
                },
                600 => function ($tui, $terminal) use ($project): void {
                    $this->assertStringContainsString(
                        'Y',
                        (string) \file_get_contents($project->path('b.php')),
                        'the dirty buffer behind the clean one was written',
                    );
                    $this->assertStringContainsString('Saved 1 file', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }
}
