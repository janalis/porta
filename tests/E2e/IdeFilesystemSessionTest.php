<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Ide;
use Porta\Onboarding\OnboardingState;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
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
    use RunsIde;
    use ScreenReads;

    private string $originalPath;

    private ?string $portaHomeToRestore = null;

    protected function setUp(): void
    {
        $this->originalPath = PORTA_TEST_PRISTINE_PATH;
        putenv('PORTA_LSP');
    }

    protected function tearDown(): void
    {
        if (null !== $this->portaHomeToRestore) {
            putenv('PORTA_HOME='.$this->portaHomeToRestore);
            $this->portaHomeToRestore = null;
        }
        putenv('PATH='.$this->originalPath);
        putenv('PORTA_LSP');
    }

    public function testStartupWithoutInitialFileFocusesTree(): void
    {
        putenv('PATH=/nonexistent');
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
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => json_encode(['keymap' => ['not_a_real_action' => ['f9']]]),
            'a.php' => "<?php\n",
        ]);

        $terminal = $this->runIde($project, $project->path('a.php'), [], 0.6);

        $this->assertStringContainsString('not_a_real_action', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testUnopenableFiles(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['ok.php' => "<?php\n"]);
        $unreadable = $project->write('secret.php', 'nope');
        chmod($unreadable, permissions: 0o000);
        $project->write('huge.bin', str_repeat('x', (2 * 1024 * 1024) + 1));
        $project->write('latin1.txt', "caf\xE9");

        $terminal = $this->runIde(
            $project,
            $project->path('ok.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x14"); // focus tree: entries huge.bin, latin1.txt, ok.php, secret.php
                    $tui->handleInput("\r"); // huge.bin
                    $tui->tick(); // render the flash before it is replaced
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r"); // latin1.txt
                    $tui->tick();
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r"); // secret.php
                    $tui->tick();
                },
            ],
        );

        chmod($unreadable, permissions: 0o644);
        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('File too large', $screen);
        $this->assertStringContainsString('Not a UTF-8 text file', $screen);
        $this->assertStringContainsString('Cannot read secret.php', $screen);
    }

    public function testNoFileOpenShowsPlaceholder(): void
    {
        putenv('PATH=/nonexistent');
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
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput('E'); // dirty the single open buffer
                    $tui->handleInput("\x11"); // ctrl+q -> first press warns
                },
                400 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('1 file has edits', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->assertStringContainsString('Unsaved changes', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testSessionSavedOnQuitAndRestoredOnStartup(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "aaa\nbbb\n", 'B.php' => "ccc\n"]);

        // First run: open A and B, move the cursor, quit.
        $terminal = new VirtualTerminal(columns: 100, rows: 24);
        new OnboardingState($project->dir)->markWelcomeSeen();
        $ide = new Ide($project->dir, $project->path('A.php'), $terminal);
        $tui = $ide->resolve(Tui::class);
        EventLoop::delay(0.3, static function () use ($tui): void {
            $tui->handleInput("\x14"); // tree
            $tui->handleInput("\x1b[B"); // B.php
            $tui->handleInput("\r"); // open B.php
        });
        EventLoop::delay(0.5, static function () use ($tui): void {
            $tui->getById('editor')->getDocument()->setCursor(0, 2);
            $tui->handleInput("\x11"); // ctrl+q (clean buffers -> quits)
        });
        $watchdog = EventLoop::delay(3.0, static function () use ($tui): void {
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
        $tui2 = $ide2->resolve(Tui::class);
        EventLoop::delay(0.3, function () use ($tui2): void {
            $editor = $tui2->getById('editor');
            $this->assertNotNull($editor->getFilePath(), 'a buffer was restored');
            $this->assertSame('editor', $tui2->getFocus()?->getId());
            $tui2->stop();
        });
        $ide2->run();

        $this->addToAssertionCount(1);
    }

    public function testLocalHistorySnapshotAndRestore(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "version one\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui) use ($project): void {
                    // Edit and save -> first snapshot; edit and save -> second.
                    $d = $tui->getById('editor')->getDocument();
                    $d->selectAll();
                    $d->insert("version two\n");
                    $tui->handleInput("\x13"); // ctrl+s
                },
                450 => static function ($tui): void {
                    $d = $tui->getById('editor')->getDocument();
                    $d->selectAll();
                    $d->insert("version three\n");
                    $tui->handleInput("\x13"); // ctrl+s
                },
                600 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b\x08"); // ctrl+alt+h show history
                    $this->assertSame('results', $tui->getFocus()?->getId());
                    $tui->tick();
                    $this->assertStringContainsString('Local History', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // Restore the older snapshot (second row).
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r");
                },
                750 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('Restored from local history', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertStringContainsString('version two', $tui->getById('editor')->getDocument()->getText());
                    // Restoring is a single undo step back to what was on screen.
                    $tui->getById('editor')->handleInput("\x1a"); // ctrl+z
                    $this->assertStringContainsString('version three', $tui->getById('editor')->getDocument()->getText());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testShowHistoryWithNoSnapshots(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "x\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b\x08"); // ctrl+alt+h, nothing saved yet
                },
                450 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('No local history for this file yet', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testSaveAllAndSaveAndCloseDialog(): void
    {
        putenv('PATH=/nonexistent');
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
                    $this->assertStringContainsString('X', (string) file_get_contents($project->path('a.php')));
                    $this->assertStringContainsString('Y', (string) file_get_contents($project->path('b.php')));
                    // Now the Save & Close dialog path
                    $tui->handleInput('Z'); // dirty b.php again
                    $tui->handleInput("\x1bw"); // dialog
                    $tui->handleInput("\r"); // Save & Close (first option)
                },
                800 => function ($tui) use ($project): void {
                    $this->assertStringEndsWith('a.php', (string) $tui->getById('editor')->getFilePath());
                    $this->assertStringContainsString('Z', (string) file_get_contents($project->path('b.php')), 'saved before closing');
                },
            ],
        );

        $this->assertStringContainsString('Nothing to save', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testSaveFailureAndSaveWithoutFile(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['dir/locked.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('dir/locked.php'),
            [
                200 => static function ($tui) use ($project): void {
                    $tui->handleInput('x'); // dirty it
                    chmod($project->path('dir/locked.php'), permissions: 0o444);
                    chmod($project->path('dir'), permissions: 0o555);
                    $tui->handleInput("\x13"); // ctrl+s fails
                },
                400 => static function ($tui) use ($project): void {
                    chmod($project->path('dir'), permissions: 0o755);
                    chmod($project->path('dir/locked.php'), permissions: 0o644);
                    $tui->handleInput("\x1a"); // undo -> clean
                    $tui->handleInput("\x1bw"); // close buffer
                    $tui->handleInput("\x13"); // save with no file: no-op
                },
            ],
        );

        $this->assertStringContainsString('Save failed', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testQuitWithoutDirtyBuffersStopsImmediately(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);
        $terminal = new VirtualTerminal(columns: 80, rows: 20);
        new OnboardingState($project->dir)->markWelcomeSeen();
        $ide = new Ide($project->dir, $project->path('a.php'), $terminal);
        $tui = $ide->resolve(Tui::class);

        EventLoop::delay(0.2, static fn () => $tui->handleInput("\x11"));
        $watchdog = EventLoop::delay(3.0, static function () use ($tui): void {
            $tui->stop();
            throw new \RuntimeException('quit did not stop the app');
        });
        $ide->run();
        EventLoop::cancel($watchdog);

        $this->assertFalse($tui->isRunning());
    }

    public function testQuitRestoresTerminalViaAlternateScreen(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);
        $terminal = new VirtualTerminal(columns: 80, rows: 20);
        new OnboardingState($project->dir)->markWelcomeSeen();
        $ide = new Ide($project->dir, $project->path('a.php'), $terminal);
        $tui = $ide->resolve(Tui::class);

        EventLoop::delay(0.2, static fn () => $tui->handleInput("\x11")); // Ctrl+Q
        $watchdog = EventLoop::delay(3.0, static function () use ($tui): void {
            $tui->stop();
            throw new \RuntimeException('quit did not stop the app');
        });
        $ide->run();
        EventLoop::cancel($watchdog);

        $out = $terminal->getOutput();
        // Entered the alternate screen on start and left it on quit → the terminal
        // is restored to its pre-launch state (no leftover IDE frame).
        $this->assertStringContainsString("\x1b[?1049h", $out, 'entered the alternate screen');
        $this->assertStringContainsString("\x1b[?1049l", $out, 'left the alternate screen on quit');
        $this->assertGreaterThan(strpos($out, needle: "\x1b[?1049h"), strrpos($out, needle: "\x1b[?1049l"), 'leave comes after enter');
        $this->assertStringContainsString("\x1b[?1000l", $out, 'mouse tracking disabled on quit');
    }

    public function testInitialLineJumpsCaretOnBoot(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "l0\nl1\nl2\nl3\nl4\nl5\n"]);
        $terminal = new VirtualTerminal(columns: 80, rows: 24);
        new OnboardingState($project->dir)->markWelcomeSeen();
        $ide = new Ide($project->dir, $project->path('A.php'), $terminal, null, initialLine: 4);
        $tui = $ide->resolve(Tui::class);

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
        putenv('PATH=/nonexistent');
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
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n// original\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui, $terminal, $ide) use ($project): void {
                    $tui->tick();
                    // The file grows beyond the 2 MB text limit → the poll must not
                    // try to load it, leaving the open buffer as-is.
                    $project->write('A.php', str_repeat('x', (2 * 1024 * 1024) + 1));
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
        putenv('PATH=/nonexistent');
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
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n// keep me\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui, $terminal, $ide) use ($project): void {
                    $tui->tick();
                    unlink($project->path('A.php')); // deleted on disk
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
        $this->assertSame(1, substr_count($screen, needle: 'deleted on disk'), 'warns exactly once');
    }

    public function testOwnSaveDoesNotTriggerReload(): void
    {
        putenv('PATH=/nonexistent');
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
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $tui->tick();
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNull($this->rowOf($grid, 'fresh.php'), 'not there yet');
                    $project->write('fresh.php', "<?php\n"); // created by an external tool
                    $ide->pollFilesystem();
                    $tui->tick();
                },
                300 => function ($tui, $terminal): void {
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($grid, 'fresh.php'), 'poll refreshed the tree');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
