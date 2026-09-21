<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\History\SessionRestorer;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The F1 help browser: docs render styled in the help viewer (no markdown
 * syntax on screen), Tab cycles the links, Enter/click follows, Backspace
 * goes back, and the help tab coexists with normal buffers.
 */
final class IdeDocsTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

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
                    $this->assertStringContainsString('Key  Guide', $screen, 'the table renders aligned');
                    $this->assertStringContainsString('Documentation', $screen, 'the help tab is in the tab bar, titled Documentation');

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
                    // Ctrl+Right cycles back to the code tab and Ctrl+Left returns.
                    $tui->handleInput("\x1b[1;5C");
                    $this->awaitPainted($tui, $terminal, '<?php');
                    $this->assertStringContainsString('<?php', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the editor pane swapped back in');
                    $tui->handleInput("\x1b[1;5D");
                    $this->awaitPainted($tui, $terminal, 'Guides');
                    $this->assertStringContainsString('Guides', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'and the help tab returns rendered');
                },
                900 => function (Tui $tui, $terminal, Ide $ide): void {
                    $this->frame($tui);
                    // Re-focus the pane with a click on a blank body row, then
                    // follow the link again: still ONE help tab, page swapped in place.
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tui->getById('help-viewer'));
                    $this->assertInstanceOf(WidgetRect::class, $rect, 'the help viewer pane is laid out');
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 1, $rect->row + 4));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 1, $rect->row + 4));
                    $tui->handleInput("\r"); // the focused link is still [Editor] (restored by the earlier back)
                    $this->frame($tui);
                    $paths = \array_map(static fn (Buffer $b): string => $b->path, Services::resolve($ide, BufferManager::class)->all());
                    $this->assertCount(2, $paths, 'navigation reuses the single help tab (a.php + Documentation)');
                    $this->assertStringEndsWith('editor.md', (string) $tui->getById('help-viewer')->path(), 'the page swapped in place');

                    // A round trip through the code tab must not wipe the trail.
                    $tui->handleInput("\x1b[1;5C"); // ctrl+right → a.php
                    $this->frame($tui);
                    $tui->handleInput("\x1b[1;5D"); // ctrl+left → back to the help tab
                    $this->frame($tui);
                    $this->assertStringEndsWith('editor.md', (string) $tui->getById('help-viewer')->path(), 'the navigated page survived the tab switch');
                    $rect = $renderer->getWidgetRect($tui->getById('help-viewer'));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 2, $rect->row + 1)); // the ‹ arrow
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 2, $rect->row + 1));
                    $this->frame($tui);
                    $this->assertStringEndsWith('README.md', (string) $tui->getById('help-viewer')->path(), 'the ‹ arrow still navigates after the round trip');
                },
                1200 => function (Tui $tui, $terminal): void {
                    $this->frame($tui);
                    // F10: the app-menu dropdown composites OVER the help viewer.
                    $tui->handleInput("\x1b[21~");
                    $this->frame($tui);
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Save File', $screen, 'the File dropdown is visible over the help pane');
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
                    $editor = $tui->getById('editor');
                    $this->await(
                        static fn (): bool => null !== $editor->getFilePath(),
                        what: 'the editor to open its file',
                    );

                    // F1 over the settings view closes it and shows the docs.
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $this->frame($tui);
                    $tui->handleInput("\x1bOP");
                    $this->frame($tui);
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Guides', $screen, 'the docs replaced the settings view');

                    // Click the [Editor] link inside the table: the row reads
                    // ' F1   Editor' at doc line 7 (the H1 rule is line 1), one
                    // header row above the body, link text from column 6 —
                    // derive the screen position from the pane's rect.
                    $rect = Services::resolve($ide, Renderer::class)
                        ->getWidgetRect($tui->getById('help-viewer'));
                    $this->assertInstanceOf(WidgetRect::class, $rect, 'the help viewer pane is laid out');
                    [$linkCol, $linkRow] = [$rect->col + 6 + 1, $rect->row + 1 + 7 + 1]; // 0-based rect → 1-based SGR
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

                    // Quit-time session excludes the help tab (asserted after the run).
                    Services::resolve($ide, SessionRestorer::class)
                        ->save();
                },
            ],
            docsDir: $docs->dir,
        );

        $session = \json_decode((string) \file_get_contents($project->path('.porta/session.json')), associative: true);
        $paths = \array_column($session['buffers'] ?? [], 'path');
        $this->assertNotContains(\realpath($docs->path('README.md')), $paths, 'help tabs never restore (they would reopen as source)');
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
                    $viewer = $tui->getById('help-viewer');
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
                    $buffers = Services::resolve($ide, BufferService::class);
                    $this->assertInstanceOf(BufferService::class, $buffers, 'the IDE exposes a BufferService');

                    $buffers->showHelpPage($docs.'/internals/README.md');
                    $this->awaitPainted($tui, $terminal, 'Settled questions');

                    $buffers->showHelpPage($docs.'/internals/decisions.md');
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
                300 => function (Tui $tui, $terminal): void {
                    $tui->handleInput("\x1bOP"); // F1 → the docs page
                    $this->frame($tui);
                    $this->assertSame('help-viewer', $tui->getFocus()?->getId(), 'the docs page is focused');

                    $tui->handleInput("\x06"); // ctrl+f
                    $this->frame($tui);
                    $this->assertSame('find-bar', $tui->getFocus()?->getId(), 'Ctrl+F opens the find bar over the docs');

                    foreach (\str_split('manual') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $this->frame($tui);
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Find: manual', $screen);
                    $this->assertStringContainsString('[1/1]', $screen, 'the query matched the rendered docs text');

                    $tui->handleInput("\x1b"); // esc closes the bar
                    $this->frame($tui);
                    $this->assertSame('help-viewer', $tui->getFocus()?->getId(), 'closing returns focus to the docs page');
                },
            ],
            docsDir: $docs->dir,
        );

        $this->addToAssertionCount(1);
    }
}
