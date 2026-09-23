<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Input\HitTester;
use Porta\Input\MouseEvent;
use Porta\Input\MouseRouter;
use Porta\Layout\ViewRegions;
use Porta\Widget\SeparatorWidget;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Widget\Settings\SettingsFormWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Workbench\View\ViewPointer;
use Porta\Workbench\View\WheelAxis;
use Porta\Workbench\View\WheelGesture;
use Symfony\Component\Tui\Tui;

/**
 * Mouse routing for the settings view's panes — the nav sidebar, the settings
 * form and the keybindings list. Owns those widgets so {@see MouseRouter} does
 * not. Like the git router, its panes are live only while the settings view is
 * swapped into the main area, so its hit tests miss otherwise. It carries no
 * cross-event drag state (settings has none).
 */
final readonly class SettingsMouseRouter implements ViewPointer
{
    private SeparatorWidget $separator;

    public function __construct(
        private HitTester $hit,
        private Tui $tui,
        private SettingsNavWidget $nav,
        private SettingsFormWidget $form,
        private KeybindingsListWidget $keys,
        ViewRegions $regions,
    ) {
        // Held for the divider hover tint only — the RECTS are core's now: it
        // mints this region, so it hit-tests its own widgets for resize arming.
        $this->separator = $regions->mint('settings')->separator();
    }

    #[\Override]
    public function wheel(WheelGesture $gesture): bool
    {
        if (WheelAxis::Horizontal === $gesture->axis) {
            return false; // no settings pane scrolls sideways
        }
        $mouse = $gesture->mouse;
        // Every settings list scrolls under the wheel as it does under its
        // arrow keys — the nav changes the shown section, exactly as from the
        // keyboard (see ArrowScrollTrait).
        foreach ([$this->nav, $this->form, $this->keys] as $pane) {
            if (null === $this->hit->hit($pane, $mouse->row, $mouse->col)) {
                continue;
            }

            $pane->scrollBy($gesture->step);

            return true;
        }

        return false;
    }

    /** A left press on a settings pane (rects exist only while the view is swapped in). */
    #[\Override]
    public function press(MouseEvent $mouse): bool
    {
        if (null !== ($rel = $this->hit->hit($this->nav, $mouse->row, $mouse->col))) {
            $this->tui->setFocus($this->nav);
            $this->nav->handleClick($rel['row']);
            $this->tui->requestRender();

            return true;
        }
        if (null !== ($rel = $this->hit->hit($this->form, $mouse->row, $mouse->col))) {
            $this->tui->setFocus($this->form);
            $this->form->handleClick($rel['row'], $rel['col']);
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
    /** Nothing in the settings view arms a drag; the stage exists for the views that do. */
    #[\Override]
    public function continueDrag(MouseEvent $mouse): bool
    {
        return false;
    }

    #[\Override]
    public function cancelDrag(): void {}

    #[\Override]
    public function hover(MouseEvent $mouse): void
    {
        $listsChanged = $this->hit->hover($mouse->row, $mouse->col, $this->nav, $this->form, $this->keys);
        $sepChanged = $this->hit->hoverDivider($mouse->row, $mouse->col, $this->separator);

        if ($listsChanged || $sepChanged) {
            $this->tui->requestRender();
        }
    }
}
