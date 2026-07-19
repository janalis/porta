<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsWidget;
use Porta\Support\IntMath;
use Porta\Ui\Menu;
use Porta\Ui\MenuPanel;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\BarChromeStyle;
use Porta\Widget\Render\TabState;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * The application menu bar: a title strip (`File  Edit  Navigate  …`) at the top
 * of the editor pane that opens a dropdown of {@see \Porta\Ui\MenuItem}s. Sitting
 * inside the pane means every title shares the editor's columns, so each dropdown
 * anchors directly under its title. This widget **only ever renders its single
 * title row** so opening a menu never reflows the layout; the base
 * {@see OverlayWidget} `$open` flag means a dropdown is showing. The dropdown's
 * styled rows are exposed via {@see dropdownSegments()} and composited over the
 * tab bar/editor by the coordinator, so the dropdown floats over the content
 * instead of pushing it down. Titles that would overflow a narrow pane are
 * dropped from the strip (they stay reachable via F10 + arrow keys).
 */
#[AsWidget('menu')]
final class MenuBarWidget extends OverlayWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    private readonly Styles $styles;

    public function __construct(?Styles $styles = null)
    {
        $this->styles = $styles ?? Styles::dark();
    }

    /** @var list<Menu> */
    private array $menus = [];
    private int $openIndex = 0;
    private int $selected = 0;
    private ?int $hoveredIndex = null; // title hover-highlighted while no menu is open

    /** @var list<array{index: int, start: int, width: int}> title strip layout from the last render */
    private array $titleLayout = [];

    /** @var (callable(): void)|null fired whenever the open/selection state changes, so the coordinator can re-place the floating dropdown */
    private $onNavigate = null;

    /** @param list<Menu> $menus */
    public function setMenus(array $menus): self
    {
        $this->menus = $menus;

        return $this;
    }

    public function onNavigate(callable $callback): self
    {
        $this->onNavigate = $callback;

        return $this;
    }

    /** Open the dropdown for menu $index and focus its first selectable item. */
    public function open(int $index = 0): void
    {
        if ([] === $this->menus) {
            return;
        }
        $this->open = true;
        $this->openIndex = IntMath::clamp($index, 0, \count($this->menus) - 1);
        $this->selected = $this->firstSelectable($this->openIndex);
        $this->afterNavigate();
    }

    private function afterNavigate(): void
    {
        $this->invalidate();
        if (null !== $this->onNavigate) {
            ($this->onNavigate)();
        }
    }

    protected function resetState(): void
    {
        $this->selected = 0;
        $this->hoveredIndex = null;
    }

    /**
     * Hover-highlight the title at $index (or clear with null), used while no menu
     * is open. Returns whether the highlight changed, so the caller re-renders only
     * when needed under the flood of mouse-motion events.
     */
    public function hoverTitle(?int $index): bool
    {
        if ($index === $this->hoveredIndex) {
            return false;
        }
        $this->hoveredIndex = $index;
        $this->invalidate();

        return true;
    }

    /**
     * Highlight the open menu's dropdown item at $index (from a hover). Skips
     * separators and no-ops when already selected; returns whether it changed.
     */
    public function hoverItem(int $index): bool
    {
        if ([] === $this->menus) {
            return false;
        }
        $item = $this->menus[$this->openIndex]->items[$index] ?? null;
        if (null === $item || $item->separator || !$item->enabled() || $index === $this->selected) {
            return false;
        }
        $this->selected = $index;
        $this->afterNavigate();

        return true;
    }

    public function render(RenderContext $context): array
    {
        // Only the title row — never the dropdown — so opening a menu never
        // reflows the widgets below. The dropdown is composited over the tab
        // bar/editor by the coordinator (see Ide::refreshMenuOverlay()).
        return [$this->renderTitleRow($context->getColumns())];
    }

    private function renderTitleRow(int $columns): string
    {
        $line = '';
        $used = 0;
        $this->titleLayout = [];
        foreach ($this->menus as $i => $menu) {
            $label = ' '.$menu->title.' ';
            $width = mb_strwidth($label);
            if (($used + $width) > $columns) {
                break; // narrow pane: drop titles that would overflow the row
            }
            $active = $this->open && $i === $this->openIndex;
            $hovered = !$this->open && $i === $this->hoveredIndex;
            // Menu titles are chrome-bar items: open = active, else hovered, else
            // rest — shaded by the shared {@see BarChromeStyle} so a title reads
            // like a tab. Only the rest background differs: titles sit on the
            // HeaderBar strip, not the tabs' Toolbar.
            $line .= BarChromeStyle::of($this->styles, TabState::of($active, $hovered), Role::HeaderBar)->apply($label);
            $this->titleLayout[] = ['index' => $i, 'start' => $used, 'width' => $width];
            $used += $width;
        }

        return $line.$this->styles->of(Role::HeaderBar)->apply(str_repeat(' ', max(0, $columns - $used)));
    }

    /** Natural width of the open menu's dropdown panel (unclamped; caller clamps to fit). */
    public function panelWidth(): int
    {
        if (!$this->open || [] === $this->menus) {
            return 0;
        }

        return MenuPanel::width($this->menus[$this->openIndex]->items);
    }

    /** Absolute start column of menu title $index in the title strip. */
    public function titleStart(int $index): int
    {
        return $this->titleLayout[$index]['start'] ?? 0;
    }

    public function openIndex(): int
    {
        return $this->openIndex;
    }

    /**
     * The open menu's dropdown rows as styled segments (one per item, in order;
     * row i is screen row 1+i), each exactly $panelWidth wide. Empty when no menu
     * is open. Positioning and compositing are the coordinator's job.
     *
     * @return list<array{segment: string, separator: bool}>
     */
    public function dropdownSegments(int $panelWidth): array
    {
        if (!$this->open || [] === $this->menus) {
            return [];
        }

        return MenuPanel::segments($this->menus[$this->openIndex]->items, $this->selected, $panelWidth, $this->styles);
    }

    public function handleInput(string $data): void
    {
        if (!$this->open) {
            return;
        }
        $kb = $this->getKeybindings();
        $count = \count($this->menus);

        if ($kb->matches($data, 'menu_close')) {
            $this->close();

            return;
        }
        if ($kb->matches($data, 'menu_left')) {
            $this->open(($this->openIndex - 1 + $count) % $count);

            return;
        }
        if ($kb->matches($data, 'menu_right')) {
            $this->open(($this->openIndex + 1) % $count);

            return;
        }
        if ($kb->matches($data, 'menu_up')) {
            $this->selected = $this->step(-1);
            $this->afterNavigate();

            return;
        }
        if ($kb->matches($data, 'menu_down')) {
            $this->selected = $this->step(1);
            $this->afterNavigate();

            return;
        }
        if ($kb->matches($data, 'menu_select')) {
            $this->runSelected();
        }

        // Any other key while open is swallowed (does not leak to the editor).
    }

    /** Which menu title sits at column $col of the title strip, or null. */
    public function titleAtColumn(int $col): ?int
    {
        foreach ($this->titleLayout as $entry) {
            if ($col >= $entry['start'] && $col < ($entry['start'] + $entry['width'])) {
                return $entry['index'];
            }
        }

        return null;
    }

    /** Run the dropdown item at $index (from a mouse click) and close. */
    public function runItem(int $index): void
    {
        $items = $this->menus[$this->openIndex]->items;
        $item = $items[$index] ?? null;
        if (null !== $item && !$item->separator && $item->enabled() && null !== $item->run) {
            ($item->run)();
        }
        $this->close();
    }

    private function runSelected(): void
    {
        $item = $this->menus[$this->openIndex]->items[$this->selected] ?? null;
        if (null !== $item && !$item->separator && $item->enabled() && null !== $item->run) {
            ($item->run)();
        }
        $this->close();
    }

    private function firstSelectable(int $menuIndex): int
    {
        return MenuPanel::firstSelectable($this->menus[$menuIndex]->items);
    }

    /** Move the selection by $dir, skipping separators and wrapping. */
    /**
     * Wheel over the open dropdown: move the highlight by $delta selectable
     * items (the shared WheelStep distance), each step skipping separators and
     * wrapping. $dir * $delta is |$delta| — the notch count — without abs().
     */
    public function moveSelection(int $delta): void
    {
        if (!$this->isOpen()) {
            return;
        }
        $dir = $delta <=> 0;
        for ($n = 0, $count = $dir * $delta; $n < $count; ++$n) {
            $this->selected = $this->step($dir);
        }
        $this->afterNavigate();
    }

    private function step(int $dir): int
    {
        return MenuPanel::step($this->menus[$this->openIndex]->items, $this->selected, $dir);
    }

    protected static function getDefaultKeybindings(): array
    {
        return [
            'menu_up' => [Key::UP],
            'menu_down' => [Key::DOWN],
            'menu_left' => [Key::LEFT],
            'menu_right' => [Key::RIGHT],
            'menu_select' => [Key::ENTER],
            'menu_close' => [Key::ESCAPE],
        ];
    }
}
