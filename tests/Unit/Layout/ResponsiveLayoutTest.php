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
use Porta\Overlay\Region\FloatingRegions;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestWorkspaceLayout;
use Porta\Ui\BidiTui;
use Porta\Ui\PaneSegmenter;
use Porta\Ui\Theme\Styles;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
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
    private \Porta\Layout\WorkspaceLayout $layout;
    private BidiTui $bidi;
    private PaneLayout $paneLayout;
    private Renderer $renderer;

    private function responsive(): ResponsiveLayout
    {
        $this->project = new TempProject();
        $this->tree = new FileTreeWidget($this->project->dir);
        $this->tree->setId('tree');
        $this->editor = new CodeEditorWidget();
        $this->editor->setId('editor');
        $this->layout = TestWorkspaceLayout::create(rootDir: $this->project->dir, tree: $this->tree, editor: $this->editor);
        $renderer = new Renderer(new StyleSheet());
        $this->renderer = $renderer;
        $this->bidi = new BidiTui(terminal: new VirtualTerminal(columns: 100, rows: 30), renderer: $renderer);
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

        return new ResponsiveLayout($this->paneLayout, $this->tree, $editorArea, $this->bidi);
    }

    /** @return list<string> */
    private function mainIds(): array
    {
        return array_map(static fn (AbstractWidget $w): string => $w->getId(), $this->layout->main()->all());
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
            array_map(static fn (AbstractWidget $w): string => $w->getId(), $this->layout->main()->all()),
            'and the editor area was left in its build-time order',
        );

        // The control: the SAME pane layout mirrors once a region exists, so
        // the assertion above is the guard holding rather than 'he' quietly
        // not being right-to-left.
        $regions->mint('acme');
        $paneLayout->applyDirection();
        $this->assertSame(
            ['editor-pane', 'separator', 'tree'],
            array_map(static fn (AbstractWidget $w): string => $w->getId(), $this->layout->main()->all()),
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
        $ids = array_map(static fn (AbstractWidget $w): string => $w->getId(), $this->paneLayout->orderedEditorPanes());
        $this->assertSame(['editor-pane'], $ids);

        $responsive->apply(200);
        $ids = array_map(static fn (AbstractWidget $w): string => $w->getId(), $this->paneLayout->orderedEditorPanes());
        $this->assertSame(['tree', 'separator', 'editor-pane'], $ids);
    }
}
