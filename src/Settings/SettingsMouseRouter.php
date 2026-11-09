<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Input\HitTester;
use Porta\Input\MouseEvent;
use Porta\Input\MouseRouter;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Widget\Settings\PluginStoreWidget;
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
    // The region's divider is core's to tint and to drag (HoverRouter,
    // MouseRouter), as it is for every view.
    public function __construct(
        private HitTester $hit,
        private Tui $tui,
        private SettingsNavWidget $nav,
        private SettingsFormWidget $form,
        private KeybindingsListWidget $keys,
        // Trailing and optional, as on the view: the hand-built routers in the tests stand.
        private ?PluginStoreWidget $store = null,
    ) {}

    /** @return list<SettingsNavWidget|SettingsFormWidget|KeybindingsListWidget|PluginStoreWidget> */
    private function panes(): array
    {
        return \array_values(\array_filter([$this->nav, $this->form, $this->keys, $this->store]));
    }

    #[\Override]
    public function wheel(WheelGesture $gesture): bool
    {
        $mouse = $gesture->mouse;
        // Every settings list scrolls under the wheel as it does under its
        // arrow keys — the nav changes the shown section, exactly as from the
        // keyboard (see ArrowScrollTrait). No settings pane scrolls sideways.
        $pane = WheelAxis::Horizontal === $gesture->axis
            ? null
            : \array_find(
                $this->panes(),
                fn (SettingsNavWidget|SettingsFormWidget|KeybindingsListWidget|PluginStoreWidget $pane): bool => null !== $this->hit->hit($pane, $mouse->row, $mouse->col),
            );
        $pane?->scrollBy($gesture->step);

        return null !== $pane;
    }

    /** A left press on a settings pane (rects exist only while the view is swapped in). */
    #[\Override]
    public function press(MouseEvent $mouse): bool
    {
        $pressed = false;
        foreach ($this->panes() as $pane) {
            $rel = $this->hit->hit($pane, $mouse->row, $mouse->col);
            if (null === $rel) {
                continue;
            }
            $this->tui->setFocus($pane);
            $pane instanceof SettingsNavWidget ? $pane->handleClick($rel['row']) : $pane->handleClick($rel['row'], $rel['col']);
            $this->tui->requestRender();
            $pressed = true;
            break;
        }

        return $pressed;
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
        $rows = $this->hit->hover($mouse->row, $mouse->col, $this->nav, $this->form, $this->keys);
        // The store is a grid: what is under the pointer takes its column too.
        $rel = null === $this->store ? null : $this->hit->hit($this->store, $mouse->row, $mouse->col);
        $rows = (null !== $this->store && $this->store->hoverAt($rel['row'] ?? null, $rel['col'] ?? 0)) || $rows;
        // The pages' title rows are trails whose links tint by column.
        $formTitle = $this->form->hoverCrumb($this->titleColumn($this->form, $mouse));
        $keysTitle = $this->keys->hoverCrumb($this->titleColumn($this->keys, $mouse));
        $storeTitle = null !== $this->store && $this->store->hoverCrumb($this->titleColumn($this->store, $mouse));
        if ($rows || $formTitle || $keysTitle || $storeTitle) {
            $this->tui->requestRender();
        }
    }

    /** The pointer's column on $page's title row, or null when it is not on that row. */
    private function titleColumn(SettingsFormWidget|KeybindingsListWidget|PluginStoreWidget $page, MouseEvent $mouse): ?int
    {
        $rel = $this->hit->hit($page, $mouse->row, $mouse->col);

        return null !== $rel && 0 === $rel['row'] ? $rel['col'] : null;
    }
}
