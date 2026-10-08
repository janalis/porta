<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Help\HelpViewController;
use Porta\History\SessionRestorer;
use Porta\Ide;
use Porta\Overlay\Presenter\ViewWindowPresenter;
use Porta\Overlay\Region\ModalBox;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Tests\Support\Widgets;
use Porta\Widget\FindBarWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The F1 help browser: docs render styled in the Documentation window (no
 * markdown syntax on screen), Tab cycles the links, Enter/click follows,
 * Backspace goes back, and the window keeps its page across closing and
 * reopening — no tab is ever opened for it.
 */
final class IdeDocsTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use ScreenReads;

    protected function setUp(): void
    {
        \putenv('PATH=/nonexistent');
    }

    /** A docs fixture with a heading, emphasis, a table, and one link per kind. */
    private function docs(): TempProject
    {
        return new TempProject([
            'README.md' => "# Guides\n\nRead the **full** manual pages:\n\n| Key | Guide |\n| --- | --- |\n| `F1` | [Editor](editor.md) |\n\nAlso see [Site](https://example.com/x).\n",
            'editor.md' => "# Editor guide\n\nplain prose here\n",
        ]);
    }

    public function testF1RendersTheDocsAndTabEnterNavigatesLinks(): void
    {
        $docs = $this->docs();
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function (Tui $tui, $terminal): void {
                    $tui->handleInput("\x1bOP"); // F1
                    $this->frame($tui);
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Guides', $screen, 'the heading text renders');
                    $this->assertStringNotContainsString('# Guides', $screen, 'without markdown syntax');
                    $this->assertStringNotContainsString('](', $screen);
                    $this->assertStringNotContainsString('**', $screen);
                    $this->assertStringContainsString('Key  │ Guide', $screen, 'the table renders aligned, its columns ruled');
                    $this->assertStringContainsString('Documentation', $screen, 'the window is titled Documentation');

                    // Typing does not edit anything — there is no document behind the page.
                    $tui->handleInput('x');
                    $this->frame($tui);
                    $this->assertStringNotContainsString('xGuides', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
                600 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $tui->handleInput("\t");
                    // focus the [Editor] link
                    $tui->handleInput("\r");
                    // follow it
                    $this->frame($tui);
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Editor guide', $screen, 'the linked page rendered');
                    $this->assertStringContainsString('plain prose here', $screen);
                    $tui->handleInput("\x7f");
                    // Backspace → back to the README
                    $this->awaitPainted($tui, $terminal, 'Guides');
                    $this->assertStringContainsString('Guides', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\x7f");
                    // history exhausted
                    $this->awaitPainted($tui, $terminal, 'Nowhere to go back to');
                    $this->assertStringContainsString('Nowhere to go back to', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
                900 => function (Tui $tui, $terminal, Ide $ide): void {
                    $this->frame($tui);
                    // Re-focus the page with a click on a blank body row, then
                    // follow the link again: no tab opens, the page swaps in place.
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tui->getById('help-viewer'));
                    $this->assertInstanceOf(WidgetRect::class, $rect, 'the help viewer pane is laid out');
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 1, $rect->row + 4));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 1, $rect->row + 4));
                    $tui->handleInput("\r"); // the focused link is still [Editor] (restored by the earlier back)
                    $this->frame($tui);
                    $paths = \array_map(static fn (Buffer $b): string => $b->path, Services::resolve($ide, BufferManager::class)->all());
                    $this->assertCount(1, $paths, 'the docs open no tab: only a.php');
                    $this->assertStringEndsWith('editor.md', (string) Widgets::byId($tui, 'help-viewer', HelpViewerWidget::class)->path(), 'the page swapped in place');

                    // Closing the window (Esc) and reopening it (F1) must not wipe the trail.
                    $tui->handleInput("\x1b");
                    $this->frame($tui);
                    $this->assertFalse(Services::resolve($ide, ViewRegistry::class)->isOpen('help'), 'Esc closed the window');
                    $tui->handleInput("\x1bOP");
                    $this->frame($tui);
                    $this->assertStringEndsWith('editor.md', (string) Widgets::byId($tui, 'help-viewer', HelpViewerWidget::class)->path(), 'the navigated page survived closing the window');
                    $rect = $renderer->getWidgetRect($tui->getById('help-viewer'));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 2, $rect->row + 1)); // the ‹ arrow
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 2, $rect->row + 1));
                    $this->frame($tui);
                    $this->assertStringEndsWith('README.md', (string) Widgets::byId($tui, 'help-viewer', HelpViewerWidget::class)->path(), 'the ‹ arrow still navigates after the round trip');
                },
                1200 => function (Tui $tui, $terminal): void {
                    $this->frame($tui);
                    // F10 then ↓: the app-menu dropdown composites OVER the docs window.
                    $tui->handleInput("\x1b[21~");
                    $tui->handleInput("\x1b[B");
                    $this->frame($tui);
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Save File', $screen, 'the File dropdown is visible over the docs window');
                    $tui->handleInput("\x1b");
                },
            ],
            docsDir: $docs->dir,
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseClickAndWheelAndSessionExclusion(): void
    {
        $docs = $this->docs();
        $project = new TempProject(['a.php' => "<?php\n"]);
        $captureFile = $project->path('opened-urls.txt');
        foreach (['open', 'xdg-open'] as $name) {
            $shim = $project->write('shim-bin/'.$name, "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> ".\escapeshellarg($captureFile)."\n");
            \chmod($shim, permissions: 0o755);
        }
        \putenv('PATH='.$project->path('shim-bin'));

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function (Tui $tui, $terminal, Ide $ide): void {
                    // The 300 ms here was a bet on the boot having opened the
                    // file; the editor says so itself.
                    $editor = Widgets::editor($tui);
                    $this->await(
                        static fn (): bool => null !== $editor->getFilePath(),
                        what: 'the editor to open its file',
                    );

                    // F1 over the Settings window swaps it for the docs.
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $this->frame($tui);
                    $tui->handleInput("\x1bOP");
                    $this->frame($tui);
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Guides', $screen, 'the docs replaced the Settings window');

                    // Click the [Editor] link inside the table: the row reads
                    // '  F1  │ Editor' at doc line 7 (the H1 rule is line 1), two
                    // header rows above the body (the bar and its rule), link
                    // text from column 9 — derive the screen position from
                    // the pane's rect.
                    $rect = Services::resolve($ide, Renderer::class)
                        ->getWidgetRect($tui->getById('help-viewer'));
                    $this->assertInstanceOf(WidgetRect::class, $rect, 'the help viewer pane is laid out');
                    [$linkCol, $linkRow] = [$rect->col + 9 + 1, $rect->row + 2 + 7 + 1]; // 0-based rect → 1-based SGR
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $linkCol, $linkRow));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $linkCol, $linkRow));
                    $this->frame($tui);
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Editor guide', $screen, 'the click followed the link');
                    $this->assertStringContainsString('Documentation › editor', $screen, 'the header breadcrumb shows the trail, root labelled Documentation');

                    // Wheel scrolls the page.
                    $tui->handleInput("\x1b[<65;40;5M");
                    $this->frame($tui);
                },
                // 1100 ms of gap stood here for a rect read that the frame at
                // the end of the step above already produced.
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    // Click the ‹ arrow in the header (row 0, column 1 of the
                    // pane) to go back to the README, then Enter on the https link.
                    $rect = Services::resolve($ide, Renderer::class)
                        ->getWidgetRect($tui->getById('help-viewer'));
                    $this->assertInstanceOf(WidgetRect::class, $rect, 'the help viewer pane is laid out');
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 1 + 1, $rect->row + 1));
                    // 0-based rect → 1-based SGR
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 1 + 1, $rect->row + 1));
                    $this->awaitPainted($tui, $terminal, 'Guides');
                    $this->assertStringContainsString('Guides', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the ‹ arrow navigated back');
                    $tui->handleInput("\x1b[Z");
                    // shift+tab → the last link (Site)
                    $tui->handleInput("\r");
                    $this->frame($tui);
                },
                // And 1200 more in front of a step that opens by awaiting its
                // own effect, so the gap was never what made it safe.
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($captureFile): void {
                    $this->awaitPainted($tui, $terminal, 'Opened https://example.com/x');
                    $this->assertStringContainsString('Opened https://example.com/x', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // The shim runs as an independent OS process, so its write
                    // has to be awaited: the flash above fires when the shim is
                    // SPAWNED, and everything after it is a subprocess waiting
                    // to be scheduled — 3s was a race a loaded machine lost.
                    //
                    // Through await(), not a usleep() loop of its own. The loop
                    // does not have to cooperate for the shim to run, but a
                    // hand-rolled budget is the wrong currency: await() is the
                    // one that grows under coverage and names itself on timeout,
                    // and blocking the loop for ten seconds to wait on a
                    // subprocess is not free for anything else armed on it.
                    $written = static fn (): string => \is_file($captureFile) ? (string) \file_get_contents($captureFile) : '';
                    $this->await(
                        static fn (): bool => \str_contains($written(), 'https://example.com/x'),
                        what: 'the opener shim to write the URL',
                    );
                    $this->assertStringContainsString('https://example.com/x', $written(), 'the opener shim wrote the URL it was handed');

                    // Quit-time session holds no docs page (asserted after the run).
                    Services::resolve($ide, SessionRestorer::class)
                        ->save();
                },
            ],
            docsDir: $docs->dir,
        );

        $session = \json_decode((string) \file_get_contents($project->path('.porta/session.json')), associative: true);
        $paths = \array_column($session['buffers'] ?? [], 'path');
        $this->assertNotContains(\realpath($docs->path('README.md')), $paths, 'the docs are never a buffer to restore');
        $this->assertNotEmpty(\array_filter($paths, static fn (string $p): bool => \str_ends_with($p, 'a.php')), 'the code tab persists');

        $this->addToAssertionCount(1);
    }

    public function testHeaderClicksWorkAfterACodeTabShowedItsBreadcrumbTrail(): void
    {
        $docs = $this->docs();
        $project = new TempProject([
            'src/Foo.php' => "<?php\nnamespace App;\nclass Foo\n{\n    public function bar(): void\n    {\n        \$x = 1;\n    }\n}\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Foo.php'),
            [
                300 => function (Tui $tui): void {
                    // Real caret movement into bar()'s body populates the code
                    // breadcrumbs bar — its rect then covers the row where the
                    // help header will sit after F1 (the bar collapses to zero
                    // rows on help tabs but the renderer keeps the stale rect).
                    for ($i = 0; $i < 6; ++$i) {
                        $tui->handleInput("\x1b[B");
                    }
                    $tui->handleInput("\x1b[C");
                    $this->frame($tui);
                },
                600 => function (Tui $tui, $terminal, Ide $ide): void {
                    $tui->handleInput("\x1bOP"); // F1
                    $this->frame($tui);
                    $tui->handleInput("\t");
                    $tui->handleInput("\r"); // follow [Editor]
                    $this->frame($tui);
                    $viewer = Widgets::byId($tui, 'help-viewer', HelpViewerWidget::class);
                    $this->assertStringEndsWith('editor.md', (string) $viewer->path());

                    // The ‹ arrow must navigate despite the stale breadcrumbs rect.
                    $rect = Services::resolve($ide, Renderer::class)
                        ->getWidgetRect($viewer);
                    $this->assertInstanceOf(WidgetRect::class, $rect);
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 2, $rect->row + 1));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 2, $rect->row + 1));
                    $this->frame($tui);
                    $this->assertStringEndsWith('README.md', (string) $viewer->path(), 'the stale code-breadcrumbs rect must not eat help-header clicks');
                },
            ],
            docsDir: $docs->dir,
        );

        $this->addToAssertionCount(1);
    }

    public function testShippedDocsRenderAndTreeMarkdownStaysEditable(): void
    {
        $project = new TempProject(['notes.md' => "# Notes\n"]);

        $this->runIde(
            $project,
            $project->path('notes.md'),
            [
                300 => function (Tui $tui, $terminal): void {
                    $this->frame($tui);
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('# Notes', $screen, 'a project .md opens as editable source');

                    $tui->handleInput("\x1bOP"); // F1 with no override → the real shipped docs
                    $this->frame($tui);
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Porta documentation', $screen);
                    $this->assertStringNotContainsString('# Porta documentation', $screen, 'rendered, not source');
                },
                600 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    // A page in the internals SUBDIRECTORY renders. Those pages
                    // are reachable only through a folder README, which is also
                    // what gives the header its folder crumb — a flat browser
                    // would leave the whole section unreachable.
                    $docs = \dirname(__DIR__, levels: 2).'/docs';
                    $window = Services::resolve($ide, HelpViewController::class);

                    $window->showPage($docs.'/internals/README.md');
                    $this->awaitPainted($tui, $terminal, 'How Porta is built');

                    $window->showPage($docs.'/internals/decisions.md');
                    $this->awaitPainted($tui, $terminal, 'measured and rejected');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringNotContainsString('# Settled questions', $screen, 'rendered, not source');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testCtrlFFindsWithinTheDocsPage(): void
    {
        $docs = $this->docs();
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function (Tui $tui, $terminal, Ide $ide): void {
                    $tui->handleInput("\x1bOP"); // F1 → the docs page
                    $this->frame($tui);
                    $this->assertSame('help-viewer', $tui->getFocus()?->getId(), 'the docs page is focused');

                    $tui->handleInput("\x06"); // ctrl+f
                    $this->frame($tui);
                    $this->assertSame('help-find', $tui->getFocus()?->getId(), 'Ctrl+F opens the window\'s own find bar');

                    foreach (\str_split('manual') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $this->frame($tui);
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('┃ manual ', $screen);
                    $this->assertStringContainsString('[1/1]', $screen, 'the query matched the rendered docs text');

                    $tui->handleInput("\x1b"); // esc closes the bar
                    $this->frame($tui);
                    $this->assertSame('help-viewer', $tui->getFocus()?->getId(), 'closing returns focus to the docs page');
                    $this->assertTrue(Services::resolve($ide, ViewRegistry::class)->isOpen('help'), 'and leaves the window open');
                },
            ],
            docsDir: $docs->dir,
        );

        $this->addToAssertionCount(1);
    }

    /**
     * The window's own pointer and focus: the wheel scrolls the page, a click
     * on the find bar focuses it, F6 moves between the bar and the page, and
     * closing the window with the bar open closes the bar too.
     */
    public function testTheDocsWindowsPointerAndFocusRing(): void
    {
        $docs = new TempProject([
            'README.md' => "# Guides\n\n".\str_repeat("A line of manual prose.\n\n", times: 40),
        ]);
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $views = Services::resolve($ide, ViewRegistry::class);
                    $views->open('help');
                    $views->open('help'); // already open: nothing changes
                    $this->frame($tui);
                    $page = Widgets::byId($tui, 'help-viewer', HelpViewerWidget::class);
                    $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($page);
                    $this->assertInstanceOf(WidgetRect::class, $rect);

                    // The wheel over the page scrolls it; sideways it is not the page's.
                    $tui->handleInput(\sprintf("\x1b[<65;%d;%dM", $rect->col + 5, $rect->row + 5));
                    $this->frame($tui);
                    $this->assertStringNotContainsString('Guides', \implode("\n", $this->screenGrid($tui, $terminal)), 'scrolled past the heading');
                    $tui->handleInput(\sprintf("\x1b[<67;%d;%dM", $rect->col + 5, $rect->row + 5));

                    // Ctrl+F, then F6 back and forth between the bar and the page.
                    $tui->handleInput("\x06");
                    $this->frame($tui);
                    $this->assertSame('help-find', $tui->getFocus()?->getId());
                    $tui->handleInput("\x1b[17~");
                    $this->assertSame('help-viewer', $tui->getFocus()?->getId(), 'F6 to the page');
                    $tui->handleInput("\x1b[17~");
                    $this->assertSame('help-find', $tui->getFocus()?->getId(), 'and back to the bar');

                    // A click on the bar focuses it; a hover over one of its
                    // buttons lights it, and moving off clears it.
                    $tui->handleInput("\x1b[17~");
                    $this->frame($tui);
                    $bar = Widgets::byId($tui, 'help-find', FindBarWidget::class);
                    $find = Services::resolve($ide, Renderer::class)->getWidgetRect($bar);
                    $this->assertInstanceOf(WidgetRect::class, $find);
                    $button = null;
                    for ($row = 0; $row < $find->rows && null === $button; ++$row) {
                        for ($col = 0; $col < $find->columns && null === $button; ++$col) {
                            $button = $bar->hoverAt($row, $col) ? [$row, $col] : null;
                        }
                    }
                    $this->assertNotNull($button, 'the bar has a button to light');
                    $bar->clearHover();
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $find->col + $button[1] + 1, $find->row + $button[0] + 1));
                    $this->assertFalse($bar->hoverAt($find->row + $button[0] - $find->row, $button[1]), 'the pointer already lit it');
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $rect->col + 5, $rect->row + 8));
                    $this->assertTrue($bar->hoverAt($button[0], $button[1]), 'moving off cleared it');
                    $bar->clearHover();

                    // A press on the window's padding, beside both, is nobody's.
                    $box = Services::resolve($ide, ViewWindowPresenter::class)->box();
                    $this->assertInstanceOf(ModalBox::class, $box);
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $box->col + 10, $box->startRow + 1 + 1));
                    $this->assertTrue($views->isOpen('help'), 'the padding is the window: it stays open');
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $find->col + 3, $find->row + 1));
                    $this->assertSame('help-find', $tui->getFocus()?->getId(), 'the click focused the bar');

                    // A click on the page focuses it.
                    $this->frame($tui);
                    $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($page);
                    $this->assertInstanceOf(WidgetRect::class, $rect);
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 5, $rect->row + 6));
                    $this->assertSame('help-viewer', $tui->getFocus()?->getId(), 'the click focused the page');

                    // Closing the window with the bar open closes the bar with it.
                    $tui->handleInput("\x1bOP"); // F1 toggles it shut
                    $this->assertFalse($views->isOpen('help'));
                    $views->open('help');
                    $this->frame($tui);
                    $this->assertSame('help-viewer', $tui->getFocus()?->getId(), 'reopened on the page, the bar gone');
                    $tui->handleInput("\x1b[17~");
                    $this->assertSame('help-viewer', $tui->getFocus()?->getId(), 'with no bar, F6 stays on the page');
                },
            ],
            docsDir: $docs->dir,
        );

        $this->addToAssertionCount(1);
    }

    /** Docs with no front page: F1 opens no empty window, and says why. */
    public function testF1WithNoFrontPageOpensNothing(): void
    {
        $docs = new TempProject(['editor.md' => "# Editor guide\n"]);
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $tui->handleInput("\x1bOP");

                    $this->assertFalse(Services::resolve($ide, ViewRegistry::class)->isOpen('help'));
                    $this->assertStringContainsString('Cannot read', Toasts::last($ide));
                },
            ],
            docsDir: $docs->dir,
        );

        $this->addToAssertionCount(1);
    }
}
