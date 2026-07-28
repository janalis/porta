<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Container\AsIdeService;
use Porta\I18n\PaneReorder;
use Porta\I18n\Translator;
use Porta\Ui\BidiTui;
use Porta\Ui\PaneSegmenter;
use Porta\Widget\Git\DiffViewWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Workbench\WorkspaceViews;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * The RTL-aware pane ordering for the three horizontal areas (editor, git,
 * settings): owns their canonical LTR pane lists, arranges each area's
 * children in the active writing direction, and re-mirrors everything on a
 * live locale switch (including the per-pane bidi reorder seam on BidiTui).
 */
#[AsIdeService]
final class PaneLayout implements EditorAreaPanes
{
    /** @var list<AbstractWidget> */
    private readonly array $editorPanes;

    /** @var list<AbstractWidget> */
    private readonly array $gitPanes;

    /** @var list<AbstractWidget> */
    private readonly array $settingsPanes;

    /** Auto-collapse state for narrow terminals — ephemeral, never persisted. */
    private bool $sidebarCollapsed = false;

    public function __construct(
        private readonly WorkspaceLayout $layout,
        private readonly Translator $translator,
        private readonly BidiTui $bidi,
        private readonly PaneSegmenter $segmenter,
        private readonly PaneReorder $reorder,
        private readonly WorkspaceViews $views,
        SettingsNavWidget $settingsNav,
        DiffViewWidget $gitDiff,
    ) {
        $this->editorPanes = $layout->editorPanes();
        $this->gitPanes = [$layout->gitLeft(), $layout->gitSeparator(), $gitDiff];
        $this->settingsPanes = [$settingsNav, $layout->settingsSeparator(), $layout->settingsContent()];
    }

    /**
     * The editor area's panes in visual order: reversed (sidebar on the right)
     * for RTL locales. The swapped-in views restore this order on close.
     *
     * @return list<AbstractWidget>
     */
    public function orderedEditorPanes(): array
    {
        return $this->order($this->currentEditorPanes());
    }

    public function arrangeEditorArea(): void
    {
        $this->setPanes($this->layout->main(), $this->currentEditorPanes());
    }

    /**
     * Drop the file-tree sidebar from the editor area (narrow terminal). The
     * swapped-in views pick the collapsed list up through
     * {@see orderedEditorPanes()} when they restore the editor area.
     */
    public function collapseSidebar(): void
    {
        $this->sidebarCollapsed = true;
        $this->rearrangeEditorAreaIfOwning();
    }

    /** Restore the file-tree sidebar (the terminal is wide enough again). */
    public function expandSidebar(): void
    {
        $this->sidebarCollapsed = false;
        $this->rearrangeEditorAreaIfOwning();
    }

    /** Re-arrange the main container only while the editor area owns it (not a swapped-in view). */
    private function rearrangeEditorAreaIfOwning(): void
    {
        if (!$this->views->isAnyOpen()) {
            $this->arrangeEditorArea();
        }
    }

    /**
     * The editor area's canonical panes, minus the tree and its separator
     * while the sidebar is auto-collapsed.
     *
     * @return list<AbstractWidget>
     */
    private function currentEditorPanes(): array
    {
        return $this->sidebarCollapsed ? \array_slice($this->editorPanes, offset: 2) : $this->editorPanes;
    }

    public function arrangeGitArea(): void
    {
        $this->setPanes($this->layout->gitArea(), $this->gitPanes);
    }

    public function arrangeSettingsArea(): void
    {
        $this->setPanes($this->layout->settingsArea(), $this->settingsPanes);
    }

    /**
     * Mirror the horizontal layouts for the active writing direction: the git
     * and settings areas always, and the editor layout when it currently owns
     * the main container (a swapped-in view restores in order when it closes).
     * A no-op until both view controllers have attached (the boot-time
     * applyAll() fires before they exist; build time already ordered the
     * containers for the persisted locale).
     */
    public function applyDirection(): void
    {
        if (!$this->views->isAttached()) {
            return;
        }
        $this->translator->isRtl()
            ? $this->bidi->setPaneReorder($this->segmenter, $this->reorder)
            : $this->bidi->setPaneReorder(null, null);
        $this->arrangeGitArea();
        $this->arrangeSettingsArea();
        if (!$this->views->isAnyOpen()) {
            $this->arrangeEditorArea();
        }
    }

    /**
     * Replace a horizontal container's children with $ltr panes in visual order.
     *
     * @param list<AbstractWidget> $ltr
     */
    private function setPanes(ContainerWidget $container, array $ltr): void
    {
        $container->clear();
        foreach ($this->order($ltr) as $pane) {
            $container->add($pane);
        }
    }

    /**
     * @param list<AbstractWidget> $ltr
     *
     * @return list<AbstractWidget>
     */
    private function order(array $ltr): array
    {
        return $this->translator->isRtl() ? array_reverse($ltr) : $ltr;
    }
}
