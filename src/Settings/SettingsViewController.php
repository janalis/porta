<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Container\AsEager;
use Porta\Container\LayoutSlot;
use Porta\Event\SettingsRowsStale;
use Porta\Event\SettingsSectionRequested;
use Porta\Layout\EditorAreaPanes;
use Porta\Layout\ViewRegion;
use Porta\Layout\ViewRegions;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Widget\Settings\SettingsFormWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Workbench\EditorArea;
use Porta\Workbench\View\ContributedView;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * The full-screen settings view: swaps the main area's tree|editor children for
 * a [nav sidebar | separator | content] column. The sidebar selects a section —
 * General, Keybindings, Plugins, or a language — and the content pane shows the
 * matching form/list. Structured like {@see \Porta\Git\GitViewController} but simpler (no
 * poll loop, no availability gate).
 */
#[AsEager(priority: 500)]
final class SettingsViewController implements ContributedView
{
    private readonly ViewRegion $region;

    private bool $open = false;
    /** 'general' | 'keybindings' | 'lang:<id>' — the active section. */
    private string $section = 'general';

    public function __construct(
        private readonly SettingsNavWidget $nav,
        private readonly SettingsFormWidget $form,
        private readonly KeybindingsListWidget $keymapList,
        #[LayoutSlot('main')]
        private readonly ContainerWidget $main,
        ViewRegions $regions,
        private readonly MenuBarWidget $menuBar,
        private readonly EditorAreaPanes $editArea,
        private readonly Tui $tui,
        /** @var iterable<SettingsSection> in #[AsTaggedItem] priority order; the general section claims everything and sorts last */
        #[AutowireIterator('porta.settings_section')]
        private readonly iterable $sections,
        private readonly SettingsKeymapController $keymap,
        private readonly SettingsPluginsController $plugins,
        private readonly EditorArea $editorArea,
        ViewRegistry $views,
    ) {
        // Minted, like git's. The nav is this view's fixed half, so it goes
        // into the sidebar once here — it used to be handed to PaneLayout as a
        // bare widget, which is why the two views' regions were mirror images
        // and neither shape could describe the other.
        $this->region = $regions->mint('settings');
        $this->region->sidebar()->add($this->nav);
        $views->register($this);
        $exit = $this->close(...);

        $this->nav
            ->onSelect($this->selectSection(...))
            ->onFocusNext($this->focusContent(...))
            ->onExit($exit);
        $this->form
            ->onChange($this->changeSetting(...))
            ->onFocusNext($this->focusNav(...))
            ->onExit($exit);
        $this->keymapList
            ->onRebind($this->rebind(...))
            ->onFocusNext($this->focusNav(...))
            ->onExit($exit);
    }

    public function viewId(): string
    {
        return 'settings';
    }

    public function ownsMainArea(): bool
    {
        return true;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    /** Where focus should land when a popup (e.g. the app menu) closes over the view. */
    public function focusTarget(): AbstractWidget&FocusableInterface
    {
        return $this->contentWidget();
    }

    public function toggle(): void
    {
        $this->open ? $this->close() : $this->open();
    }

    public function open(): void
    {
        if ($this->open) {
            return;
        }
        $this->open = true;
        // The (global, full-width) menu bar stays put; just drop any open
        // dropdown so its tracked hit region cannot go stale across the swap.
        $this->menuBar->close();
        $this->main->clear();
        $this->main->add($this->region->area());

        $this->selectSection('general'); // always open on General
        $this->tui->setFocus($this->form);
        $this->tui->requestRender();
    }

    public function close(): void
    {
        if (!$this->open) {
            return;
        }
        $this->open = false;
        $this->menuBar->close();
        $this->main->clear();
        foreach ($this->editArea->orderedEditorPanes() as $widget) {
            $this->main->add($widget);
        }
        $this->tui->setFocus($this->editorArea->widget());
        $this->tui->requestRender();
    }

    /**
     * Show a section's content pane. Does not steal focus — the sidebar keeps it
     * while navigating (Tab moves to the content).
     */
    /**
     * The form's `2` and the keybindings list's `1` — two widgets advertising a
     * shortcut to the other page, which is one intent with two spellings.
     */
    #[AsEventListener]
    public function onSectionRequested(SettingsSectionRequested $event): void
    {
        $this->selectSection($event->section);
    }

    private function selectSection(string $id): void
    {
        $this->section = $id;
        $this->nav->setActive($id);
        $this->region->content()->clear();
        if ('keybindings' === $id) {
            $this->keymapList->setRows($this->keymap->rows());
            $this->region->content()->add($this->keymapList);
        }
        if ('keybindings' !== $id) {
            $this->form->setRows($this->rowsForSection());
            $this->region->content()->add($this->form);
        }
        $this->tui->requestRender();
    }

    /**
     * The section owning the active nav id. Never null: the general section
     * claims every id and its tag priority sorts it last.
     */
    private function activeSection(): SettingsSection
    {
        foreach ($this->sections as $section) {
            if ($section->handles($this->section)) {
                return $section;
            }
        }

        throw new \LogicException('No settings section claimed '.$this->section);
    }

    /** @return list<SettingsRow> */
    private function rowsForSection(): array
    {
        return $this->activeSection()->rows($this->section);
    }

    private function changeSetting(string $key, bool|int|string $value): void
    {
        $this->activeSection()->change($this->section, $key, $value);
        $this->form->setRows($this->rowsForSection());
    }

    /**
     * Re-push the plugin rows when plugin state changed outside the form's own
     * change flow (an async install landed, a plugin was removed). When the
     * shown plugin page's plugin just vanished, fall back to the Plugins
     * section. No-op elsewhere.
     */
    /**
     * The plugin set moved under us. Core used to reach this through a
     * concrete SettingsViewController field on the view registry, which is
     * exactly the coupling that put Workbench -> Settings in the graph.
     */
    #[AsEventListener]
    public function onSettingsRowsStale(SettingsRowsStale $event): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        if (!$this->open) {
            return;
        }
        if (str_starts_with($this->section, 'plugin:') && !$this->plugins->hasPlugin(substr($this->section, offset: 7))) {
            $this->selectSection('plugins');

            return;
        }
        if ('plugins' === $this->section || str_starts_with($this->section, 'plugin:')) {
            $this->form->setRows($this->rowsForSection());
            $this->tui->requestRender();
        }
    }

    private function rebind(string $action, string $key): void
    {
        $this->keymap->rebind($action, $key);
        $this->keymapList->setRows($this->keymap->rows());
    }

    private function contentWidget(): AbstractWidget&FocusableInterface
    {
        return 'keybindings' === $this->section ? $this->keymapList : $this->form;
    }

    /**
     * F6/Ctrl+F6 while the settings view is open: bounce between nav and
     * content — two stops, so the direction cannot matter and is ignored.
     */
    public function cycleFocus(int $direction): bool
    {
        if ($this->tui->getFocus() === $this->nav) {
            $this->focusContent();

            return true;
        }
        $this->focusNav();

        return true;
    }

    private function focusNav(): void
    {
        $this->tui->setFocus($this->nav);
        $this->tui->requestRender();
    }

    private function focusContent(): void
    {
        $this->tui->setFocus($this->contentWidget());
        $this->tui->requestRender();
    }
}
