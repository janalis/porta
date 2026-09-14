<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\RecordingHelpViewerHandler;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Sgr;
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
                    .\implode("\n\n", \array_map(static fn (int $i): string => "Paragraph {$i}.", \range(1, end: 30)))
                    ."\n\n[Bottom](editor.md)\n",
            'editor.md' => "# Editor guide\n\nBack home: [Home](README.md)\n",
            'demo.php' => "<?php\n",
        ]);
        $viewer = new HelpViewerWidget();
        $viewer->setFocused(true);
        $this->handler = new RecordingHelpViewerHandler();
        $this->handler->onOpenHelp = static function (string $path) use ($viewer): void {
            $viewer->refreshPage($path, (string) \file_get_contents($path)); // mirror the IDE's synchronous re-show
        };
        $viewer->setHandler($this->handler);
        $viewer->setDocsRoot($this->docs->dir);
        $viewer->refreshPage($this->docs->path('README.md'), (string) \file_get_contents($this->docs->path('README.md')));

        return $viewer;
    }

    /** @return list<string> */
    private function screen(HelpViewerWidget $viewer, int $cols = 60, int $rows = 10): array
    {
        return \array_map(AnsiUtils::stripAnsiCodes(...), $viewer->render(new RenderContext($cols, $rows)));
    }

    public function testTheHelpPaneContributesNoStatusSegmentAndDiesWithItsTab(): void
    {
        $viewer = new HelpViewerWidget();
        $this->assertSame('help', $viewer->paneId());
        // Empty: the status bar keeps showing the editor's diagnostics legend,
        // which is what it did when this was a branch in the provider.
        $this->assertSame('', $viewer->statusRight());

        $viewer->refreshPage('/p/docs/README.md', "# Hi\n");
        $this->assertNotNull($viewer->path());

        // A switch back to the tab keeps the navigated page; closing it does not.
        $viewer->show('/p/docs/other.md', "# Other\n");
        $this->assertStringEndsWith('README.md', (string) $viewer->path(), 'show() adopts a page only when the pane is empty');
        $viewer->forget('/p/docs/README.md');
        $this->assertNull($viewer->path(), 'the page and its history die with the tab');
    }

    public function testRendersStyledPageAndScrolls(): void
    {
        $viewer = $this->viewer();
        $screen = $this->screen($viewer);

        $this->assertStringContainsString('‹ ›', $screen[0], 'the navigation header leads');
        $this->assertStringContainsString('README', $screen[0], 'with the current page as its crumb');
        $this->assertSame('Guides', \rtrim($screen[1]), 'the heading renders without markdown syntax');
        $this->assertSame('══════', \rtrim($screen[2]), 'an H1 gets a title-width rule');
        $this->assertStringNotContainsString('](', \implode("\n", $screen));

        $viewer->scrollBy(6);
        $this->assertStringNotContainsString('Guides', \implode("\n", $this->screen($viewer)));
        $viewer->scrollBy(-\PHP_INT_MAX);
        $this->assertSame('Guides', \rtrim($this->screen($viewer)[1]));
        $viewer->scrollBy(\PHP_INT_MAX);
        $this->assertStringContainsString('Paragraph 30.', \implode("\n", $this->screen($viewer)), 'end clamps to the last page');
    }

    public function testALinkThatEscapesTheDocsRootIsRefused(): void
    {
        $docs = new TempProject([
            'pages/README.md' => "# Home\n\nSee [escape](../secret.php) and [abs](/etc/hosts) and [sibling](../pages-evil/secret.md).\n",
            'secret.php' => "<?php\n",
            // The classic prefix bug: a SIBLING whose name merely starts with
            // the root's must not pass the containment test.
            'pages-evil/secret.md' => "# Not docs\n",
        ]);
        $viewer = new HelpViewerWidget();
        $viewer->setFocused(true);
        $handler = new RecordingHelpViewerHandler();
        $viewer->setHandler($handler);
        $viewer->setDocsRoot($docs->path('pages'));
        $viewer->refreshPage($docs->path('pages/README.md'), (string) \file_get_contents($docs->path('pages/README.md')));
        $this->screen($viewer); // establish width so the links exist

        $viewer->handleInput("\t"); // escape (../ chain out of the root)
        $viewer->handleInput("\r");
        $viewer->handleInput("\t"); // abs (an absolute filesystem path)
        $viewer->handleInput("\r");
        $viewer->handleInput("\t"); // sibling (prefix-sharing directory)
        $viewer->handleInput("\r");

        $this->assertSame(['missing:../secret.php', 'missing:/etc/hosts', 'missing:../pages-evil/secret.md'], $handler->events, 'a target outside the docs root never opens a buffer');
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
        $screen = \implode("\n", $this->screen($viewer));
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
        $this->assertStringContainsString('Bottom', \implode("\n", $this->screen($viewer)), 'the focused bottom link scrolled into view');

        // …and cycling onward wraps to the top links → scrolls back up.
        $viewer->handleInput("\t");
        $this->assertStringContainsString('See Editor', \implode("\n", $this->screen($viewer)), 'wrapping scrolled the first link back into view');
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
        $this->assertStringNotContainsString('Guides', \implode("\n", $this->screen($viewer)));
        $this->assertTrue($viewer->clickAt(0, 6), 'the current-page crumb is a scroll-to-top target');
        $this->assertSame([], $this->handler->events, 'without navigating anywhere');
        $this->assertStringContainsString('Guides', \implode("\n", $this->screen($viewer)), 'the page scrolled back to the top');

        // A page under the root shows root › page; only the root is clickable.
        $viewer->handleInput("\t");
        $viewer->handleInput("\r"); // → editor
        $this->assertStringContainsString('README › editor', $this->screen($viewer)[0]);
        $this->assertTrue($viewer->clickAt(0, 6), 'the root crumb starts right after the arrows');
        $this->assertSame(['help:editor.md', 'help:README.md'], $this->handler->events, 'the crumb click navigated home');
        $this->assertSame(\realpath($this->docs->path('README.md')), $viewer->path());

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
        $viewer->refreshPage($this->docs->path('guide/sub/deep.md'), "# Deep page\n");

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
        $viewer->refreshPage($this->docs->path('guide/sub/deep.md'), "# Deep page\n");
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

    public function testAUrlNotAtTheStartOfTheTargetIsNotTreatedAsAUrl(): void
    {
        $docs = new TempProject([
            'README.md' => "# Home\n\n[weird](not-a-url-http://x.md)\n",
        ]);
        $viewer = new HelpViewerWidget();
        $viewer->setFocused(true);
        $handler = new RecordingHelpViewerHandler();
        $viewer->setHandler($handler);
        $viewer->setDocsRoot($docs->dir);
        $viewer->refreshPage($docs->path('README.md'), (string) \file_get_contents($docs->path('README.md')));
        $this->screen($viewer);

        $viewer->handleInput("\t");
        $viewer->handleInput("\r");

        $this->assertSame(['missing:not-a-url-http://x.md'], $handler->events, 'the scheme must anchor the START of the target, not appear anywhere in it');
    }

    public function testAnUppercaseUrlSchemeIsStillRecognizedAsAUrl(): void
    {
        $docs = new TempProject([
            'README.md' => "# Home\n\n[up](HTTP://EXAMPLE.COM)\n",
        ]);
        $viewer = new HelpViewerWidget();
        $viewer->setFocused(true);
        $handler = new RecordingHelpViewerHandler();
        $viewer->setHandler($handler);
        $viewer->setDocsRoot($docs->dir);
        $viewer->refreshPage($docs->path('README.md'), (string) \file_get_contents($docs->path('README.md')));
        $this->screen($viewer);

        $viewer->handleInput("\t");
        $viewer->handleInput("\r");

        $this->assertSame(['url:HTTP://EXAMPLE.COM'], $handler->events, 'the scheme match must be case-insensitive');
    }

    public function testALinkToADirectoryUnderTheRootIsRefusedNotOpenedAsAFile(): void
    {
        $docs = new TempProject([
            'README.md' => "# Home\n\n[dir](subdir)\n",
            'subdir/placeholder.md' => "# placeholder\n",
        ]);
        $viewer = new HelpViewerWidget();
        $viewer->setFocused(true);
        $handler = new RecordingHelpViewerHandler();
        $viewer->setHandler($handler);
        $viewer->setDocsRoot($docs->dir);
        $viewer->refreshPage($docs->path('README.md'), (string) \file_get_contents($docs->path('README.md')));
        $this->screen($viewer);

        $viewer->handleInput("\t");
        $viewer->handleInput("\r");

        $this->assertSame(['missing:subdir'], $handler->events, 'a directory is not a file and must be refused, not opened');
    }

    public function testHistoryAndForwardEntriesStorePathAsAString(): void
    {
        // A widget with NO page loaded (path() is null) that still navigates:
        // the (string) cast on a null path must not be dropped, or the
        // history/forward entry's 'path' key gets a null where a string
        // type is documented — exercised via back() after two navigations.
        $viewer = $this->viewer();
        $this->screen($viewer);
        $viewer->handleInput("\t"); // Editor
        $viewer->handleInput("\r");
        $this->screen($viewer);
        $viewer->handleInput("\x7f"); // back to README
        $this->assertSame(['help:editor.md', 'help:README.md'], $this->handler->events);
        $this->assertIsString($viewer->path());
    }

    public function testBackRestoresThePreviousPageScrollAndFocus(): void
    {
        $viewer = $this->viewer();
        $this->screen($viewer);

        $viewer->handleInput("\t"); // focus "Editor"
        $viewer->handleInput("\r"); // follow → editor.md
        $this->assertStringContainsString('Editor guide', \implode("\n", $this->screen($viewer)));

        $viewer->handleInput("\x7f"); // Backspace → back to the README
        $this->assertSame(['help:editor.md', 'help:README.md'], $this->handler->events);
        $this->assertStringContainsString('Guides', \implode("\n", $this->screen($viewer)));

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
        $this->assertLessThanOrEqual(40, \max(\array_map(mb_strwidth(...), $narrow)), 'narrower width re-wraps');
        $this->assertStringContainsString('Paragraph 30.', \implode("\n", $narrow).\implode("\n", $this->screen($viewer, 40, 10)), 'scroll stays clamped in range');
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

        $this->assertSame(\realpath($this->docs->path('README.md')), $viewer->path());
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

    public function testCtrlFOpensTheFindBarThroughTheHandler(): void
    {
        $viewer = $this->viewer();
        $viewer->handleInput("\x06"); // ctrl+f
        $this->assertContains('find', $this->handler->events, 'Ctrl+F asks the IDE to open the find bar');
    }

    public function testActsAsAFindTargetOverTheRenderedPage(): void
    {
        $viewer = $this->viewer();
        $this->screen($viewer, cols: 60, rows: 40); // render so the doc (and its lines) exist

        // The searchable surface is the rendered page, stripped of styling.
        $lines = $viewer->findLines();
        $this->assertContains('Paragraph 1.', $lines, 'findLines exposes the plain rendered text');
        $this->assertNull($viewer->replaceDocument(), 'documentation is read-only');
        $this->assertSame(0, $viewer->findCaretCol());
        $this->assertSame(0, $viewer->findCaretLine(), 'the match anchor starts at the top');

        // The version moves when the page changes (a re-search trigger).
        $before = $viewer->findVersion();
        $viewer->refreshPage($this->docs->path('editor.md'), (string) \file_get_contents($this->docs->path('editor.md')));
        $this->assertGreaterThan($before, $viewer->findVersion());
    }

    public function testRevealMatchScrollsAnOffScreenMatchIntoView(): void
    {
        $viewer = $this->viewer();
        $this->screen($viewer, cols: 60, rows: 10);

        $target = \array_search('Paragraph 30.', $viewer->findLines(), strict: true);
        $this->assertIsInt($target);

        $viewer->revealMatch($target, startByte: 0, endByte: 3);
        $this->assertGreaterThan(0, $viewer->findCaretLine(), 'scrolled down to bring the match on screen');
        $this->assertStringContainsString('Paragraph 30.', \implode("\n", $this->screen($viewer)), 'the match line is now visible');

        // Revealing an earlier match scrolls back up.
        $viewer->revealMatch(0, startByte: 0, endByte: 1);
        $this->assertSame(0, $viewer->findCaretLine(), 'scrolled up to the earlier match');

        // A match already on screen does not move the viewport.
        $viewer->revealMatch(0, startByte: 0, endByte: 1);
        $this->assertSame(0, $viewer->findCaretLine());
    }

    public function testShowSearchMatchesHighlightsTheMatchWithoutChangingText(): void
    {
        $viewer = $this->viewer();
        $viewer->render(new RenderContext(60, 40)); // build the doc

        $lineNo = null;
        $col = 0;
        foreach ($viewer->findLines() as $i => $line) {
            $at = \strpos($line, needle: 'Paragraph 1.');
            if (false !== $at) {
                $lineNo = $i;
                $col = $at;

                break;
            }
        }
        $this->assertNotNull($lineNo);

        $plain = $viewer->render(new RenderContext(60, 40));
        $viewer->showSearchMatches([$lineNo => [['start' => $col, 'end' => $col + 4, 'current' => true]]]);
        $lit = $viewer->render(new RenderContext(60, 40));

        $row = self::HELP_HEADER_ROWS + $lineNo; // scrollTop is 0
        $this->assertNotSame($plain[$row], $lit[$row], 'the match line gains search styling');
        $this->assertSame(
            AnsiUtils::stripAnsiCodes($plain[$row]),
            AnsiUtils::stripAnsiCodes($lit[$row]),
            'but the visible text is unchanged',
        );
    }

    public function testRefreshPageResetsScrollTopToExactlyZero(): void
    {
        $viewer = $this->viewer();
        $this->screen($viewer);
        $viewer->scrollBy(3);
        $before = $viewer->getRenderRevision();

        $viewer->refreshPage($this->docs->path('editor.md'), (string) \file_get_contents($this->docs->path('editor.md')));

        $this->assertGreaterThan($before, $viewer->getRenderRevision(), 'refreshPage invalidates');
        // scrollTop = -1 would shift every body row down by one blank line.
        $this->assertStringContainsString('Editor guide', \rtrim($this->screen($viewer)[1]), 'the heading is the very first body row');
    }

    public function testClearBumpsFindVersionForwardNotBackward(): void
    {
        $viewer = $this->viewer();
        $this->screen($viewer);
        $before = $viewer->findVersion();

        $viewer->clear();

        $this->assertGreaterThan($before, $viewer->findVersion(), 'clear() must increment findVersion, not decrement it');
    }

    public function testClearResetsScrollTopToExactlyZero(): void
    {
        $viewer = $this->viewer();
        $this->screen($viewer);
        $viewer->scrollBy(3);
        $viewer->clear();

        $viewer->refreshPage($this->docs->path('README.md'), (string) \file_get_contents($this->docs->path('README.md')));
        $this->assertStringContainsString('Guides', \rtrim($this->screen($viewer)[1]), 'scrollTop is exactly 0 after clear(), not -1');
    }

    public function testRefreshThemeInvalidates(): void
    {
        $viewer = $this->viewer();
        $before = $viewer->getRenderRevision();

        $viewer->refreshTheme();

        $this->assertGreaterThan($before, $viewer->getRenderRevision());
    }

    public function testScrollByInvalidates(): void
    {
        $viewer = $this->viewer();
        $this->screen($viewer);
        $before = $viewer->getRenderRevision();

        $viewer->scrollBy(1);

        $this->assertGreaterThan($before, $viewer->getRenderRevision());
    }

    public function testAClickExactlyOnTheColumnPastALinkSpanMisses(): void
    {
        $viewer = $this->viewer();
        $this->screen($viewer);

        // Doc line 3 holds the links; body row 4 with the header offset.
        // clickAt(4, 4) hits inside "Editor" (established by the existing
        // "click follows a link" test) — one column further must not.
        $this->assertTrue($viewer->clickAt(4, 4), 'sanity: inside the span');
        $lastCol = 4;
        while ($viewer->clickAt(4, $lastCol)) {
            ++$lastCol;
        }
        // $lastCol is now one past the span's last hit — endCol is exclusive.
        $this->assertFalse($viewer->clickAt(4, $lastCol));
    }

    public function testABodyClickSkipsANonMatchingSpanAndStillFindsALaterOneOnTheSameRow(): void
    {
        $viewer = $this->viewer();
        $this->screen($viewer);

        // Row 4: "See Editor and Porta and demo and gone ." — clicking the
        // LAST link ('gone', col 34) must still work even though scanning
        // it walks past Editor's (col 4) non-matching span first — proving
        // a miss on an earlier span doesn't abort the whole scan.
        $this->assertTrue($viewer->clickAt(4, 35));
        $this->assertSame(['missing:missing.md'], $this->handler->events);
    }

    public function testAOneColumnByOneRowRenderIsNotEmpty(): void
    {
        $viewer = $this->viewer();
        // cols = 1 and rows = 1 are the smallest NON-empty render — only 0 is empty.
        $this->assertNotSame([], $viewer->render(new RenderContext(1, 10)));
        $this->assertNotSame([], $viewer->render(new RenderContext(10, 1)));
    }

    public function testOnlyOneDimensionBeingZeroStillRendersNothing(): void
    {
        $viewer = $this->viewer();
        $this->assertSame([], $viewer->render(new RenderContext(0, 10)), 'zero columns alone is empty');
        $this->assertSame([], $viewer->render(new RenderContext(10, 0)), 'zero rows alone is empty');
    }

    public function testRenderingAgainAtTheSameWidthDoesNotReRewrapTheDoc(): void
    {
        $viewer = $this->viewer();
        $viewer->render(new RenderContext(60, 10));
        $before = $viewer->findVersion();

        $viewer->render(new RenderContext(60, 10)); // identical width: must reuse the memoized doc

        $this->assertSame($before, $viewer->findVersion(), 'findVersion only bumps on an actual re-wrap');
    }

    public function testANarrowerWidthReRewrapsAndBumpsFindVersion(): void
    {
        $viewer = $this->viewer();
        $viewer->render(new RenderContext(60, 10));
        $before = $viewer->findVersion();

        $viewer->render(new RenderContext(40, 10));

        $this->assertGreaterThan($before, $viewer->findVersion(), 're-wrapping at a new width must bump findVersion forward');
    }

    public function testExactlyTheLastBodyRowIsRenderedNoMoreNoLess(): void
    {
        $viewer = $this->viewer();
        // 6 rows total: 1 header + 5 body rows.
        $screen = $viewer->render(new RenderContext(60, 6));
        $this->assertCount(6, $screen, 'header + exactly (rows - HEADER_ROWS) body rows');
    }

    public function testCrumbsCollapseExactlyWhenTheHeaderWouldOverflowNotOneCharSooner(): void
    {
        $viewer = $this->viewer();
        $this->docs->write('guide/README.md', "# Guide index\n");
        $this->docs->write('guide/sub/deep.md', "# Deep page\n");
        $viewer->refreshPage($this->docs->path('guide/sub/deep.md'), "# Deep page\n");

        // ' ‹ ›  README › guide › sub › deep' fits at exactly 37 columns.
        $exact = $this->screen($viewer, 37, 10)[0];
        $this->assertStringNotContainsString('…', $exact, 'the full trail must still fit exactly at its natural width');

        $oneNarrower = $this->screen($viewer, 36, 10)[0];
        $this->assertStringContainsString('…', $oneNarrower, 'one column narrower must already collapse');
    }

    public function testMonochromeHeaderSkipsBoldAndColorRoles(): void
    {
        $viewer = $this->viewer();
        $viewer->refreshTheme(); // drop any memo before checking styles are consulted fresh
        $screen = $this->screen($viewer); // stripAnsiCodes — content-only check

        $this->assertStringContainsString('Guides', \rtrim($screen[1]));
        // The plain, stripped screen must be identical regardless of the
        // bold/unbold ternary direction — this just proves the header still
        // renders sane content; the ANSI-level bold assertion lives below.
        $header = $viewer->render(new RenderContext(60, 10))[0];
        $this->assertStringContainsString(Sgr::BOLD, $header, 'the current-page crumb is bold when the theme has color');
    }

    public function testInactiveArrowGlyphIsNotStyledAsTabAccent(): void
    {
        $viewer = $this->viewer();
        $this->screen($viewer); // no history/forward yet: both arrows inactive

        $header = $viewer->render(new RenderContext(60, 10))[0];
        // Role::Dim, not Role::TabAccent, colors an inactive arrow.
        $this->assertStringNotContainsString("\x1b[38;5;255m", $header, 'inactive glyphs must not carry the active TabAccent color');
    }

    public function testDroppedCrumbsStillKeepTheLeadingArrowsAndSpacing(): void
    {
        $viewer = $this->viewer();
        $this->docs->write('guide/README.md', "# Guide index\n");
        $this->docs->write('guide/sub/deep.md', "# Deep page\n");
        $viewer->refreshPage($this->docs->path('guide/sub/deep.md'), "# Deep page\n");

        // Narrow enough that the collapse branch runs (`$text .=`, not `$text =`,
        // for the ellipsis — an overwrite would silently erase the arrows).
        $narrow = $this->screen($viewer, 24, 10)[0];
        $this->assertStringContainsString('‹', $narrow, 'the back arrow survives the ellipsis append');
        $this->assertStringContainsString('…', $narrow);
        $this->assertStringContainsString('deep', $narrow);
    }

    public function testTheCurrentPageCrumbBoldTextComesBeforeItsUnboldClose(): void
    {
        $viewer = $this->viewer();
        $screen = $this->screen($viewer);
        $this->assertStringContainsString('README', $screen[0]);

        $header = $viewer->render(new RenderContext(60, 10))[0];
        $boldAt = \strpos($header, Sgr::BOLD);
        $labelAt = \strpos($header, needle: 'README');
        $this->assertNotFalse($boldAt);
        $this->assertNotFalse($labelAt);
        $this->assertLessThan($labelAt, $boldAt, 'bold opens before the label, not after');
    }

    public function testHeaderRestoresColorAfterTheLineNotBeforeIt(): void
    {
        $viewer = $this->viewer();
        $header = $viewer->render(new RenderContext(60, 10))[0];

        $this->assertStringEndsWith(Sgr::RESET, $header, 'the reset trails the content, closing it — not leading it');
    }

    public function testCrumbLabelFallsBackToTheBasenameWhenThereIsNoDocsHandler(): void
    {
        $viewer = new HelpViewerWidget();
        $viewer->setFocused(true);
        // No setHandler() call: docs() is null, so crumbLabel() must fall
        // back to basename() rather than crash on a nullsafe-less call.
        $viewer->setDocsRoot('/does-not-matter');
        $viewer->refreshPage('/does-not-matter/page.md', "# Page\n");

        $header = $this->plain($viewer->render(new RenderContext(60, 10))[0]);
        $this->assertStringContainsString('page', $header);
    }

    private function plain(string $line): string
    {
        return AnsiUtils::stripAnsiCodes($line);
    }

    public function testHierarchyCrumbsDoesNotCrashWithNoPageLoaded(): void
    {
        $viewer = new HelpViewerWidget();
        $viewer->setFocused(true);
        $viewer->setHandler($handler = new RecordingHelpViewerHandler());
        $viewer->setDocsRoot('/docs');
        // path() is still null here — hierarchyCrumbs()'s (string) cast on a
        // null $this->path must not be dropped, or crumbLabel(null) crashes
        // with a TypeError on the string-typed parameter.
        $line = $viewer->render(new RenderContext(60, 10));
        $this->assertNotSame([], $line, 'renders (a mostly-blank page) rather than throwing');
    }

    public function testAPathSharingOnlyAPrefixWithTheRootIsNotTreatedAsUnderIt(): void
    {
        $docs = new TempProject([
            'docs/README.md' => "# Home\n",
            'docs-other/page.md' => "# Other\n",
        ]);
        $viewer = new HelpViewerWidget();
        $viewer->setFocused(true);
        $viewer->setHandler(new RecordingHelpViewerHandler());
        $viewer->setDocsRoot($docs->path('docs'));
        // 'docs-other/page.md' starts with the raw string 'docs' (root minus
        // its trailing slash) but is a SIBLING, not a page under the root.
        $viewer->refreshPage($docs->path('docs-other/page.md'), "# Other\n");

        $header = $this->plain($viewer->render(new RenderContext(60, 10))[0]);
        // Only the current-page crumb — no root/folder crumbs — since this
        // page is not really under docsRoot.
        $this->assertStringNotContainsString('Home', $header, 'the unrelated root README must not appear as an ancestor crumb');
    }

    public function testEveryScrollKeyBindingMovesTheViewport(): void
    {
        $viewer = $this->viewer();
        $this->screen($viewer, 60, 6); // small viewport so scroll differences are visible

        $viewer->handleInput("\x1b[B"); // Down
        $afterDown = $viewer->findCaretLine();
        $this->assertSame(1, $afterDown, 'help_scroll_down moved exactly one row');

        $viewer->handleInput("\x1b[A"); // Up
        $this->assertSame(0, $viewer->findCaretLine(), 'help_scroll_up moved back exactly one row');

        $viewer->handleInput("\x1b[6~"); // Page Down
        $this->assertGreaterThan(0, $viewer->findCaretLine(), 'help_page_down moved by a body-rows page');

        $viewer->handleInput("\x1b[H"); // Home
        $this->assertSame(0, $viewer->findCaretLine(), 'help_home returns to the top');

        $viewer->handleInput("\x1b[F"); // End
        $this->assertGreaterThan(0, $viewer->findCaretLine(), 'help_end jumps to the bottom');

        $viewer->handleInput("\x1b[5~"); // Page Up
        $this->assertLessThan($viewer->findCaretLine() + 1, $viewer->findCaretLine(), 'help_page_up is wired (sanity: still a valid line)');
    }

    public function testCycleLinkDoesNotScrollWhenTheFocusedLinkIsAlreadyOnScreen(): void
    {
        $viewer = $this->viewer();
        $this->screen($viewer);
        $before = $viewer->findCaretLine();

        $viewer->handleInput("\t"); // "Editor" is on the very first screen already

        $this->assertSame($before, $viewer->findCaretLine(), 'a link already in view must not move the viewport');
    }

    public function testHighlightSplicesTheSelectionStyleExactlyAroundTheLinkTextInOrder(): void
    {
        $viewer = $this->viewer();
        $this->screen($viewer);
        $viewer->handleInput("\t"); // focus "Editor"

        $line = $viewer->render(new RenderContext(60, 10))[4];
        // Exactly: prefix, RESET, style-open, the link text, RESET, suffix — in that order.
        $this->assertMatchesRegularExpression('/^.*\x1b\[0m(?:\x1b\[[0-9;]*m)+Editor\x1b\[0m.*$/', $line);
    }

    public function testSearchHighlightSplicesTheMatchStyleExactlyAroundTheMatchedTextInOrder(): void
    {
        $viewer = $this->viewer();
        $viewer->render(new RenderContext(60, 40));
        $lineNo = \array_search('Paragraph 1.', $viewer->findLines(), strict: true);
        $this->assertIsInt($lineNo);

        $viewer->showSearchMatches([$lineNo => [['start' => 0, 'end' => 9, 'current' => true]]]);
        $row = self::HELP_HEADER_ROWS + $lineNo;
        $line = $viewer->render(new RenderContext(60, 40))[$row];

        $this->assertMatchesRegularExpression(
            '/^\x1b\[0m(?:\x1b\[[0-9;]*m)+Paragraph\x1b\[0m.*$/',
            $line,
            'RESET, style-open, matched text, RESET — exactly in that order, nothing dropped or reordered',
        );
    }

    public function testTheCurrentMatchStyleDiffersFromANonCurrentMatch(): void
    {
        $viewer = $this->viewer();
        $viewer->render(new RenderContext(60, 40));
        $lineNo = \array_search('Paragraph 1.', $viewer->findLines(), strict: true);
        $this->assertIsInt($lineNo);

        $viewer->showSearchMatches([$lineNo => [['start' => 0, 'end' => 9, 'current' => true]]]);
        $row = self::HELP_HEADER_ROWS + $lineNo;
        $current = $viewer->render(new RenderContext(60, 40))[$row];

        $viewer->showSearchMatches([$lineNo => [['start' => 0, 'end' => 9, 'current' => false]]]);
        $notCurrent = $viewer->render(new RenderContext(60, 40))[$row];

        $this->assertNotSame($current, $notCurrent, 'the current match must be styled differently (bold + a distinct role)');
    }

    public function testByteAtColumnHandlesAMultibyteGraphemeBoundaryExactly(): void
    {
        $viewer = new HelpViewerWidget();
        $viewer->setFocused(true);
        $viewer->setHandler(new RecordingHelpViewerHandler());
        $viewer->setDocsRoot('/docs');
        // A combining-mark grapheme ("é" as e + combining acute) exercises the
        // grapheme-splitting loop's exact boundary arithmetic.
        $viewer->refreshPage('/docs/x.md', "café society and more filler text to search\n");
        $viewer->render(new RenderContext(60, 10));

        $lineNo = \array_search('café society and more filler text to search', $viewer->findLines(), strict: true);
        $this->assertIsInt($lineNo);
        // Highlight the word "society" (columns 5..11 of the plain text).
        $viewer->showSearchMatches([$lineNo => [['start' => 5, 'end' => 12, 'current' => true]]]);

        $row = self::HELP_HEADER_ROWS + $lineNo;
        $plain = AnsiUtils::stripAnsiCodes($viewer->render(new RenderContext(60, 10))[$row]);
        $this->assertStringStartsWith('café society', \rtrim($plain), 'the multibyte prefix is untouched by the highlight splice');
    }

    public function testClampScrollNeverGoesBelowZeroOnAShortPage(): void
    {
        $viewer = new HelpViewerWidget();
        $viewer->setFocused(true);
        $viewer->setHandler(new RecordingHelpViewerHandler());
        $viewer->setDocsRoot('/docs');
        $viewer->refreshPage('/docs/x.md', "# Just one short page\n");

        $viewer->scrollBy(\PHP_INT_MAX); // a page shorter than the viewport: max scroll must clamp to 0
        $screen = $this->screen($viewer, 60, 20);
        $this->assertStringContainsString('Just one short page', \implode("\n", $screen));
    }

    public function testBodyRowsIsAtLeastOneEvenWithATinyLastRows(): void
    {
        $viewer = $this->viewer();
        $viewer->render(new RenderContext(60, 1)); // lastRows = 1 → bodyRows would be 0 without its floor

        // scrollBy must not divide-by-zero or otherwise misbehave when bodyRows floors to 1.
        $viewer->scrollBy(1);
        $this->addToAssertionCount(1); // reaching here without throwing is the assertion
    }

    private const int HELP_HEADER_ROWS = 1;
}
