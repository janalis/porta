<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Container\AsEager;
use Porta\Event\SettingsRowsStale;
use Porta\Event\SettingsSectionRequested;
use Porta\Layout\ViewRegion;
use Porta\Layout\ViewRegions;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Widget\Settings\SettingsFormWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Workbench\Menu\ViewMenuSource;
use Porta\Workbench\View\ContributedView;
use Porta\Workbench\View\ViewHost;
use Porta\Workbench\View\ViewPointer;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * The full-screen settings view: swaps the main area's tree|editor children for
 * a [nav sidebar | separator | content] column. The sidebar selects a section —
 * General, Keybindings, Plugins, or a language — and the content pane shows the
 * matching form/list. Structured like the git plugin's view but simpler (no
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
        ViewRegions $regions,
        private readonly ViewHost $host,
        private readonly SettingsMouseRouter $mouse,
        private readonly Tui $tui,
        private readonly SettingsPages $pages,
        private readonly SettingsKeymapController $keymap,
        private readonly SettingsPluginsController $plugins,
        private readonly SettingsProvenance $provenance,
        private readonly ShadowedWriteWarning $shadowed,
        ViewRegistry $views,
    ) {
        // Minted, like git's. The nav is this view's fixed half, so it goes
        // into the sidebar once here — it used to be handed to PaneLayout as a
        // bare widget, which is why the two views' regions were mirror images
        // and neither shape could describe the other.
        $this->region = $regions->mint('settings');
        $this->region->sidebar()->add($this->nav);
        // One widget spans this sidebar, so it IS the resizable unit.
        $this->region->setSidebarPanes($this->nav);
        $views->register($this);
        $exit = $this->close(...);

        $this->nav
            ->onSelect($this->selectSection(...))
            ->onFocusNext($this->focusContent(...))
            ->onExit($exit);
        $this->form
            ->onChange($this->changeSetting(...))
            ->onReset($this->resetSetting(...))
            ->onFocusNext($this->focusNav(...))
            ->onExit($exit);
        $this->keymapList
            ->onRebind($this->rebind(...))
            ->onReset($this->resetBinding(...))
            ->onFocusNext($this->focusNav(...))
            ->onExit($exit);
    }

    #[\Override]
    public function viewId(): string
    {
        return 'settings';
    }

    #[\Override]
    public function ownsMainArea(): bool
    {
        return true;
    }

    #[\Override]
    public function isOpen(): bool
    {
        return $this->open;
    }

    /** Where focus should land when a popup (e.g. the app menu) closes over the view. */
    #[\Override]
    public function pointer(): ?ViewPointer
    {
        return $this->mouse;
    }

    /** No pane in the settings view offers a context menu. */
    #[\Override]
    public function contextMenus(): ?ViewMenuSource
    {
        return null;
    }

    #[\Override]
    public function focusTarget(): AbstractWidget&FocusableInterface
    {
        return $this->contentWidget();
    }

    #[\Override]
    public function toggle(): void
    {
        $this->open ? $this->close() : $this->open();
    }

    #[\Override]
    public function open(): void
    {
        if ($this->open) {
            return;
        }
        $this->open = true;
        // The (global, full-width) menu bar stays put; just drop any open
        // dropdown so its tracked hit region cannot go stale across the swap.
        $this->host->show($this->region);

        $this->selectSection('general'); // always open on General
        $this->host->focus($this->form);
        $this->host->invalidate();
    }

    #[\Override]
    public function close(): void
    {
        if (!$this->open) {
            return;
        }
        $this->open = false;
        // EditorArea::focus() is setFocus(widget()) + requestRender(), which
        // is what these two lines were doing by hand.
        $this->host->restore();
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
        if (null !== $event->row) {
            $this->form->selectKey($event->row);
        }
    }

    private function selectSection(string $id): void
    {
        // Which content widget is losing the slot, and whether the KEYBOARD is
        // on it. The `1`/`2` page jumps are pressed while the content has focus,
        // and the swap below unmounts that widget — so without moving focus the
        // keys kept going to a widget nobody could see, where Enter and Space
        // actuate a settings row and WRITE CONFIG. The visible page meanwhile
        // received nothing and looked frozen.
        //
        // Conditional, because the same swap driven from the NAV must not steal
        // focus from it: the nav is still mounted and is where the user is.
        $outgoing = 'keybindings' === $this->section ? $this->keymapList : $this->form;
        $followFocus = $this->host->focused() === $outgoing;

        $this->section = $id;
        $this->nav->setActive($id);
        $this->region->content()->clear();
        if ('keybindings' === $id) {
            $this->keymapList->setRows($this->keymap->rows());
            $this->region->content()->add($this->keymapList);
        }
        if ('keybindings' !== $id) {
            $this->form->setRows($this->rowsForSection(), $this->nav->activeLabel());
            $this->region->content()->add($this->form);
        }
        if ($followFocus) {
            $this->host->focus('keybindings' === $id ? $this->keymapList : $this->form);
        }
        $this->host->invalidate();
    }

    /**
     * The section owning the active nav id. Never null: the general section
     * claims every id and its tag priority sorts it last.
     */
    private function activeSection(): SettingsSection
    {
        return $this->pages->sectionFor($this->section);
    }

    /**
     * The rebuilt row for $key, or null when the page no longer has one.
     *
     * @param list<SettingsRow> $rows
     */
    private function rowFor(array $rows, string $key): ?SettingsRow
    {
        foreach ($rows as $row) {
            if ($row->key === $key) {
                return $row;
            }
        }

        return null;
    }

    /** @return list<SettingsRow> */
    private function rowsForSection(): array
    {
        return $this->activeSection()->rows($this->section);
    }

    /** Put the selected row back to its default, then repaint the page. */
    private function resetSetting(string $key): void
    {
        $this->activeSection()->reset($this->section, $key);
        $this->afterWrite($key, $this->shadowed->afterReset(...));
    }

    /**
     * Everything that follows a write to a config file: the provenance tags
     * are stale, the rows are stale, and this project may be about to ignore
     * what just landed.
     *
     * Spelled once because the two write paths drifted the moment they were
     * written separately — reset invalidated the tags and skipped the warning,
     * so pressing `r` on a shadowed row removed a global value and said
     * nothing while the project went on deciding it.
     *
     * @param \Closure(?SettingsRow): void $warn which of the two things just happened
     */
    private function afterWrite(string $key, \Closure $warn): void
    {
        $this->provenance->invalidate();
        $rows = $this->rowsForSection();
        $this->form->setRows($rows, $this->nav->activeLabel());
        $warn($this->rowFor($rows, $key));
    }

    /**
     * Apply a row's edit and repaint the page. FALSE propagates the section's
     * refusal to the form, which then keeps the inline editor open with the
     * text as typed — the rows are NOT re-pushed in that case, because doing
     * so is exactly what used to destroy it.
     */
    private function changeSetting(string $key, bool|int|string $value): bool
    {
        if (!$this->activeSection()->change($this->section, $key, $value)) {
            return false;
        }
        // This view is the single funnel for every form edit, so it is the one
        // place that knows a config file may just have moved under the
        // provenance tags. Refusals do not reach here, and nothing was written.
        $this->afterWrite($key, $this->shadowed->afterSave(...));

        return true;
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
            $this->form->setRows($this->rowsForSection(), $this->nav->activeLabel());
            $this->host->invalidate();
        }
    }

    private function rebind(string $action, string $key): void
    {
        $this->keymap->rebind($action, $key);
        $this->keymapList->setRows($this->keymap->rows());
    }

    private function resetBinding(string $action): void
    {
        $this->keymap->resetBinding($action);
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
    #[\Override]
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
        $this->host->focus($this->nav);
        $this->host->invalidate();
    }

    private function focusContent(): void
    {
        $this->host->focus($this->contentWidget());
        $this->host->invalidate();
    }
}
