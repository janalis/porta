<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\RecordingHelpViewerHandler;
use Porta\Tests\Support\TempProject;
use Porta\Widget\HelpViewerWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class HelpViewerWidgetTest extends TestCase
{
    private TempProject $docs;
    private RecordingHelpViewerHandler $handler;

    private function viewer(): HelpViewerWidget
    {
        $this->docs = new TempProject([
            'README.md' =>
                "# Guides\n\nSee [Editor](editor.md) and [Porta](https://porta.dev) and [demo](demo.php) and [gone](missing.md).\n\n"
                    .implode("\n\n", array_map(static fn (int $i): string => "Paragraph {$i}.", range(1, end: 30)))
                    ."\n\n[Bottom](editor.md)\n",
            'editor.md' => "# Editor guide\n\nBack home: [Home](README.md)\n",
            'demo.php' => "<?php\n",
        ]);
        $viewer = new HelpViewerWidget();
        $viewer->setFocused(true);
        $this->handler = new RecordingHelpViewerHandler();
        $this->handler->onOpenHelp = static function (string $path) use ($viewer): void {
            $viewer->show($path, (string) file_get_contents($path)); // mirror the IDE's synchronous re-show
        };
        $viewer->setHandler($this->handler);
        $viewer->setDocsRoot($this->docs->dir);
        $viewer->show($this->docs->path('README.md'), (string) file_get_contents($this->docs->path('README.md')));

        return $viewer;
    }

    /** @return list<string> */
    private function screen(HelpViewerWidget $viewer, int $cols = 60, int $rows = 10): array
    {
        return array_map(AnsiUtils::stripAnsiCodes(...), $viewer->render(new RenderContext($cols, $rows)));
    }

    public function testRendersStyledPageAndScrolls(): void
    {
        $viewer = $this->viewer();
        $screen = $this->screen($viewer);

        $this->assertStringContainsString('‹ ›', $screen[0], 'the navigation header leads');
        $this->assertStringContainsString('README', $screen[0], 'with the current page as its crumb');
        $this->assertSame('Guides', rtrim($screen[1]), 'the heading renders without markdown syntax');
        $this->assertSame('══════', rtrim($screen[2]), 'an H1 gets a title-width rule');
        $this->assertStringNotContainsString('](', implode("\n", $screen));

        $viewer->scrollBy(6);
        $this->assertStringNotContainsString('Guides', implode("\n", $this->screen($viewer)));
        $viewer->scrollBy(-\PHP_INT_MAX);
        $this->assertSame('Guides', rtrim($this->screen($viewer)[1]));
        $viewer->scrollBy(\PHP_INT_MAX);
        $this->assertStringContainsString('Paragraph 30.', implode("\n", $this->screen($viewer)), 'end clamps to the last page');
    }

    public function testTabCyclesLinksHighlightsAndScrollsIntoView(): void
    {
        $viewer = $this->viewer();
        $this->screen($viewer); // establish width

        $viewer->handleInput("\t");
        $line = $viewer->render(new RenderContext(60, 10))[4] ?? '';
        $this->assertStringContainsString("\x1b[48;5;31m", $line, 'the focused link is painted with the selection colors');
        $this->assertStringContainsString('Editor', AnsiUtils::stripAnsiCodes($line));
        $this->assertStringContainsString("Editor\x1b[0m and ", $line, 'the text after the focused link keeps its own (plain) style — no link-style bleed');

        $viewer->handleInput("\t"); // Porta
        $viewer->handleInput("\t"); // demo
        $viewer->handleInput("\t"); // gone
        $viewer->handleInput("\t"); // wraps to Editor
        $viewer->handleInput("\r");
        $this->assertSame(['help:editor.md'], $this->handler->events, 'Tab wraps and Enter follows the focused link');

        // On the new page, scroll to the bottom then Tab: the link scrolls into view.
        $viewer->handleInput("\x1b[1;2Z"); // unbound noise is ignored
        $viewer->scrollBy(\PHP_INT_MAX);
        $viewer->handleInput("\t");
        $screen = implode("\n", $this->screen($viewer));
        $this->assertStringContainsString('Home', $screen, 'cycling scrolled the focused link into view');
    }

    public function testABlurredPaneDimsTheFocusedLink(): void
    {
        $viewer = $this->viewer();
        $this->screen($viewer); // establish width
        $viewer->handleInput("\t"); // focus the first link

        $viewer->setFocused(false);
        $line = $viewer->render(new RenderContext(60, 10))[4] ?? '';
        $this->assertStringContainsString("\x1b[48;5;237m", $line, 'a blurred pane dims the focused link (SelectedRowUnfocused)');
        $this->assertStringNotContainsString("\x1b[48;5;31m", $line, 'not the bright focused shade');
    }

    public function testCyclingScrollsDownToABottomLinkAndBackUp(): void
    {
        $viewer = $this->viewer();
        $this->screen($viewer);

        // Tab to the last link (far below the viewport) → scrolls down…
        for ($i = 0; $i < 5; ++$i) {
            $viewer->handleInput("\t");
        }
        $this->assertStringContainsString('Bottom', implode("\n", $this->screen($viewer)), 'the focused bottom link scrolled into view');

        // …and cycling onward wraps to the top links → scrolls back up.
        $viewer->handleInput("\t");
        $this->assertStringContainsString('See Editor', implode("\n", $this->screen($viewer)), 'wrapping scrolled the first link back into view');
    }

    public function testForwardArrowRoundTripAndFreshFollowsClearIt(): void
    {
        $viewer = $this->viewer();
        $this->screen($viewer);

        // Navigate away with some scroll state, then back.
        $viewer->scrollBy(2);
        $viewer->handleInput("\t"); // Editor (scrolls back into view)
        $viewer->handleInput("\r");
        $this->screen($viewer);
        $viewer->handleInput("\x7f"); // back → README
        $this->screen($viewer);

        // The › arrow re-enters editor.md.
        $this->assertTrue($viewer->clickAt(0, 3), 'the forward arrow zone is active after a back');
        $this->assertTrue($viewer->clickAt(0, 3), 'a stale › zone still swallows the click');
        $this->assertSame(['help:editor.md', 'help:README.md', 'help:editor.md'], $this->handler->events, 'an emptied forward stack navigates nowhere');
        $this->screen($viewer);

        // A fresh page follow clears the forward trail: › becomes inert.
        $viewer->handleInput("\x7f"); // back to README again (forward re-armed)
        $this->screen($viewer);
        $viewer->handleInput("\x1b[Z"); // shift+tab from the restored focus → "Bottom" (a page link)
        $viewer->handleInput("\r");
        $this->screen($viewer);
        $this->assertFalse($viewer->clickAt(0, 3), 'a page follow cleared the forward trail');
    }

    public function testBreadcrumbMirrorsTheFileHierarchyAndTheRootCrumbNavigates(): void
    {
        $viewer = $this->viewer();

        // On the root page the trail is just the root crumb; clicking the
        // current page scrolls back to the top instead of navigating.
        $this->assertStringNotContainsString('›  README ›', $this->screen($viewer)[0]);
        $viewer->scrollBy(6);
        $this->assertStringNotContainsString('Guides', implode("\n", $this->screen($viewer)));
        $this->assertTrue($viewer->clickAt(0, 6), 'the current-page crumb is a scroll-to-top target');
        $this->assertSame([], $this->handler->events, 'without navigating anywhere');
        $this->assertStringContainsString('Guides', implode("\n", $this->screen($viewer)), 'the page scrolled back to the top');

        // A page under the root shows root › page; only the root is clickable.
        $viewer->handleInput("\t");
        $viewer->handleInput("\r"); // → editor
        $this->assertStringContainsString('README › editor', $this->screen($viewer)[0]);
        $this->assertTrue($viewer->clickAt(0, 6), 'the root crumb starts right after the arrows');
        $this->assertSame(['help:editor.md', 'help:README.md'], $this->handler->events, 'the crumb click navigated home');
        $this->assertSame(realpath($this->docs->path('README.md')), $viewer->path());

        // A crumb click is a fresh navigation: back re-enters the page, forward is cleared.
        $this->screen($viewer);
        $this->assertFalse($viewer->clickAt(0, 3), 'no forward trail after a crumb navigation');
        $this->assertTrue($viewer->clickAt(0, 1), 'the ‹ arrow is armed by the crumb navigation');
        $this->assertStringEndsWith('editor.md', (string) $viewer->path());
    }

    public function testArrowZonesArePaddedAroundTheGlyphs(): void
    {
        $viewer = $this->viewer();
        $this->screen($viewer);
        $viewer->handleInput("\t");
        $viewer->handleInput("\r"); // → editor (history armed)
        $this->screen($viewer);

        $this->assertTrue($viewer->clickAt(0, 0), 'a near-miss left of ‹ still goes back');
        $this->screen($viewer);
        $this->assertTrue($viewer->clickAt(0, 4), 'a near-miss right of › still goes forward');
        $this->assertSame(['help:editor.md', 'help:README.md', 'help:editor.md'], $this->handler->events);
    }

    public function testFolderCrumbsClickableIndexesInertOtherwiseAndTruncationKeepsTheTail(): void
    {
        $viewer = $this->viewer();
        $this->docs->write('guide/README.md', "# Guide index\n");
        $this->docs->write('guide/sub/deep.md', "# Deep page\n");
        $viewer->show($this->docs->path('guide/sub/deep.md'), "# Deep page\n");

        $this->assertFalse($viewer->clickAt(0, 1), 'the ‹ arrow is inert with no history');
        $this->assertFalse($viewer->clickAt(0, 3), 'the › arrow is inert with no forward');

        // ' ‹ ›  README › guide › sub › deep' — folders mirror the file tree.
        $header = $this->screen($viewer)[0];
        $this->assertStringContainsString('README › guide › sub › deep', $header);
        $this->assertFalse($viewer->clickAt(0, 23), 'a folder without a README.md index is not a link');
        $this->assertTrue($viewer->clickAt(0, 15), 'a folder with a README.md index is');
        $this->assertStringEndsWith('guide/README.md', (string) $viewer->path());
        $this->assertStringContainsString('README › guide', $this->screen($viewer)[0]);
        $this->assertStringNotContainsString('guide › README', $this->screen($viewer)[0], "a folder's index page wears the folder's name");

        // Narrow pane: the left-most crumbs collapse, the current page stays.
        $viewer->show($this->docs->path('guide/sub/deep.md'), "# Deep page\n");
        $narrow = $this->screen($viewer, 24, 10)[0];
        $this->assertStringContainsString('…', $narrow, 'left-most crumbs collapse behind an ellipsis');
        $this->assertStringContainsString('deep', $narrow, 'the current page stays visible');
    }

    public function testClickBeforeAnyRenderAndZeroSizedRenders(): void
    {
        $viewer = $this->viewer();
        $this->assertFalse($viewer->clickAt(0, 0), 'nothing rendered yet — no spans to hit');
        $this->assertFalse($viewer->clickAt(1, 0), 'no rendered page — a body click misses');
        $this->assertSame([], $viewer->render(new RenderContext(0, 0)), 'a zero-sized pane renders nothing');
    }

    public function testShiftTabCyclesBackwardsFromTheEnd(): void
    {
        $viewer = $this->viewer();
        $this->screen($viewer);

        $viewer->handleInput("\x1b[Z"); // shift+tab from none → the last link ("Bottom")
        $viewer->handleInput("\x1b[Z"); // → "gone"
        $viewer->handleInput("\r");
        $this->assertSame(['missing:missing.md'], $this->handler->events);
    }

    public function testFollowRoutesUrlsFilesAndMissingTargets(): void
    {
        $viewer = $this->viewer();
        $this->screen($viewer);

        $viewer->handleInput("\t"); // Editor
        $viewer->handleInput("\t"); // Porta (url)
        $viewer->handleInput("\r");
        $viewer->handleInput("\t"); // demo.php (file)
        $viewer->handleInput("\r");
        $this->assertSame(['url:https://porta.dev', 'file:demo.php'], $this->handler->events);

        $viewer->handleInput("\r"); // Enter with no doc change keeps working
        $this->assertCount(3, $this->handler->events);
    }

    public function testBackRestoresThePreviousPageScrollAndFocus(): void
    {
        $viewer = $this->viewer();
        $this->screen($viewer);

        $viewer->handleInput("\t"); // focus "Editor"
        $viewer->handleInput("\r"); // follow → editor.md
        $this->assertStringContainsString('Editor guide', implode("\n", $this->screen($viewer)));

        $viewer->handleInput("\x7f"); // Backspace → back to the README
        $this->assertSame(['help:editor.md', 'help:README.md'], $this->handler->events);
        $this->assertStringContainsString('Guides', implode("\n", $this->screen($viewer)));

        $viewer->handleInput("\x7f"); // history exhausted
        $this->assertContains('back-empty', $this->handler->events);
    }

    public function testClickFollowsLinksAndMissesJustReportFalse(): void
    {
        $viewer = $this->viewer();
        $this->screen($viewer);

        $this->assertFalse($viewer->clickAt(1, 2), 'plain text is not a link');
        $this->assertFalse($viewer->clickAt(0, 30), 'header space between zones is inert');

        // Doc line 3 holds the links → body row 4 with the header offset.
        $this->assertTrue($viewer->clickAt(4, 4), 'a click inside a link span follows it');
        $this->assertSame(['help:editor.md'], $this->handler->events);
    }

    public function testResizeReRendersAndClampsState(): void
    {
        $viewer = $this->viewer();
        $this->screen($viewer, 100, 10);
        $viewer->scrollBy(\PHP_INT_MAX);

        $narrow = $this->screen($viewer, 40, 10);
        $this->assertLessThanOrEqual(40, max(array_map(mb_strwidth(...), $narrow)), 'narrower width re-wraps');
        $this->assertStringContainsString('Paragraph 30.', implode("\n", $narrow).implode("\n", $this->screen($viewer, 40, 10)), 'scroll stays clamped in range');
    }

    public function testTabManagementKeysFireCallbacksAndClearResets(): void
    {
        $viewer = $this->viewer();
        $viewer->handleInput("\x1b[1;5C"); // ctrl+right
        $viewer->handleInput("\x1b[1;5D"); // ctrl+left
        $viewer->handleInput("\x07"); // unbound
        $viewer->handleInput("\x1bw"); // alt+w
        $viewer->handleInput("\x10"); // ctrl+p
        $viewer->handleInput("\x1b\x13"); // ctrl+alt+s
        $this->assertSame(['cycle:1', 'cycle:-1', 'close', 'goto', 'save-all'], $this->handler->events);

        $this->assertSame(realpath($this->docs->path('README.md')), $viewer->path());
        $viewer->clear();
        $this->assertNull($viewer->path());
        $viewer->handleInput("\x7f");
        $this->assertContains('back-empty', $this->handler->events, 'clear() dropped the history');
    }

    public function testRefreshThemeDropsTheRenderedMemo(): void
    {
        $viewer = $this->viewer();
        $before = $viewer->render(new RenderContext(60, 10));
        $viewer->refreshTheme();
        $this->assertSame($before, $viewer->render(new RenderContext(60, 10)), 'same theme → same output, freshly rendered');
    }
}
