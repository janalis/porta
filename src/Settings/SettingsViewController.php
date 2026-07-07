<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Config\SettingsController;
use Porta\Config\SettingsKeymapController;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Widget\Settings\SettingsFormWidget;
use Porta\Widget\Settings\SettingsTabsWidget;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * The full-screen settings view: swaps the main area's tree|editor children for
 * a [tabs | content] column, where content is either the editor-settings form
 * or the rebindable-keybindings list. Structured like {@see \Porta\Git\GitViewController}
 * but simpler — no git poll loop and no availability gate.
 */
final class SettingsViewController
{
    private bool $open = false;
    /** 'settings' | 'keybindings' — which tab owns the content pane. */
    private string $mode = 'settings';

    /**
     * @param list<AbstractWidget> $editArea the main container's normal children (tree, separator, editor pane)
     * @param \Closure(): void     $onClosed restore focus/render after the swap back
     */
    public function __construct(
        private readonly SettingsTabsWidget $tabs,
        private readonly SettingsFormWidget $form,
        private readonly KeybindingsListWidget $keymapList,
        private readonly ContainerWidget $main,
        private readonly ContainerWidget $settingsArea,
        private readonly ContainerWidget $settingsContent,
        private readonly MenuBarWidget $menuBar,
        private readonly array $editArea,
        private readonly Tui $tui,
        private readonly SettingsController $settings,
        private readonly SettingsKeymapController $keymap,
        private readonly \Closure $onClosed,
    ) {
        $exit = fn () => $this->close();
        $next = fn () => $this->focusNext();

        $this->tabs->onSelect(fn (string $mode) => $this->switchMode($mode));
        $this->form
            ->onChange(fn (string $key, bool|int|string $value) => $this->changeSetting($key, $value))
            ->onShowKeybindings(fn () => $this->switchMode('keybindings'))
            ->onFocusNext($next)
            ->onExit($exit);
        $this->keymapList
            ->onRebind(fn (string $action, string $key) => $this->rebind($action, $key))
            ->onShowSettings(fn () => $this->switchMode('settings'))
            ->onFocusNext($next)
            ->onExit($exit);
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    /** Where focus should land when a popup (e.g. the app menu) closes over the view. */
    public function focusTarget(): AbstractWidget&FocusableInterface
    {
        return 'keybindings' === $this->mode ? $this->keymapList : $this->form;
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
        $this->main->add($this->settingsArea);

        // Always open on the Settings tab.
        $this->mode = 'settings';
        $this->tabs->setActive('settings');
        $this->form->setRows($this->settings->formRows());
        $this->settingsContent->clear();
        $this->settingsContent->add($this->form);
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
        foreach ($this->editArea as $widget) {
            $this->main->add($widget);
        }
        ($this->onClosed)();
    }

    /**
     * Swap the content pane between the Settings form and the Keybindings list.
     * Only ever reached with the other mode (the tab strip fires solely for the
     * inactive tab and the in-pane switch keys always cross over).
     */
    private function switchMode(string $mode): void
    {
        $this->mode = $mode;
        $this->tabs->setActive($mode);
        $this->settingsContent->clear();
        if ('keybindings' === $mode) {
            $this->keymapList->setRows($this->keymap->rows());
            $this->settingsContent->add($this->keymapList);
            $this->tui->setFocus($this->keymapList);
        } else {
            $this->form->setRows($this->settings->formRows());
            $this->settingsContent->add($this->form);
            $this->tui->setFocus($this->form);
        }
        $this->tui->requestRender();
    }

    private function changeSetting(string $key, bool|int|string $value): void
    {
        $this->settings->changeSetting($key, $value);
        $this->form->setRows($this->settings->formRows());
    }

    private function rebind(string $action, string $key): void
    {
        $this->keymap->rebind($action, $key);
        // The live binding only changes next launch, but re-reading clears the
        // capture prompt and keeps the list consistent.
        $this->keymapList->setRows($this->keymap->rows());
    }

    /** One focusable pane per tab today, so this simply re-focuses it. */
    private function focusNext(): void
    {
        $this->tui->setFocus('keybindings' === $this->mode ? $this->keymapList : $this->form);
        $this->tui->requestRender();
    }
}
