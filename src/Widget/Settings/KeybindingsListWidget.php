<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\Config\KeybindingRow;
use Porta\Container\AsWidget;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\Role;
use Porta\Ui\ShortcutFormatter;
use Porta\Ui\Theme\Styles;
use Porta\Widget\HoverableList;
use Porta\Widget\PaneNavigationCallbacks;
use Porta\Widget\Render\HeaderRenderer;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Support\ArrowScrollTrait;
use Porta\Widget\Support\FillsPaneTrait;
use Porta\Widget\Support\HoverTrait;
use Porta\Widget\Support\InputHook;
use Porta\Widget\Support\StylesDefault;
use Porta\Widget\Support\WheelScrollable;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * The rebindable-action list: one row per action as `label … shortcut`. Enter
 * (or a click on the selected row) arms capture; the next keypress is handed
 * raw to onRebind for the controller to parse, validate and persist — Escape
 * cancels capture instead of leaving the view. Rebinds take effect next launch.
 *
 */
#[AsWidget('settings-keys', flex: 1)]
final class KeybindingsListWidget extends AbstractWidget implements FocusableInterface, HoverableList, MenuOverlayHost, VerticallyExpandableInterface, WheelScrollable
{
    use ArrowScrollTrait;
    use FillsPaneTrait;
    use FocusableTrait;
    use HoverTrait;
    use InputHook;
    use KeybindingsTrait;
    use MenuOverlayHostTrait;
    use PaneNavigationCallbacks;
    use StylesDefault;

    /** @var list<KeybindingRow> */
    private array $rows = [];
    private readonly ListSelection $list;
    private int $lastRows = 10;
    /** The row index awaiting a keypress to rebind, or null when not capturing. */
    private ?int $capturingIndex = null;

    /** @var (callable(string, string): void)|null a captured key (action, raw key data) to rebind */
    private $onRebind = null;
    /** @var (callable(): void)|null `1`: switch to the Settings tab */
    private $onShowSettings = null;

    public function __construct(
        private readonly Translator $translator,
        ?Styles $styles = null,
    ) {
        $this->initStyles($styles);
        $this->list = new ListSelection();
        $this->addStyleClass('list'); // the theme's .list::selected(:focus) rules style the selection
    }

    /** @param callable(string, string): void $callback */
    public function onRebind(callable $callback): self
    {
        $this->onRebind = $callback;

        return $this;
    }

    public function onShowSettings(callable $callback): self
    {
        $this->onShowSettings = $callback;

        return $this;
    }

    /**
     * Replace the rows, keeping the selection in range and dropping any capture.
     *
     * @param list<KeybindingRow> $rows
     */
    public function setRows(array $rows): void
    {
        $this->rows = $rows;
        $this->list->clamp(\count($rows));
        $this->capturingIndex = null;
        $this->hoveredIndex = null;
        $this->invalidate();
    }

    public function isCapturing(): bool
    {
        return null !== $this->capturingIndex;
    }

    public function selectedAction(): ?string
    {
        return ($this->rows[$this->list->selected()] ?? null)?->action;
    }

    public function handleInput(string $data): void
    {
        if ($this->inputHookHandled($data)) {
            return;
        }
        $kb = $this->getKeybindings();

        // Capture mode consumes exactly the next key: Esc cancels, anything
        // else is handed raw to the controller to parse/validate/persist.
        if (null !== $this->capturingIndex) {
            $row = $this->rows[$this->capturingIndex] ?? null;
            $this->capturingIndex = null;
            if (!$kb->matches($data, 'exit_view') && null !== $row && null !== $this->onRebind) {
                ($this->onRebind)($row->action, $data);
            }
            $this->invalidate();

            return;
        }

        if ($kb->matches($data, 'exit_view')) {
            $this->fire($this->onExit);

            return;
        }
        if ($kb->matches($data, 'focus_next_pane')) {
            $this->fire($this->onFocusNext);

            return;
        }
        if ($kb->matches($data, 'show_settings')) {
            $this->fire($this->onShowSettings);

            return;
        }
        if ($kb->matches($data, 'rebind')) {
            $this->armCapture();

            return;
        }
        if (ListNavigation::handleKey($data, $kb, $this->list, \count($this->rows), page: $this->lastRows)) {
            $this->invalidate();
        }
    }

    /** A click selects the row; a click on the already-selected row arms capture. */
    public function handleClick(int $relRow): void
    {
        $index = $this->rowIndexAt($relRow);
        if (null === $index) {
            return;
        }
        $armCapture = $index === $this->list->selected() && null === $this->capturingIndex;
        if ($armCapture) {
            $this->capturingIndex = $index;
        }
        if (!$armCapture) {
            $this->list->select($index);
        }
        $this->invalidate();
    }

    public function hoverRow(int $relRow): bool
    {
        return $this->setHovered($this->rowIndexAt($relRow));
    }

    private function armCapture(): void
    {
        if ([] !== $this->rows) {
            $this->capturingIndex = $this->list->selected();
            $this->invalidate();
        }
    }

    private function rowIndexAt(int $relRow): ?int
    {
        return $this->list->rowIndexAt($relRow, \count($this->rows));
    }

    public function render(RenderContext $context): array
    {
        $width = $context->getColumns();
        $rows = max(1, $context->getRows() > 0 ? $context->getRows() : 12);
        $this->lastRows = $rows;

        $title = ' '.$this->translator->trans('Keybindings — %count% actions · ⏎ to rebind', ['%count%' => \count($this->rows)]);
        $lines = [HeaderRenderer::render($title, $width, $this->styles->of(Role::HeaderBar)->withBold())];

        $scroll = $this->list->follow($rows - 1);
        for ($row = 1; $row < $rows; ++$row) {
            $index = $scroll + $row - 1;
            $entry = $this->rows[$index] ?? null;
            if (null === $entry) {
                $lines[] = str_repeat(' ', max(0, $width));
                continue;
            }

            $shortcut = ShortcutFormatter::format($entry->binding);
            $right = '' === $shortcut ? $this->styles->of(Role::Dim)->apply('—') : $shortcut;
            $rightWidth = '' === $shortcut ? 1 : mb_strwidth($shortcut);
            if ($index === $this->capturingIndex) {
                $prompt = $this->translator->trans('Press a key… (Esc to cancel)');
                $right = $this->styles->of(Role::Modified)->apply($prompt);
                $rightWidth = mb_strwidth($prompt);
            }

            $labelWidth = max(0, $width - $rightWidth - 2);
            $label = AnsiText::truncate(' '.$entry->label, $labelWidth, pad: true);
            $text = $label.' '.$right.' ';
            $lines[] = match (true) {
                $index === $this->list->selected() => $this->applyElement('selected', $text),
                $index === $this->hoveredIndex => $this->styles->of(Role::RowHighlightBg)->apply($text),
                default => $text,
            };
        }

        return $this->withMenuOverlay($lines, $width);
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'move_up' => [Key::UP],
            'move_down' => [Key::DOWN],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'rebind' => [Key::ENTER],
            'show_settings' => ['1'],
            'focus_next_pane' => [Key::TAB],
            'exit_view' => [Key::ESCAPE],
        ];
    }
}
