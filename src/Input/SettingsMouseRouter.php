<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Widget\SeparatorWidget;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Widget\Settings\SettingsFormWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Tui;

/**
 * Mouse routing for the settings view's panes — the nav sidebar, the settings
 * form and the keybindings list. Owns those widgets so {@see MouseRouter} does
 * not. Like the git router, its panes are live only while the settings view is
 * swapped into the main area, so its hit tests miss otherwise. It carries no
 * cross-event drag state (settings has none).
 */
final class SettingsMouseRouter
{
    public function __construct(
        private readonly HitTester $hit,
        private readonly Tui $tui,
        private readonly SettingsNavWidget $nav,
        private readonly SettingsFormWidget $form,
        private readonly KeybindingsListWidget $keys,
        private readonly SeparatorWidget $separator,
    ) {}

    /** The sidebar's drag column (identity for the layout's resize arming). */
    public function separatorRect(): ?WidgetRect
    {
        return $this->hit->rect($this->separator);
    }

    /** The sidebar's left edge (the nav spans the sidebar width). */
    public function leftRect(): ?WidgetRect
    {
        return $this->hit->rect($this->nav);
    }

    /** Vertical wheel over the keybindings list; returns whether it scrolled. */
    public function scrollVertical(MouseEvent $mouse): bool
    {
        // Every settings list scrolls under the wheel as it does under its
        // arrow keys — the nav changes the shown section, exactly as from the
        // keyboard (see ArrowScrollTrait).
        foreach ([$this->nav, $this->form, $this->keys] as $pane) {
            if (null === $this->hit->hit($pane, $mouse->row, $mouse->col)) {
                continue;
            }

            $pane->scrollBy(WheelStep::rows($mouse));

            return true;
        }

        return false;
    }

    /** A left press on a settings pane (rects exist only while the view is swapped in). */
    public function tryPaneClick(MouseEvent $mouse): bool
    {
        if (null !== ($rel = $this->hit->hit($this->nav, $mouse->row, $mouse->col))) {
            $this->tui->setFocus($this->nav);
            $this->nav->handleClick($rel['row']);
            $this->tui->requestRender();

            return true;
        }
        if (null !== ($rel = $this->hit->hit($this->form, $mouse->row, $mouse->col))) {
            $this->tui->setFocus($this->form);
            $this->form->handleClick($rel['row']);
            $this->tui->requestRender();

            return true;
        }
        if (null !== ($rel = $this->hit->hit($this->keys, $mouse->row, $mouse->col))) {
            $this->tui->setFocus($this->keys);
            $this->keys->handleClick($rel['row']);
            $this->tui->requestRender();

            return true;
        }

        return false;
    }

    /**
     * The nav sidebar and the keybindings list tint on hover; a miss clears
     * them. Called after the menu bar has had its turn.
     */
    public function hover(MouseEvent $mouse): void
    {
        $listsChanged = $this->hit->hover($mouse->row, $mouse->col, $this->nav, $this->form, $this->keys);
        $sepChanged = $this->hit->hoverDivider($mouse->row, $mouse->col, $this->separator);

        if ($listsChanged || $sepChanged) {
            $this->tui->requestRender();
        }
    }
}
