<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Config\SettingsController;
use Porta\Config\SettingsKeymapController;
use Porta\Config\SettingsLanguageController;
use Porta\Config\SettingsLspController;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Widget\Settings\SettingsFormWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * The full-screen settings view: swaps the main area's tree|editor children for
 * a [nav sidebar | separator | content] column. The sidebar selects a section —
 * General, Keybindings, or a language — and the content pane shows the matching
 * form/list. Structured like {@see \Porta\Git\GitViewController} but simpler (no
 * poll loop, no availability gate).
 */
final class SettingsViewController
{
    private bool $open = false;
    /** 'general' | 'keybindings' | 'lang:<id>' — the active section. */
    private string $section = 'general';

    /**
     * @param list<AbstractWidget> $editArea the main container's normal children (tree, separator, editor pane)
     * @param \Closure(): void     $onClosed restore focus/render after the swap back
     */
    public function __construct(
        private readonly SettingsNavWidget $nav,
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
        private readonly SettingsLanguageController $languages,
        private readonly SettingsLspController $lsp,
        private readonly \Closure $onClosed,
    ) {
        $exit = fn () => $this->close();

        $this->nav
            ->onSelect(fn (string $id) => $this->selectSection($id))
            ->onFocusNext(fn () => $this->focusContent())
            ->onExit($exit);
        $this->form
            ->onChange(fn (string $key, bool|int|string $value) => $this->changeSetting($key, $value))
            ->onFocusNext(fn () => $this->focusNav())
            ->onExit($exit);
        $this->keymapList
            ->onRebind(fn (string $action, string $key) => $this->rebind($action, $key))
            ->onFocusNext(fn () => $this->focusNav())
            ->onExit($exit);
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
        $this->main->add($this->settingsArea);

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
        foreach ($this->editArea as $widget) {
            $this->main->add($widget);
        }
        ($this->onClosed)();
    }

    /**
     * Show a section's content pane. Does not steal focus — the sidebar keeps it
     * while navigating (Tab moves to the content).
     */
    private function selectSection(string $id): void
    {
        $this->section = $id;
        $this->nav->setActive($id);
        $this->settingsContent->clear();
        if ('keybindings' === $id) {
            $this->keymapList->setRows($this->keymap->rows());
            $this->settingsContent->add($this->keymapList);
        } else {
            $this->form->setRows($this->rowsForSection());
            $this->settingsContent->add($this->form);
        }
        $this->tui->requestRender();
    }

    /**
     * The form rows for the active (General or language) section.
     *
     * @return list<array{key: string, label: string, kind: string, value: bool|int|string, restart: bool, options?: list<string>, min?: int, max?: int, hint?: string}>
     */
    private function rowsForSection(): array
    {
        if (!str_starts_with($this->section, 'lang:')) {
            return $this->settings->formRows();
        }
        $language = substr($this->section, 5);

        return [...$this->languages->rows($language), ...$this->lsp->rows($language)];
    }

    private function changeSetting(string $key, bool|int|string $value): void
    {
        if (str_starts_with($this->section, 'lang:')) {
            $language = substr($this->section, 5);
            if ('lspServer' === $key) {
                $this->lsp->changeLspServer($language, (string) $value);
            } else {
                $this->languages->changeLanguageSetting($language, $key, $value);
            }
        } else {
            $this->settings->changeSetting($key, $value);
        }
        $this->form->setRows($this->rowsForSection());
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
