<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsChromeRoot;
use Porta\Container\AsIdeService;
use Porta\Container\AsWidget;
use Porta\I18n\BaseDirection;
use Porta\Support\IntMath;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\BarChromeStyle;
use Porta\Widget\Render\TabState;
use Porta\Widget\Support\MenuNavigation;
use Porta\Widget\Support\StylesDefault;
use Porta\Workbench\Menu\Menu;
use Porta\Workbench\Menu\MenuPanel;
use Porta\Workbench\Menu\Mnemonics;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * The application menu bar: a title strip (`File  Edit  Navigate  …`) at the top
 * of the editor pane that opens a dropdown of {@see \Porta\Workbench\Menu\MenuItem}s. Sitting
 * inside the pane means every title shares the editor's columns, so each dropdown
 * anchors directly under its title. This widget **only ever renders its single
 * title row** so opening a menu never reflows the layout. The base
 * {@see OverlayWidget} `$open` flag means the bar holds the keys — armed by
 * F10 with a title lit, or with its dropdown showing
 * ({@see isDropdownShown()}). The dropdown's
 * styled rows are exposed via {@see dropdownSegments()} and composited over the
 * tab bar/editor by the coordinator, so the dropdown floats over the content
 * instead of pushing it down. Titles that would overflow a narrow pane are
 * dropped from the strip behind a `…`, itself a title that opens the first of
 * them (and the arrow keys still walk into them).
 */
#[AsChromeRoot]
#[AsIdeService]
#[AsWidget('menu')]
final class MenuBarWidget extends OverlayWidget implements FocusableInterface
{
    use FocusableTrait;
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

    /** @var list<Menu> */
    private array $menus = [];
    private int $openIndex = 0;
    private int $selected = 0;
    private ?int $hoveredIndex = null; // title hover-highlighted while no menu is open

    /**
     * Whether the open bar shows a dropdown. False is "armed" — F10's state,
     * as on a desktop: the bar has the keys and a title is lit, and ↓, Enter,
     * Space or a title's letter drops its menu.
     */
    private bool $dropped = true;

    /** @var list<array{index: int, start: int, width: int}> title strip layout from the last render */
    private array $titleLayout = [];

    /** @param list<Menu> $menus */
    public function setMenus(array $menus): self
    {
        $this->menus = $menus;

        return $this;
    }

    /** Open the dropdown for menu $index and focus its first selectable item. */
    public function open(int $index = 0): void
    {
        if ([] === $this->menus) {
            return;
        }
        $this->dropped = true;
        $this->markOpen();
        $this->openIndex = IntMath::clamp($index, 0, \count($this->menus) - 1);
        $this->selected = $this->firstSelectable($this->openIndex);
        $this->afterNavigate();
    }

    /** Take the keys with title $index lit and no dropdown: F10's arming. */
    public function arm(int $index = 0): void
    {
        if ([] === $this->menus) {
            return;
        }
        $this->dropped = false;
        $this->markOpen();
        $this->openIndex = IntMath::clamp($index, 0, \count($this->menus) - 1);
        $this->afterNavigate();
    }

    /** Whether a dropdown is showing — not merely the bar holding the keys. */
    public function isDropdownShown(): bool
    {
        return $this->open && $this->dropped;
    }

    #[\Override]
    protected function resetState(): void
    {
        $this->selected = 0;
        $this->hoveredIndex = null;
        $this->dropped = true;
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

    #[\Override]
    public function render(RenderContext $context): array
    {
        // Only the title row — never the dropdown — so opening a menu never
        // reflows the widgets below. The dropdown is composited over the tab
        // bar/editor by the coordinator (see Ide::refreshMenuOverlay()).
        return [$this->renderTitleRow($context->getColumns())];
    }

    private function renderTitleRow(int $columns): string
    {
        // Titles that do not fit are dropped, and a dropped title used to
        // leave nothing behind: at 60 columns the German and French bars end
        // at Ansicht / Affichage, and the row reads as though Hilfe / Aide do
        // not exist. They are still reachable — menu_left/menu_right cycle the
        // whole list, not the drawn part — so what was missing is the row
        // saying it was cut, the way every other cut in this UI does.
        $shown = $this->fittingTitles($columns);
        $mark = '';
        if ($shown < \count($this->menus)) {
            // A column held back for the mark, which may cost one more title:
            // a row filled to its edge cannot say that it was cut.
            $mark = Glyphs::ELLIPSIS;
            $shown = $this->fittingTitles($columns - \mb_strwidth($mark));
        }

        $line = '';
        $used = 0;
        $this->titleLayout = [];
        $letters = Mnemonics::assign(\array_map(static fn (Menu $menu): string => $menu->title, $this->menus));
        foreach (\array_slice($this->menus, offset: 0, length: $shown, preserve_keys: true) as $i => $menu) {
            $label = ' '.$menu->title.' ';
            $active = $this->open && $i === $this->openIndex;
            $hovered = !$this->open && $i === $this->hoveredIndex;
            // Menu titles are chrome-bar items: open = active, else hovered, else
            // rest — shaded by the shared {@see BarChromeStyle} so a title reads
            // like a tab. Only the rest background differs: titles sit on the
            // HeaderBar strip, not the tabs' Toolbar. An armed title is lit but
            // keeps its padding: the rails belong to a dropdown under it.
            // Hovered, the whole title underlines: the letter's own closing
            // SGR would cut that underline short after the letter.
            $text = $hovered ? $menu->title : MenuPanel::underlineLetter($menu->title, $menu->title, $letters[$i]);
            $line .= $this->title($text, BarChromeStyle::of($this->styles, TabState::of($active, $hovered), Role::HeaderBar), $active && $this->dropped, $hovered);
            $this->titleLayout[] = ['index' => $i, 'start' => $used, 'width' => \mb_strwidth($label)];
            $used += \mb_strwidth($label);
        }
        $pad = \max(0, $columns - $used - \mb_strwidth($mark));
        if ('' !== $mark) {
            // The mark is a title too, standing for the first menu it hides:
            // a click drops that one under it, and it is lit while any hidden
            // menu is — the arrows walk on into them from the last title.
            $this->titleLayout[] = ['index' => $shown, 'start' => $used + $pad, 'width' => \mb_strwidth($mark)];
            $state = TabState::of($this->open && $this->openIndex >= $shown, !$this->open && $shown === $this->hoveredIndex);
            $mark = BarChromeStyle::of($this->styles, $state, Role::HeaderBar)->apply($mark);
        }

        return $line.$this->styles->of(Role::HeaderBar)->apply(\str_repeat(' ', $pad)).$mark;
    }

    /**
     * One title, ` Title ` wide in every state. The open one swaps its padding
     * for the heavy rails of the dropdown hanging under it — `┃File┃` — so the
     * title and its menu read as one shape, colour or not; the pointer
     * underlines the text alone, the form controls' hover.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- open and hovered are the title's state, not a second responsibility.
    private function title(string $title, Style $style, bool $open, bool $hovered): string
    {
        if ($open) {
            $rail = $style->withBold(false)->apply(Glyphs::FIELD_FOCUS_VERTICAL);

            return $rail.$style->apply($title).$rail;
        }

        return $style->apply(' '.($hovered ? $style->withUnderline()->apply($title).$style->getAnsiRestore() : $title).' ');
    }

    /** How many titles fit in $columns, in order. */
    private function fittingTitles(int $columns): int
    {
        $used = 0;
        $count = 0;
        foreach ($this->menus as $menu) {
            $used += \mb_strwidth(' '.$menu->title.' ');
            if ($used > $columns) {
                break;
            }
            ++$count;
        }

        return $count;
    }

    /** Natural width of the open menu's dropdown panel (unclamped; caller clamps to fit). */
    public function panelWidth(): int
    {
        if (!$this->isDropdownShown() || [] === $this->menus) {
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
        if (!$this->isDropdownShown() || [] === $this->menus) {
            return [];
        }

        return MenuPanel::segments($this->menus[$this->openIndex]->items, $this->selected, $panelWidth, $this->styles, $this->direction);
    }

    #[\Override]
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
        if (!$this->dropped) {
            $this->handleArmedInput($data);

            return;
        }
        // A letter runs the item it is the access letter of.
        $letter = MenuPanel::itemForKey($this->menus[$this->openIndex]->items, $data);
        if (null !== $letter) {
            $this->selected = $letter;
            $this->runSelected();

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

    /**
     * Armed: ←/→ move the lit title, ↓, Enter or Space drop its menu, a
     * title's letter drops that one. Anything else is swallowed.
     */
    private function handleArmedInput(string $data): void
    {
        $kb = $this->getKeybindings();
        $count = \count($this->menus);
        $step = match (true) {
            $kb->matches($data, 'menu_left') => -1,
            $kb->matches($data, 'menu_right') => 1,
            default => 0,
        };
        if (0 !== $step) {
            $this->openIndex = ($this->openIndex + $step + $count) % $count;
            $this->afterNavigate();

            return;
        }
        if ($kb->matches($data, 'menu_down') || $kb->matches($data, 'menu_select')) {
            $this->open($this->openIndex);

            return;
        }
        $title = Mnemonics::pick(\array_map(static fn (Menu $menu): string => $menu->title, $this->menus), $data);
        if (null !== $title) {
            $this->open($title);
        }
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

    /** Move the selection by $dir, skipping separators and wrapping. */
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
            'menu_select' => [Key::ENTER, Key::SPACE],
            'menu_close' => [Key::ESCAPE],
        ];
    }
}
