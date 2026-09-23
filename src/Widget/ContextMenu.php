<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsChromeRoot;
use Porta\Container\AsIdeService;
use Porta\Container\AsWidget;
use Porta\I18n\BaseDirection;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Support\MenuNavigation;
use Porta\Widget\Support\StylesDefault;
use Porta\Workbench\Menu\MenuItem;
use Porta\Workbench\Menu\MenuPanel;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * A right-click context menu: a floating single-column list of
 * {@see MenuItem}s anchored at the pointer. Unlike {@see MenuBarWidget} it has
 * no title strip and **renders nothing in the layout** — it floats purely as an
 * overlay composited over the tree / tab bar / editor by the coordinator (see
 * {@see \Porta\Ide::refreshContextMenuOverlay()}), which also routes keyboard
 * to it modally while open. That keeps the menu independent of which widget owns
 * focus, so a right-click never disturbs the editor caret or tree selection.
 */
#[AsChromeRoot]
#[AsIdeService]
#[AsWidget('context-menu')]
final class ContextMenu extends OverlayWidget
{
    use KeybindingsTrait;
    use MenuNavigation;
    use StylesDefault;

    private BaseDirection $direction = BaseDirection::Ltr;

    public function __construct(?Styles $styles = null)
    {
        $this->initStyles($styles);
    }

    /**
     * The writing direction the panel lays its columns out for.
     *
     * A setter rather than a constructor argument, the shape {@see
     * \Porta\Ui\BidiTui::setPaneReorder()} uses: the direction arrives from the
     * locale long after this widget is built, changes while it is alive, and
     * every existing construction site would otherwise have to learn about it.
     */
    public function setTextDirection(BaseDirection $direction): void
    {
        $this->direction = $direction;
        $this->invalidate();
    }

    /** @var list<MenuItem> */
    private array $items = [];
    private int $selected = 0;
    /** Whether it is open as a select's option list rather than a menu of actions. */
    private bool $listbox = false;
    /** A listbox is at least as wide as the select it hangs from (panel columns, frame excluded). */
    private int $minWidth = 0;

    /**
     * Open the menu with $items and select its first non-separator entry.
     *
     * @param list<MenuItem> $items
     */
    public function open(array $items): void
    {
        if ([] === $items) {
            return;
        }
        $this->items = $items;
        $this->markOpen();
        $this->listbox = false;
        $this->minWidth = 0;
        $this->selected = MenuPanel::firstSelectable($items);
        $this->afterNavigate();
    }

    /**
     * Open as a select's option list — the browser's dropped-down `<select>`:
     * the highlight starts on the $current option rather than the first, and
     * the panel is at least $minWidth columns so it lines up under the box
     * it drops from. Keys, clicks, hover and outside-dismiss are the menu's.
     *
     * @param list<MenuItem> $items
     */
    public function openListbox(array $items, int $current, int $minWidth): void
    {
        if ([] === $items) {
            return;
        }
        $this->items = $items;
        $this->markOpen();
        $this->listbox = true;
        $this->minWidth = $minWidth;
        $this->selected = \array_key_exists($current, $items) ? $current : MenuPanel::firstSelectable($items);
        $this->afterNavigate();
    }

    /** Whether the open panel is a select's option list (drawn with the focused, heavy frame). */
    public function isListbox(): bool
    {
        return $this->open && $this->listbox;
    }

    #[\Override]
    protected function resetState(): void
    {
        $this->items = [];
        $this->selected = 0;
        $this->listbox = false;
        $this->minWidth = 0;
    }

    /** Natural width of the panel (unclamped; the coordinator clamps it to fit). */
    public function panelWidth(): int
    {
        return $this->open ? \max(MenuPanel::width($this->items), $this->minWidth) : 0;
    }

    /**
     * The panel's styled rows, each exactly $panelWidth wide; empty when closed.
     *
     * @return list<array{segment: string, separator: bool}>
     */
    public function segments(int $panelWidth): array
    {
        return $this->open ? MenuPanel::segments($this->items, $this->selected, $panelWidth, $this->styles, $this->direction) : [];
    }

    #[\Override]
    public function render(RenderContext $context): array
    {
        return []; // floats via the overlay; never occupies a layout row
    }

    public function handleInput(string $data): void
    {
        if (!$this->open) {
            return;
        }
        $kb = $this->getKeybindings();
        if ($kb->matches($data, 'menu_close')) {
            $this->close();

            return;
        }
        if ($kb->matches($data, 'menu_up')) {
            $this->selected = MenuPanel::step($this->items, $this->selected, -1);
            $this->afterNavigate();

            return;
        }
        if ($kb->matches($data, 'menu_down')) {
            $this->selected = MenuPanel::step($this->items, $this->selected, 1);
            $this->afterNavigate();

            return;
        }
        if ($kb->matches($data, 'menu_select')) {
            $this->runSelected();
        }

        // Any other key while open is swallowed (handled by the coordinator's modal routing).
    }

    /** Run the item at $index (from a mouse click) and close. */
    public function runItem(int $index): void
    {
        $this->runItemObject($this->items[$index] ?? null);
    }

    /**
     * Wheel over the menu: move the highlight by $delta selectable items (the
     * shared WheelStep distance), each step skipping separators and wrapping.
     * $dir * $delta is |$delta| — the notch count — without abs().
     */
    public function moveSelection(int $delta): void
    {
        if (!$this->isOpen()) {
            return;
        }
        $dir = $delta <=> 0;
        for ($n = 0, $count = $dir * $delta; $n < $count; ++$n) {
            $this->selected = MenuPanel::step($this->items, $this->selected, $dir);
        }
        $this->afterNavigate();
    }

    /**
     * Highlight the item at $index (from a hover). Skips separators/disabled and
     * no-ops when already selected; returns whether the highlight changed.
     */
    public function hoverItem(int $index): bool
    {
        $item = $this->items[$index] ?? null;
        if (null === $item || $item->separator || !$item->enabled() || $index === $this->selected) {
            return false;
        }
        $this->selected = $index;
        $this->afterNavigate();

        return true;
    }

    private function runSelected(): void
    {
        $this->runItemObject($this->items[$this->selected] ?? null);
    }

    private function runItemObject(?MenuItem $item): void
    {
        if (null !== $item && !$item->separator && $item->enabled() && null !== $item->run) {
            ($item->run)();
        }
        $this->close();
    }

    protected static function getDefaultKeybindings(): array
    {
        return [
            'menu_up' => [Key::UP],
            'menu_down' => [Key::DOWN],
            'menu_select' => [Key::ENTER],
            'menu_close' => [Key::ESCAPE],
        ];
    }
}
