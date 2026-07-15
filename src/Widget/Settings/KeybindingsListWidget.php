<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\Config\KeybindingRow;
use Porta\Container\AsWidget;
use Porta\I18n\Translator;
use Porta\Ui\ColorPalette;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\Palette;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\ShortcutFormatter;
use Porta\Widget\FillsPaneTrait;
use Porta\Widget\HoverTrait;
use Porta\Widget\InputHook;
use Porta\Widget\PaneNavigationCallbacks;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Symfony\Component\Tui\Ansi\AnsiUtils;
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
final class KeybindingsListWidget extends AbstractWidget implements FocusableInterface, MenuOverlayHost, VerticallyExpandableInterface
{
    use FillsPaneTrait;
    use FocusableTrait;
    use HoverTrait;
    use InputHook;
    use MenuOverlayHostTrait;
    use KeybindingsTrait;
    use PaneNavigationCallbacks;

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

    private readonly Palette $palette;

    public function __construct(
        private readonly Translator $translator,
        ?Palette $palette = null,
    ) {
        $this->palette = $palette ?? ColorPalette::dark();
        $this->list = new ListSelection();
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

        $title = ' '.$this->translator->trans('Keybindings — %count% actions · Enter to rebind', ['%count%' => \count($this->rows)]);
        $lines = [$this->palette->of(Role::HeaderBar).Sgr::BOLD.AnsiUtils::truncateToWidth($title, $width, '…', pad: true).Sgr::RESET];

        $scroll = $this->list->follow($rows - 1);
        for ($row = 1; $row < $rows; ++$row) {
            $index = $scroll + $row - 1;
            $entry = $this->rows[$index] ?? null;
            if (null === $entry) {
                $lines[] = str_repeat(' ', max(0, $width));
                continue;
            }

            $selected = $index === $this->list->selected();
            $bg = match (true) {
                $selected && $this->focused => $this->palette->of(Role::SelectedRow),
                $selected => $this->palette->of(Role::SelectedRowUnfocused),
                $index === $this->hoveredIndex => $this->palette->of(Role::RowHighlightBg),
                default => '',
            };

            $shortcut = ShortcutFormatter::format($entry->binding);
            $right = '' === $shortcut ? $this->palette->of(Role::Dim)."—\x1b[39m" : $shortcut;
            $rightWidth = '' === $shortcut ? 1 : mb_strwidth($shortcut);
            if ($index === $this->capturingIndex) {
                $prompt = $this->translator->trans('Press a key… (Esc to cancel)');
                $right = $this->palette->of(Role::Modified).$prompt.Sgr::DEFAULT_FG;
                $rightWidth = mb_strwidth($prompt);
            }

            $labelWidth = max(0, $width - $rightWidth - 2);
            $label = AnsiUtils::truncateToWidth(' '.$entry->label, $labelWidth, '…', pad: true);
            $lines[] = $bg.$label.' '.$right.' '.Sgr::RESET;
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
