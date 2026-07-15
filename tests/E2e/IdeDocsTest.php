<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The F1 help browser: docs render styled in the help viewer (no markdown
 * syntax on screen), Tab cycles the links, Enter/click follows, Backspace
 * goes back, and the help tab coexists with normal buffers.
 */
final class IdeDocsTest extends TestCase
{
    use RunsIde;

    private string $originalPath;

    protected function setUp(): void
    {
        $this->originalPath = (string) getenv('PATH');
        putenv('PORTA_LSP');
        putenv('PATH=/nonexistent');
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
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
                300 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1bOP"); // F1
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Guides', $screen, 'the heading text renders');
                    $this->assertStringNotContainsString('# Guides', $screen, 'without markdown syntax');
                    $this->assertStringNotContainsString('](', $screen);
                    $this->assertStringNotContainsString('**', $screen);
                    $this->assertStringContainsString('Key  Guide', $screen, 'the table renders aligned');
                    $this->assertStringContainsString('Documentation', $screen, 'the help tab is in the tab bar, titled Documentation');

                    // Typing does not edit anything — there is no document behind the page.
                    $tui->handleInput('x');
                    $tui->tick();
                    $this->assertStringNotContainsString('xGuides', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
                600 => function ($tui, $terminal) use ($docs): void {
                    $tui->handleInput("\t"); // focus the [Editor] link
                    $tui->handleInput("\r"); // follow it
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Editor guide', $screen, 'the linked page rendered');
                    $this->assertStringContainsString('plain prose here', $screen);

                    $tui->handleInput("\x7f"); // Backspace → back to the README
                    $tui->tick();
                    $this->assertStringContainsString('Guides', AnsiUtils::stripAnsiCodes($terminal->getOutput()));

                    $tui->handleInput("\x7f"); // history exhausted
                    $tui->tick();
                    $this->assertStringContainsString('Nowhere to go back to', AnsiUtils::stripAnsiCodes($terminal->getOutput()));

                    // Ctrl+Right cycles back to the code tab and Ctrl+Left returns.
                    $tui->handleInput("\x1b[1;5C");
                    $tui->tick();
                    $this->assertStringContainsString('<?php', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the editor pane swapped back in');
                    $tui->handleInput("\x1b[1;5D");
                    $tui->tick();
                    $this->assertStringContainsString('Guides', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'and the help tab returns rendered');

                    // Go-to-file stays reachable from the help tab.
                    $tui->handleInput("\x10"); // ctrl+p
                    $tui->tick();
                    $this->assertSame('goto-file', $tui->getFocus()?->getId(), 'the go-to-file palette opened over the help tab');
                    $tui->handleInput("\x1b");
                },
                900 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    // Re-focus the pane with a click on a blank body row, then
                    // follow the link again: still ONE help tab, page swapped in place.
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tui->getById('help-viewer'));
                    $this->assertNotNull($rect, 'the help viewer pane is laid out');
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 1, $rect->row + 4));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 1, $rect->row + 4));
                    $tui->handleInput("\r"); // the focused link is still [Editor] (restored by the earlier back)
                    $tui->tick();
                    $paths = array_map(static fn ($b): string => $b->path, new \ReflectionProperty($ide, 'buffers')->getValue($ide)->all());
                    $this->assertCount(2, $paths, 'navigation reuses the single help tab (a.php + Documentation)');
                    $this->assertStringEndsWith('editor.md', (string) $tui->getById('help-viewer')->path(), 'the page swapped in place');

                    // A round trip through the code tab must not wipe the trail.
                    $tui->handleInput("\x1b[1;5C"); // ctrl+right → a.php
                    $tui->tick();
                    $tui->handleInput("\x1b[1;5D"); // ctrl+left → back to the help tab
                    $tui->tick();
                    $this->assertStringEndsWith('editor.md', (string) $tui->getById('help-viewer')->path(), 'the navigated page survived the tab switch');
                    $rect = $renderer->getWidgetRect($tui->getById('help-viewer'));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 2, $rect->row + 1)); // the ‹ arrow
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 2, $rect->row + 1));
                    $tui->tick();
                    $this->assertStringEndsWith('README.md', (string) $tui->getById('help-viewer')->path(), 'the ‹ arrow still navigates after the round trip');
                },
                1200 => function ($tui, $terminal): void {
                    $tui->tick();
                    // F10: the app-menu dropdown composites OVER the help viewer.
                    $tui->handleInput("\x1b[21~");
                    $tui->tick();
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
            $shim = $project->write('shim-bin/'.$name, "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> ".escapeshellarg($captureFile)."\n");
            chmod($shim, permissions: 0o755);
        }
        putenv('PATH='.$project->path('shim-bin'));

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function ($tui, $terminal, $ide): void {
                    // F1 over the settings view closes it and shows the docs.
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_settings');
                    $tui->tick();
                    $tui->handleInput("\x1bOP");
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Guides', $screen, 'the docs replaced the settings view');

                    // Click the [Editor] link inside the table: the row reads
                    // ' F1   Editor' at doc line 7 (the H1 rule is line 1), one
                    // header row above the body, link text from column 6 —
                    // derive the screen position from the pane's rect.
                    $rect = new \ReflectionProperty($ide, 'renderer')
                        ->getValue($ide)
                        ->getWidgetRect($tui->getById('help-viewer'));
                    $this->assertNotNull($rect, 'the help viewer pane is laid out');
                    [$linkCol, $linkRow] = [$rect->col + 6 + 1, $rect->row + 1 + 7 + 1]; // 0-based rect → 1-based SGR
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $linkCol, $linkRow));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $linkCol, $linkRow));
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Editor guide', $screen, 'the click followed the link');
                    $this->assertStringContainsString('Documentation › editor', $screen, 'the header breadcrumb shows the trail, root labelled Documentation');

                    // Wheel scrolls the page.
                    $tui->handleInput("\x1b[<65;40;5M");
                    $tui->tick();
                },
                1400 => function ($tui, $terminal, $ide) use ($captureFile): void {
                    // Click the ‹ arrow in the header (row 0, column 1 of the
                    // pane) to go back to the README, then Enter on the https link.
                    $rect = new \ReflectionProperty($ide, 'renderer')
                        ->getValue($ide)
                        ->getWidgetRect($tui->getById('help-viewer'));
                    $this->assertNotNull($rect, 'the help viewer pane is laid out');
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 1 + 1, $rect->row + 1)); // 0-based rect → 1-based SGR
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 1 + 1, $rect->row + 1));
                    $tui->tick(); // render the page so its links are cycle-able
                    $this->assertStringContainsString('Guides', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the ‹ arrow navigated back');
                    $tui->handleInput("\x1b[Z"); // shift+tab → the last link (Site)
                    $tui->handleInput("\r");
                    $tui->tick();
                },
                2600 => function ($tui, $terminal, $ide) use ($captureFile, $docs): void {
                    $tui->tick();
                    $this->assertStringContainsString('Opened https://example.com/x', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // The shim runs as an independent OS process — poll for its
                    // write instead of racing it at a fixed tick (blocking here
                    // is fine: the shim needs no event-loop cooperation).
                    $deadline = microtime(true) + 3.0;
                    while (!str_contains((string) (is_file($captureFile) ? file_get_contents($captureFile) : false), 'https://example.com/x') && microtime(true) < $deadline) {
                        usleep(20_000);
                    }
                    $this->assertStringContainsString('https://example.com/x', (string) file_get_contents($captureFile));

                    // Quit-time session excludes the help tab (asserted after the run).
                    new \ReflectionProperty($ide, 'sessionRestorer')
                        ->getValue($ide)
                        ->save();
                },
            ],
            docsDir: $docs->dir,
        );

        $session = json_decode((string) file_get_contents($project->path('.porta/session.json')), associative: true);
        $paths = array_column($session['buffers'] ?? [], 'path');
        $this->assertNotContains(realpath($docs->path('README.md')), $paths, 'help tabs never restore (they would reopen as source)');
        $this->assertNotEmpty(array_filter($paths, static fn (string $p): bool => str_ends_with($p, 'a.php')), 'the code tab persists');

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
                300 => static function ($tui): void {
                    // Real caret movement into bar()'s body populates the code
                    // breadcrumbs bar — its rect then covers the row where the
                    // help header will sit after F1 (the bar collapses to zero
                    // rows on help tabs but the renderer keeps the stale rect).
                    for ($i = 0; $i < 6; ++$i) {
                        $tui->handleInput("\x1b[B");
                    }
                    $tui->handleInput("\x1b[C");
                    $tui->tick();
                },
                600 => function ($tui, $terminal, $ide): void {
                    $tui->handleInput("\x1bOP"); // F1
                    $tui->tick();
                    $tui->handleInput("\t");
                    $tui->handleInput("\r"); // follow [Editor]
                    $tui->tick();
                    $viewer = $tui->getById('help-viewer');
                    $this->assertStringEndsWith('editor.md', (string) $viewer->path());

                    // The ‹ arrow must navigate despite the stale breadcrumbs rect.
                    $rect = new \ReflectionProperty($ide, 'renderer')
                        ->getValue($ide)
                        ->getWidgetRect($viewer);
                    $this->assertNotNull($rect);
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 2, $rect->row + 1));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 2, $rect->row + 1));
                    $tui->tick();
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
                300 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('# Notes', $screen, 'a project .md opens as editable source');

                    $tui->handleInput("\x1bOP"); // F1 with no override → the real shipped docs
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Porta documentation', $screen);
                    $this->assertStringNotContainsString('# Porta documentation', $screen, 'rendered, not source');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
