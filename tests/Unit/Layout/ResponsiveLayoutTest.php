<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Layout;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\EditorPanes;
use Porta\I18n\PaneReorder;
use Porta\I18n\Translator;
use Porta\Layout\PaneLayout;
use Porta\Layout\ResponsiveLayout;
use Porta\Layout\ViewRegions;
use Porta\Layout\WorkspaceLayout;
use Porta\Overlay\Region\FloatingRegions;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestWorkspaceLayout;
use Porta\Ui\BidiTui;
use Porta\Ui\PaneSegmenter;
use Porta\Ui\Theme\Styles;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\Support\TabBarPosition;
use Porta\Widget\TabBarWidget;
use Porta\Workbench\EditorArea;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Widget\AbstractWidget;

final class ResponsiveLayoutTest extends TestCase
{
    private TempProject $project;
    private FileTreeWidget $tree;
    private CodeEditorWidget $editor;
    private MenuBarWidget $menuBar;
    private WorkspaceLayout $layout;
    private TabBarWidget $tabBar;
    private BidiTui $bidi;
    private PaneLayout $paneLayout;
    private Renderer $renderer;

    private function responsive(int $columns = 100): ResponsiveLayout
    {
        $this->project = new TempProject();
        $this->tree = new FileTreeWidget($this->project->dir);
        $this->tree->setId('tree');
        $this->editor = new CodeEditorWidget();
        $this->editor->setId('editor');
        $this->menuBar = new MenuBarWidget();
        $this->tabBar = new TabBarWidget();
        $this->layout = TestWorkspaceLayout::create(rootDir: $this->project->dir, tree: $this->tree, editor: $this->editor, menuBar: $this->menuBar, tabBar: $this->tabBar);
        $renderer = new Renderer(new StyleSheet());
        $this->renderer = $renderer;
        $this->bidi = new BidiTui(terminal: new VirtualTerminal(columns: $columns, rows: 30), renderer: $renderer);
        $this->layout->mount($this->bidi);

        $t = new Translator('en');
        $this->paneLayout = new PaneLayout(
            $this->layout,
            $t,
            $this->bidi,
            new PaneSegmenter($renderer, new class implements FloatingRegions {
                public function regions(): array
                {
                    return [];
                }
            }),
            new PaneReorder(),
            new ViewRegistry(),
            new ViewRegions(Styles::dark()),
        );
        $this->paneLayout->arrangeEditorArea();

        $editorArea = new EditorArea(
            new BufferManager(),
            $this->editor,
            new EditorPanes(),
            $this->bidi,
        );

        return new ResponsiveLayout($this->paneLayout, $this->tree, $editorArea, $this->layout, $this->bidi);
    }

    /** @return list<string> */
    private function mainIds(): array
    {
        return \array_map(static fn (AbstractWidget $w): string => $w->getId(), $this->layout->main->all());
    }

    public function testApplyingDirectionBeforeAnyViewMintsARegionIsANoOp(): void
    {
        // The boot-time applyAll() fires before any view controller exists, and
        // a view's region is minted in its constructor — so "no regions yet" is
        // the same moment the old "no controller attached yet" guard named.
        // Without it, boot mirrors panes for a workspace that is not built.
        $this->responsive(); // builds the layout, the bidi tui and the renderer
        $regions = new ViewRegions(Styles::dark());
        $paneLayout = new PaneLayout(
            $this->layout,
            new Translator('he'), // the RTL locale, so a missing guard visibly mirrors
            $this->bidi,
            new PaneSegmenter($this->renderer, new class implements FloatingRegions {
                public function regions(): array
                {
                    return [];
                }
            }),
            new PaneReorder(),
            new ViewRegistry(),
            $regions,
        );

        $paneLayout->applyDirection();

        $this->assertSame([], $regions->all(), 'nothing minted, so nothing to arrange');
        $this->assertSame(
            ['tree', 'separator', 'editor-pane'],
            \array_map(static fn (AbstractWidget $w): string => $w->getId(), $this->layout->main->all()),
            'and the editor area was left in its build-time order',
        );

        // The control: the SAME pane layout mirrors once a region exists, so
        // the assertion above is the guard holding rather than 'he' quietly
        // not being right-to-left.
        $regions->mint('acme');
        $paneLayout->applyDirection();
        $this->assertSame(
            ['editor-pane', 'separator', 'tree'],
            \array_map(static fn (AbstractWidget $w): string => $w->getId(), $this->layout->main->all()),
        );
    }

    public function testCollapsesBelowTheThresholdAndRestoresAbove(): void
    {
        $responsive = $this->responsive();
        $this->assertSame(['tree', 'separator', 'editor-pane'], $this->mainIds());

        $responsive->apply(ResponsiveLayout::COLLAPSE_BELOW - 1);
        $this->assertSame(['editor-pane'], $this->mainIds(), 'narrow: the sidebar collapses');

        $responsive->apply(ResponsiveLayout::COLLAPSE_BELOW - 5); // still narrow → no re-arrangement
        $this->assertSame(['editor-pane'], $this->mainIds());

        $responsive->apply(ResponsiveLayout::COLLAPSE_BELOW);
        $this->assertSame(['tree', 'separator', 'editor-pane'], $this->mainIds(), 'wide again: the sidebar restores');
    }

    public function testASideTabStripGoesToTheTopWhenTheTerminalCannotSeatIt(): void
    {
        // The strip is a fixed 24 columns. On a narrow terminal that is most of
        // the screen and the file reads in what is left — which is how a
        // one-column editor pane was reachable at all.
        $responsive = $this->responsive();
        $this->layout->setTabBarPosition(TabBarPosition::Right);
        $this->assertTrue($this->tabBar->position()->isSide());

        $responsive->apply(ResponsiveLayout::SIDE_TABS_BELOW - 1);
        $this->assertSame(TabBarPosition::Top, $this->tabBar->position(), 'narrow: the strip goes up');
        $this->assertTrue($this->layout->tabsAreDisplaced());

        // Entered AND left: the rule guards on a change of state, so a one-way
        // test proves half of it.
        $responsive->apply(ResponsiveLayout::SIDE_TABS_BELOW);
        $this->assertSame(TabBarPosition::Right, $this->tabBar->position(), 'wide again: back where it was asked for');
        $this->assertFalse($this->layout->tabsAreDisplaced());
    }

    public function testAFocusedPaneKeepsItsFocusWhenTheStripMoves(): void
    {
        // The surgery clears and refills the editor pane, so the focused widget
        // is detached and focus falls to the menu bar — the same hazard the
        // sidebar collapse handles, on a second path that had none.
        $responsive = $this->responsive();
        $this->layout->setTabBarPosition(TabBarPosition::Right);

        // Collapse the sidebar FIRST, at a width the strip still fits. Both
        // rules fire on the same call and the sidebar's threshold is the wider
        // one, so a single narrow apply() would have the sidebar's own focus
        // restore doing this assertion's work — which is exactly what it did
        // until mutation testing showed the strip's restore could be deleted
        // with the test still green.
        $responsive->apply(ResponsiveLayout::COLLAPSE_BELOW - 1);
        $this->assertSame(TabBarPosition::Right, $this->tabBar->position(), 'the strip still fits at this width');

        $this->bidi->setFocus($this->tree);
        $responsive->apply(ResponsiveLayout::SIDE_TABS_BELOW - 1);

        $this->assertSame(TabBarPosition::Top, $this->tabBar->position());
        $this->assertSame($this->editor, $this->bidi->getFocus(), 'focus was handed to the editor, not dropped');
    }

    public function testATOPBarIsLeftAloneAtEveryWidth(): void
    {
        // There is nothing to rescue, and moving it would be inventing a
        // preference the user never expressed.
        $responsive = $this->responsive();
        $this->layout->setTabBarPosition(TabBarPosition::Bottom);

        $responsive->apply(ResponsiveLayout::SIDE_TABS_BELOW - 1);

        $this->assertSame(TabBarPosition::Bottom, $this->tabBar->position());
        $this->assertFalse($this->layout->tabsAreDisplaced(), 'nothing was displaced');
    }

    public function testChoosingAPositionWHILEDisplacedIsWhatGetsRestored(): void
    {
        // The staleness this design exists to avoid: the layout is told the
        // user's CHOICE by the one thing that applies it, so a change made
        // while the strip is displaced replaces what would otherwise be put
        // back. Reading a boot snapshot would restore an abandoned preference.
        $responsive = $this->responsive();
        $this->layout->setTabBarPosition(TabBarPosition::Right);
        $responsive->apply(ResponsiveLayout::SIDE_TABS_BELOW - 1);
        $this->assertSame(TabBarPosition::Top, $this->tabBar->position());

        // The user picks the other side while the terminal is still narrow.
        $this->layout->setTabBarPosition(TabBarPosition::Left);
        $this->assertSame(TabBarPosition::Top, $this->tabBar->position(), 'still too narrow to seat it');

        $responsive->apply(ResponsiveLayout::SIDE_TABS_BELOW);
        $this->assertSame(TabBarPosition::Left, $this->tabBar->position(), 'the NEW choice is restored');
    }

    public function testFocusedTreeHandsFocusToTheEditorBeforeCollapsing(): void
    {
        $responsive = $this->responsive();
        $this->bidi->setFocus($this->tree);
        $this->assertTrue($this->tree->isFocused());

        $responsive->apply(40);

        $this->assertFalse($this->tree->isFocused(), 'a collapsing tree never keeps focus');
        $this->assertTrue($this->editor->isFocused(), 'the editor took it');
        $this->assertSame(['editor-pane'], $this->mainIds());
    }

    public function testUnfocusedTreeCollapsesWithoutTouchingFocus(): void
    {
        $responsive = $this->responsive();
        $this->bidi->setFocus($this->editor);

        $responsive->apply(40);

        $this->assertTrue($this->editor->isFocused(), 'focus stays where it was');
        $this->assertSame(['editor-pane'], $this->mainIds());
    }

    public function testCollapsedStateFlowsThroughOrderedEditorPanes(): void
    {
        $responsive = $this->responsive();
        $responsive->apply(40);

        // A swapped-in view restoring the editor area reads the collapsed list.
        $ids = \array_map(static fn (AbstractWidget $w): string => $w->getId(), $this->paneLayout->orderedEditorPanes());
        $this->assertSame(['editor-pane'], $ids);

        $responsive->apply(200);
        $ids = \array_map(static fn (AbstractWidget $w): string => $w->getId(), $this->paneLayout->orderedEditorPanes());
        $this->assertSame(['tree', 'separator', 'editor-pane'], $ids);
    }

    public function testConstructionSelfRegistersOnTheRenderLoopsWidthHook(): void
    {
        // Built narrow from the start: apply() itself is never called
        // directly here — only the render loop's own onColumnsChanged hook,
        // fired by BidiTui::processRender()'s first frame, drives the
        // collapse. If the constructor never wired it, a resize would just
        // never be noticed.
        $this->responsive(columns: 40);
        $this->assertSame(['tree', 'separator', 'editor-pane'], $this->mainIds(), 'not collapsed yet — nothing has rendered a frame');

        $this->bidi->processRender();

        $this->assertSame(['editor-pane'], $this->mainIds(), 'the first frame\'s width-change hook collapsed it');
    }

    public function testARedundantApplyAtTheSameCollapseStateTouchesNothing(): void
    {
        $responsive = $this->responsive();
        $responsive->apply(40); // collapses
        // Some later, unrelated focus change — not what the FIRST apply()
        // restored, so a real redundant re-arrangement below would visibly
        // reassign it.
        $this->bidi->setFocus($this->tree);
        $this->assertTrue($this->tree->isFocused());

        $responsive->apply(40); // same collapse state: must be a no-op

        $this->assertTrue($this->tree->isFocused(), 'an early return means nothing was re-arranged or refocused');
    }

    public function testRestoreFocusRequiresBothInstanceofFocusableAndActuallyFocused(): void
    {
        // The menu bar is FocusableInterface too, and — unlike the tree or
        // the editor pane — is never a child of `main`, so a container clear
        // cannot itself be what moves focus off it; only a wrongly-true
        // restoreFocus can.
        $responsive = $this->responsive();
        $this->bidi->setFocus($this->menuBar);
        $this->assertTrue($this->menuBar->isFocused());

        $responsive->apply(40);

        $this->assertTrue($this->menuBar->isFocused(), 'neither the tree nor the editor pane was focused, so restoreFocus must be false');
        $this->assertFalse($this->editor->isFocused());
    }

    public function testArrangeViewAreasIsPubliclyCallable(): void
    {
        // Only applyDirection() calls this internally today, which stays fine
        // even if visibility narrowed — a direct external call (as a future
        // caller, or a plugin's own view refresh, might make) is the only way
        // a narrowed visibility becomes a fatal error instead of a silent
        // no-op difference.
        $this->responsive();

        $this->paneLayout->arrangeViewAreas();

        $this->addToAssertionCount(1);
    }

    public function testArrangeViewAreasOrdersEveryMintedRegionsOwnArea(): void
    {
        $this->responsive();
        $regions = new ViewRegions(Styles::dark());
        $region = $regions->mint('acme');
        $paneLayout = new PaneLayout(
            $this->layout,
            new Translator('en'),
            $this->bidi,
            new PaneSegmenter($this->renderer, new class implements FloatingRegions {
                public function regions(): array
                {
                    return [];
                }
            }),
            new PaneReorder(),
            new ViewRegistry(),
            $regions,
        );

        $paneLayout->arrangeViewAreas();

        $this->assertSame(
            ['acme-left', 'acme-separator', 'acme-content'],
            \array_map(static fn (AbstractWidget $w): string => $w->getId(), $region->area()->all()),
            'the region\'s own area got filled — main() is a different container entirely',
        );
    }

    public function testApplyDirectionMirrorsUnderRtlAndNotUnderLtr(): void
    {
        $this->responsive();
        $regions = new ViewRegions(Styles::dark());
        $regions->mint('acme');

        $ltr = new PaneLayout(
            $this->layout,
            new Translator('en'),
            $this->bidi,
            new PaneSegmenter($this->renderer, new class implements FloatingRegions {
                public function regions(): array
                {
                    return [];
                }
            }),
            new PaneReorder(),
            new ViewRegistry(),
            $regions,
        );
        $ltr->applyDirection();
        $this->assertFalse($this->bidi->isMirrored(), 'an LTR locale must not install the reorder seam');

        $rtl = new PaneLayout(
            $this->layout,
            new Translator('he'),
            $this->bidi,
            new PaneSegmenter($this->renderer, new class implements FloatingRegions {
                public function regions(): array
                {
                    return [];
                }
            }),
            new PaneReorder(),
            new ViewRegistry(),
            $regions,
        );
        $rtl->applyDirection();
        $this->assertTrue($this->bidi->isMirrored(), 'an RTL locale must install it');
    }

    public function testApplyDirectionArrangesTheViewAreasNotJustTheEditorArea(): void
    {
        // applyDirection()'s own arrangeEditorArea() call rearranges `main`
        // regardless of arrangeViewAreas() — so a removed arrangeViewAreas()
        // call is only observable through a minted region's OWN area, not
        // through main()'s pane order.
        $this->responsive();
        $regions = new ViewRegions(Styles::dark());
        $region = $regions->mint('acme');
        $paneLayout = new PaneLayout(
            $this->layout,
            new Translator('en'),
            $this->bidi,
            new PaneSegmenter($this->renderer, new class implements FloatingRegions {
                public function regions(): array
                {
                    return [];
                }
            }),
            new PaneReorder(),
            new ViewRegistry(),
            $regions,
        );

        $paneLayout->applyDirection();

        $this->assertSame(
            ['acme-left', 'acme-separator', 'acme-content'],
            \array_map(static fn (AbstractWidget $w): string => $w->getId(), $region->area()->all()),
        );
    }
}
