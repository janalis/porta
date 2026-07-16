<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsWidget;
use Porta\Ui\MenuItem;
use Porta\Ui\MenuPanel;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\Styles;
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
#[AsWidget('context-menu')]
final class ContextMenu extends OverlayWidget
{
    use KeybindingsTrait;

    private readonly Styles $styles;

    public function __construct(?Styles $styles = null)
    {
        $this->styles = $styles ?? new Styles(DarkTheme::definition());
    }

    /** @var list<MenuItem> */
    private array $items = [];
    private int $selected = 0;

    /** @var (callable(): void)|null fired on open/navigate so the coordinator re-places the overlay */
    private $onNavigate = null;

    public function onNavigate(callable $callback): self
    {
        $this->onNavigate = $callback;

        return $this;
    }

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
        $this->open = true;
        $this->selected = MenuPanel::firstSelectable($items);
        $this->afterNavigate();
    }

    protected function resetState(): void
    {
        $this->items = [];
        $this->selected = 0;
    }

    public function itemCount(): int
    {
        return \count($this->items);
    }

    /** Natural width of the panel (unclamped; the coordinator clamps it to fit). */
    public function panelWidth(): int
    {
        return $this->open ? MenuPanel::width($this->items) : 0;
    }

    /**
     * The panel's styled rows, each exactly $panelWidth wide; empty when closed.
     *
     * @return list<array{segment: string, separator: bool}>
     */
    public function segments(int $panelWidth): array
    {
        return $this->open ? MenuPanel::segments($this->items, $this->selected, $panelWidth, $this->styles) : [];
    }

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

    private function afterNavigate(): void
    {
        $this->invalidate();
        if (null !== $this->onNavigate) {
            ($this->onNavigate)();
        }
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
