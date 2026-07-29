<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;

/**
 * Editing chrome beyond keystrokes: multi-caret flows, structural
 * selection, sticky scroll, breadcrumbs, toasts.
 * Split out of the oversized IdeEdgeCasesTest.
 */
final class IdeEditingTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    public function testStickyScrollPinsHeaderAndClickJumps(): void
    {
        putenv('PATH=/nonexistent');
        $code = "<?php\n\nnamespace App;\n\nclass Service\n{\n    public function handle(): void\n    {\n".str_repeat("        \$x = 1;\n", times: 30)."    }\n}\n";
        $project = new TempProject(['Service.php' => $code]);

        $this->runIde(
            $project,
            $project->path('Service.php'),
            [
                200 => static function ($tui): void {
                    for ($i = 0; $i < 30; ++$i) {
                        $tui->handleInput("\x1b[B"); // scroll deep into the method
                    }
                },
                280 => function ($tui, $terminal, $ide): void {
                    $editor = $tui->getById('editor');
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($editor);
                    $rendered = $editor->render(new \Symfony\Component\Tui\Render\RenderContext($rect->columns, $rect->rows));
                    $this->assertStringContainsString('class Service', AnsiUtils::stripAnsiCodes($rendered[0]), 'class header pinned at the top');
                    // Click the pinned class header (editor row 0).
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 7, $rect->row + 1));
                },
                360 => function ($tui): void {
                    $this->assertSame(4, $tui->getById('editor')->getDocument()->getCursorLine(), 'clicking the pinned header jumped to the class declaration');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testBreadcrumbsShowScopePathAndJumpOnClick(): void
    {
        putenv('PATH=/nonexistent');
        $code = "<?php\n\nnamespace App;\n\nclass Service\n{\n    public function handle(): void\n    {\n        \$x = 1;\n    }\n}\n";
        $project = new TempProject(['Service.php' => $code]);

        $this->runIde(
            $project,
            $project->path('Service.php'),
            [
                200 => function ($tui): void {
                    // Caret at the very top is outside every scope → no bar.
                    $this->assertSame([], $tui->getById('breadcrumbs')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1)));
                    // Navigate into handle() with the keyboard (invalidates the editor → relayout).
                    for ($i = 0; $i < 8; ++$i) {
                        $tui->handleInput("\x1b[B"); // down to line 8 (the `$x` line)
                    }
                },
                280 => function ($tui): void {
                    $plain = AnsiUtils::stripAnsiCodes($tui->getById('breadcrumbs')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1))[0]);
                    $this->assertStringContainsString('App › Service › handle', $plain, 'the scope path is shown');
                },
                340 => static function ($tui, $terminal, $ide): void {
                    $tui->requestRender();
                    $tui->tick();
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tui->getById('breadcrumbs'));
                    // ' App'(1..3) › 'Service'(7..13) › 'handle'. Click 'Service' (col 8) →
                    // jump to the class declaration (0-based line 4).
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 8 + 1, $rect->row + 1));
                },
                420 => function ($tui): void {
                    $this->assertSame(4, $tui->getById('editor')->getDocument()->getCursorLine(), 'clicking the Service crumb jumped to the class declaration');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMultiCursorThroughRunningIde(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "one\ntwo\nthree\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(0, 0);
                    $tui->handleInput("\x1b[1;7B"); // ctrl+alt+down
                    $tui->handleInput("\x1b[1;7B");
                    $this->assertTrue($editor->hasMultipleCarets());
                    $tui->handleInput('#'); // type at all three carets
                    $this->assertSame("#one\n#two\n#three\n", $editor->getDocument()->getText());
                    $tui->handleInput("\x1a"); // ctrl+z: one undo reverts all
                    $this->assertSame("one\ntwo\nthree\n", $editor->getDocument()->getText());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMultiCaretSurvivesStrayInputAndShiftSelects(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "aaaa\nbbbb\ncccc\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(0, 0);
                    $tui->handleInput("\x1b[1;7B");
                    $tui->handleInput("\x1b[1;7B"); // carets on lines 0,1,2
                    // A stray focus report between keystrokes must not collapse them.
                    $tui->handleInput('X');
                    $tui->handleInput("\x1b[I");
                    $tui->handleInput('Y');
                    $this->assertTrue($editor->hasMultipleCarets(), 'stray input kept the carets');
                    $this->assertSame("XYaaaa\nXYbbbb\nXYcccc\n", $editor->getDocument()->getText());
                    // shift+right selects one char at every caret; typing replaces each.
                    $tui->handleInput("\x1b[1;2C");
                    $tui->handleInput('Z');
                    $this->assertSame("XYZaaa\nXYZbbb\nXYZccc\n", $editor->getDocument()->getText());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testExpandSelectionThenSurroundThroughFullIde(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['src/A.php' => "<?php\n\$x = foo(bar);\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(1, 10); // inside "bar"
                    $tui->handleInput("\x17"); // ctrl+w: expand to the word "bar"
                    $tui->handleInput("\x1b\x14"); // ctrl+alt+t: open the surround menu
                    $tui->handleInput("\x1b[B"); // down to "[ ] Brackets"
                    $tui->handleInput("\r"); // accept -> wrap "bar" in [ ]
                },
                600 => static function ($tui): void {
                    $tui->tick();
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('foo([bar])', $screen);
    }

    public function testNotificationLogShowsExpiredFlashesInTheDock(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$a = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui, $terminal, $ide): void {
                    // The 0.1 s flash will expire; the log is the only way back to it.
                    Services::resolve($ide, Notifier::class)
                        ->flash('the answer is 42', 0.1);
                },
                400 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    Services::resolve($ide, CommandRegistry::class)->run('ide_notifications');
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Notifications', $screen, 'the dock tab opened');
                    $this->assertStringContainsString('the answer is 42', $screen, 'the expired flash is reviewable');

                    // A flash landing while the tab is open appears live, newest first.
                    Services::resolve($ide, Notifier::class)
                        ->flash('a later event', 0.1);
                    $tui->tick();
                    $panelText = AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('results')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 12))));
                    $this->assertLessThan(
                        strpos($panelText, needle: 'the answer is 42'),
                        strpos($panelText, needle: 'a later event'),
                        'the new flash appended live, newest first',
                    );
                    $tui->setFocus($tui->getById('results'));
                    $tui->handleInput("\r"); // Enter on a log row is inert
                    $tui->tick();
                    $this->assertTrue($tui->getById('results')->isVisible(), 'the tab stays put on Enter');
                    $tui->handleInput("\x1b"); // Esc hides the dock
                    $tui->tick();
                    $this->assertFalse($tui->getById('results')->isVisible());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
