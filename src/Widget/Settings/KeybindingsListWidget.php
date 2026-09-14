<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\Config\KeybindingRow;
use Porta\Container\AsWidget;
use Porta\Event\SettingsSectionRequested;
use Porta\I18n\Bidi;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Ui\CaretVisibility;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\Role;
use Porta\Ui\ShortcutFormatter;
use Porta\Ui\TextField;
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
use Porta\Widget\Support\PanelRowStyle;
use Porta\Widget\Support\WheelScrollable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
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
    use PanelRowStyle;

    /** @var list<KeybindingRow> */
    private array $rows = [];
    /** @var list<KeybindingRow> the rows the filter leaves, which is what the list shows and acts on */
    private array $shown = [];
    private readonly TextField $filter;
    /** True while typing edits the filter rather than driving the list. */
    private bool $filtering = false;
    private readonly ListSelection $list;
    private int $lastRows = 10;
    /** The row index awaiting a keypress to rebind, or null when not capturing. */
    private ?int $capturingIndex = null;

    /** @var (callable(string, string): void)|null a captured key (action, raw key data) to rebind */
    private $onRebind = null;

    /** @var (callable(string): void)|null an action whose override the user asked to drop */
    private $onReset = null;

    public function __construct(
        private readonly Translator $translator,
        ?Styles $styles = null,
        private readonly ?EventDispatcherInterface $events = null,
    ) {
        $this->initStyles($styles);
        $this->filter = new TextField();
        $this->list = new ListSelection();
        $this->addStyleClass('list'); // the theme's .list::selected(:focus) rules style the selection
    }

    /** @param callable(string, string): void $callback */
    public function onRebind(callable $callback): self
    {
        $this->onRebind = $callback;

        return $this;
    }

    /** @param callable(string): void $callback */
    public function onReset(callable $callback): self
    {
        $this->onReset = $callback;

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
        $this->applyFilter();
        $this->capturingIndex = null;
        $this->hoveredIndex = null;
        $this->invalidate();
    }

    /** The rows the filter leaves — what the list shows, selects and rebinds. */
    private function applyFilter(): void
    {
        $needle = mb_strtolower(trim($this->filter->value()));
        $this->shown = '' === $needle
            ? $this->rows
            : array_values(array_filter(
                $this->rows,
                // The KEY as well as the label: "what is Ctrl+Shift+N bound to" is
                // the other half of what anyone opens this page to find out, and
                // PhpStorm gives it a mode of its own. One field answers both.
                static fn (KeybindingRow $row): bool => str_contains(mb_strtolower($row->label), $needle) || str_contains(mb_strtolower(ShortcutFormatter::format($row->binding)), $needle),
            ));
        $this->list->clamp(\count($this->shown));
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        if ($this->inputHookHandled($data)) {
            return;
        }
        $kb = $this->getKeybindings();

        // Capture mode consumes exactly the next key: Esc cancels, anything
        // else is handed raw to the controller to parse/validate/persist.
        if (null !== $this->capturingIndex) {
            $row = $this->shown[$this->capturingIndex] ?? null;
            $this->capturingIndex = null;
            if (!$kb->matches($data, 'exit_view') && null !== $row && null !== $this->onRebind) {
                ($this->onRebind)($row->action, $data);
            }
            $this->invalidate();

            return;
        }

        if ($this->filtering && $this->editFilter($data, $kb)) {
            return;
        }
        if ($kb->matches($data, 'filter')) {
            $this->filtering = true;
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
            $this->events?->dispatch(new SettingsSectionRequested('general'));

            return;
        }
        if ($kb->matches($data, 'rebind')) {
            $this->armCapture();

            return;
        }
        if ($kb->matches($data, 'reset_binding')) {
            $row = $this->shown[$this->list->selected()] ?? null;
            if (null !== $row && null !== $this->onReset) {
                ($this->onReset)($row->action);
            }

            return;
        }
        // The title row — and the filter row while it is showing — are not
        // list rows, so a page is what render() hands follow(), not the pane
        // height. With the filter open the difference was two entries.
        if (ListNavigation::handleKey($data, $kb, $this->list, \count($this->shown), page: $this->lastRows - $this->headerRows())) {
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

    #[\Override]
    public function hoverRow(int $relRow): bool
    {
        return $this->setHovered($this->rowIndexAt($relRow));
    }

    /**
     * While filtering, the keys belong to the field: printable characters
     * narrow the list, Backspace widens it, Escape leaves the query behind.
     * Everything else — the arrows, Enter to rebind — still drives the list, so
     * you can type two letters and press Enter without a mode change.
     */
    private function editFilter(string $data, Keybindings $kb): bool
    {
        if ($kb->matches($data, 'exit_view')) {
            $this->filtering = false;
            $this->filter->clear();
            $this->applyFilter();
            $this->invalidate();

            return true;
        }
        if ($kb->matches($data, 'delete_char_backward')) {
            if ($this->filter->backspace()) {
                $this->applyFilter();
                $this->invalidate();
            }

            return true;
        }
        if ($this->filter->insert($data)) {
            $this->applyFilter();
            $this->invalidate();

            return true;
        }

        return false;
    }

    /** The filter row is a header row while it is showing. */
    private function headerRows(): int
    {
        return $this->filtering ? 2 : 1;
    }

    private function armCapture(): void
    {
        if ([] !== $this->shown) {
            $this->capturingIndex = $this->list->selected();
            $this->invalidate();
        }
    }

    private function rowIndexAt(int $relRow): ?int
    {
        return $this->list->rowIndexAt($relRow, \count($this->shown), headerRows: $this->headerRows());
    }

    #[\Override]
    public function render(RenderContext $context): array
    {
        $width = $context->getColumns();
        $rows = max(1, $context->getRows() > 0 ? $context->getRows() : 12);
        $this->lastRows = $rows;

        $title =
            ' '
            .(
                $this->filtering
                    ? $this->translator->trans('Keybindings — %shown% of %count% · ⏎ to rebind', ['%shown%' => \count($this->shown), '%count%' => \count($this->rows)])
                    : $this->translator->trans('Keybindings — %count% actions · ⏎ to rebind · Ctrl+F to filter · Del to reset', ['%count%' => \count($this->rows)])
            );
        $lines = [HeaderRenderer::title($title, $width, $this->styles)];
        if ($this->filtering) {
            $caret = $this->isFocused() ? CaretVisibility::Shown : CaretVisibility::Hidden;
            $lines[] = AnsiText::truncate(' '.$this->translator->trans('Filter').': '.$this->filter->render($caret), $width, pad: true);
        }

        $headerRows = $this->headerRows();
        $scroll = $this->list->follow(max(1, $rows - $headerRows));
        for ($row = $headerRows; $row < $rows; ++$row) {
            $index = $scroll + $row - $headerRows;
            $entry = $this->shown[$index] ?? null;
            if (null === $entry) {
                $lines[] = AnsiText::blank($width);
                continue;
            }

            $shortcut = ShortcutFormatter::format($entry->binding);
            // An overridden row is showing a CHOICE, and until now looked
            // exactly like the 200 rows showing a default — so the one row
            // with something to reset was the one row you could not pick out.
            // The mark is a colour rather than a glyph on purpose: this row
            // budgets its width down to the column, and a marker column would
            // come out of the label.
            $right = match (true) {
                '' === $shortcut => $this->styles->of(Role::Dim)->apply('—'),
                $entry->overridden => $this->styles->of(Role::Modified)->apply($shortcut),
                default => $shortcut,
            };
            $rightWidth = '' === $shortcut ? 1 : mb_strwidth($shortcut);
            if ($index === $this->capturingIndex) {
                $prompt = $this->translator->trans('Press a key… (Esc to cancel)');
                $right = $this->styles->of(Role::Modified)->apply($prompt);
                $rightWidth = mb_strwidth($prompt);
            }

            $labelWidth = max(0, $width - $rightWidth - 2);
            $label = AnsiText::truncate(' '.$entry->label, $labelWidth, pad: true);
            // Clamped, because the label alone yielding is not enough: once
            // $labelWidth hits its floor of zero the row is still
            // `' ' + shortcut + ' '` wide, so a pane narrower than the
            // shortcut got a row wider than itself — 8 columns into 1 — and an
            // over-wide row garbles whatever is drawn beneath it. A no-op at
            // every width where the label has room, which is all of them until
            // the pane is narrower than `Ctrl+Shift+C`.
            // A mark, not a space, between the two columns under RTL: four
            // shipped action labels end in a Latin run (`הצג TODO`,
            // `Git: הסחנה (stash)`, …), and a tail like that is strong
            // left-to-right just as the chord is — so without something to end
            // the label's run the two merge and the chord is drawn inside the
            // label. It costs the same column the space did, Bidi spending the
            // mark for a space on the way out.
            $divide = $this->translator->isRtl() ? Bidi::RTL_MARK : ' ';
            $text = AnsiText::truncate($label.$divide.$right.' ', $width, pad: true, ellipsis: '');
            $lines[] = $this->shadeRow($text, $index === $this->list->selected(), $index === $this->hoveredIndex);
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
            // Delete, not Backspace: Backspace belongs to the filter field,
            // and a key that erases a query on one row and an override on the
            // next is the kind of overload people learn to distrust.
            'reset_binding' => [Key::DELETE],
            // Ctrl+F is the settings view's find key on its other two panes,
            // and it was DEAD here — the exact shape the nav's own comment
            // warns about. `/` is the type-to-filter idiom the rest of the app
            // uses, and both mean "find inside this list".
            'filter' => ['ctrl+f', '/'],
            'delete_char_backward' => [Key::BACKSPACE],
            'show_settings' => ['1'],
            'focus_next_pane' => [Key::TAB],
            'exit_view' => [Key::ESCAPE],
        ];
    }
}
