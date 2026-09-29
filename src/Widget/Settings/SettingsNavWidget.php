<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\Config\LanguageCatalog;
use Porta\Container\AsWidget;
use Porta\Event\SettingsSearchRequested;
use Porta\I18n\Translator;
use Porta\Layout\ResizableSidebar;
use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\ListSelection;
use Porta\Ui\Theme\Styles;
use Porta\Widget\HoverableList;
use Porta\Widget\PaneNavigationCallbacks;
use Porta\Widget\Render\EdgeIndicator;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Render\Rule;
use Porta\Widget\Support\ArrowScrollTrait;
use Porta\Widget\Support\FillsPaneTrait;
use Porta\Widget\Support\HoverTrait;
use Porta\Widget\Support\InputHook;
use Porta\Widget\Support\PanelRowStyle;
use Porta\Widget\Support\PanelWidth;
use Porta\Widget\Support\WheelScrollable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * The settings view's left navigation sidebar: the {@see SECTIONS} rows, one
 * indented sub-row per installed plugin under Plugins, then a
 * (non-selectable) Languages header followed by one row per configurable
 * language. Selecting a row (arrow/click) fires onSelect with its id
 * ('general' | 'interface' | 'highlighting' | 'keybindings' | 'commandLine' | 'plugins' |
 * 'plugin:<name>' | 'lang:<id>'); the header is skipped. Self-sizing so the
 * sidebar can be dragged wider/narrower — and {@see minWidth()} measures the
 * rows rather than naming a number, because the row set here grows.
 *
 * @phpstan-type Entry array{id: string|null, label: string, kind: NavEntryKind}
 */
#[AsWidget('settings-nav', flex: 0)]
final class SettingsNavWidget extends AbstractWidget implements FocusableInterface, HoverableList, MenuOverlayHost, ResizableSidebar, VerticallyExpandableInterface, WheelScrollable
{
    use ArrowScrollTrait;
    use FillsPaneTrait;
    use FocusableTrait;
    use HoverTrait;
    use InputHook;
    use KeybindingsTrait;
    use MenuOverlayHostTrait;
    use PanelWidth;
    use PaneNavigationCallbacks;
    use PanelRowStyle;

    private const array SECTIONS = [
        'general' => 'General',
        'interface' => 'Interface',
        'highlighting' => 'Highlighting',
        'keybindings' => 'Keybindings',
        'commandLine' => 'Command line',
        'plugins' => 'Plugins',
    ];

    /**
     * The floor under {@see minWidth()}'s measurement — what this nav was
     * allowed to be when the number was a literal 14, kept so a bare install
     * (whose widest row is `   JavaScript`, 13 columns) drags exactly as far
     * as it always did.
     */
    private const int NARROWEST = 14;

    /**
     * The ceiling over it, and not cosmetic:
     * {@see \Porta\Layout\LayoutController::clampSidebarWidth()} applies
     * minWidth OUTSIDE its max-ratio and neighbour caps, so a minimum derived
     * from content with no bound of its own would let one long plugin name
     * win against the terminal's own width. 24 is what {@see PanelWidth}
     * asks for a side panel generally; past it, rows truncate as before.
     */
    private const int WIDEST_MINIMUM = 24;

    /** @var list<Entry> */
    private array $entries;

    /** @var list<string>|null rendered-lines memo — the flex-0 side pane renders twice per frame otherwise */
    private ?array $memoLines = null;

    /** @var array{int, int, int} the [revision, width, rows] the memo was rendered for */
    private array $memoKey = [-1, -1, -1];

    /** Non-entry rows the last render put above the list: the ▲ slot, or none. */
    private int $indicatorRows = 0;

    /** Entry rows the last render actually drew, so the trailing ▼ is not mistaken for one. */
    private int $bodyRows = 0;

    /** @var array<int, int> the ▲/▼ rows the last render drew, and the entry a click on each selects */
    private array $indicatorTargets = [];
    private readonly ListSelection $list;

    /** @var (callable(string): void)|null selection moved to this entry id */
    private $onSelect;

    public function __construct(
        private readonly Translator $translator,
        int $width = 22,
        ?Styles $styles = null,
        // Trailing and optional, as the widget rule has it. Only Ctrl+F uses
        // it: section selection is a callback because the view owns it, while
        // the picker is nobody's in particular.
        private readonly ?EventDispatcherInterface $events = null,
    ) {
        $this->width = $width;
        $this->initStyles($styles);
        $this->entries = $this->buildEntries([]);
        $this->list = new ListSelection();
        $this->addStyleClass('list'); // the theme's .list::selected(:focus) rules style the selection
    }

    /**
     * The nav rows: sections, one sub-item per active plugin under Plugins,
     * then the Languages header and its rows.
     *
     * There is no "Version control" header any more. Git's page was a core
     * section with a hardcoded row here; git is a plugin, so its settings are
     * its own page under Plugins, reached the same way every other plugin's is.
     *
     * @param list<string> $pluginNames
     *
     * @return list<Entry>
     */
    private function buildEntries(array $pluginNames): array
    {
        $entries = [];
        foreach (self::SECTIONS as $id => $label) {
            $entries[] = ['id' => $id, 'label' => $label, 'kind' => NavEntryKind::Section];
            if ('plugins' === $id) {
                foreach ($pluginNames as $name) {
                    $entries[] = ['id' => 'plugin:'.$name, 'label' => $name, 'kind' => NavEntryKind::Plugin];
                }
            }
        }
        $entries[] = ['id' => null, 'label' => 'Languages', 'kind' => NavEntryKind::Header];
        foreach (LanguageCatalog::LANGUAGES as $id => $name) {
            $entries[] = ['id' => 'lang:'.$id, 'label' => $name, 'kind' => NavEntryKind::Language];
        }

        return $entries;
    }

    /**
     * Rebuild the per-plugin sub-items (a plugin was installed or removed),
     * keeping the selection on the same id — or falling back to the Plugins
     * section when the selected plugin just vanished.
     *
     * @param list<string> $names
     */
    public function setPluginEntries(array $names): void
    {
        $selected = $this->selectedId();
        $this->entries = $this->buildEntries($names);
        $ids = \array_column($this->entries, 'id');
        $this->setActive(null !== $selected && \in_array($selected, $ids, strict: true) ? $selected : 'plugins');
        $this->invalidate();
    }

    /**
     * The narrowest this may be dragged: wide enough to READ every row it
     * draws, not a number frozen against the rows it drew once.
     *
     * 14 was right in July, when the nav held three sections and the language
     * catalogue and its widest row was `   JavaScript` at 13. Since then
     * Interface and Highlighting arrived, and every installed plugin became a
     * sub-row under Plugins — `   local-history` needs 16 — so dragging to
     * the floor cut names the sidebar exists to name. Translated rows make it
     * worse than the English screen shows: French renders Keybindings as
     * `Raccourcis clavier`, 19 columns.
     */
    #[\Override]
    public function minWidth(): int
    {
        $widest = 0;
        foreach ($this->entries as $entry) {
            $widest = \max($widest, AnsiUtils::visibleWidth($this->rowText($entry)));
        }

        return \max(self::NARROWEST, \min($widest, self::WIDEST_MINIMUM));
    }

    #[\Override]
    public function maxWidth(): int
    {
        return 60;
    }

    #[\Override]
    public function maxRatio(): float
    {
        return 0.5;
    }

    public function onSelect(callable $callback): self
    {
        $this->onSelect = $callback;

        return $this;
    }

    /** The id of the currently selected entry ('general' | 'keybindings' | 'plugins' | 'plugin:<name>' | 'lang:<id>'). */
    /**
     * The selected entry's display label — the form's page title.
     *
     * Section names are translated here and plugin/language names deliberately
     * are not (they are identifiers the user typed or installed), which is the
     * same rule the nav renders by.
     */
    public function activeLabel(): string
    {
        // Indexed, not searched: the entry list is never empty and the
        // selection is always clamped into it, so there is no "no selection"
        // case to invent a fallback for.
        $entry = $this->entries[$this->list->selected()];

        return NavEntryKind::Section === $entry['kind'] ? $this->translator->trans($entry['label']) : $entry['label'];
    }

    public function selectedId(): ?string
    {
        return $this->entries[$this->list->selected()]['id'] ?? null;
    }

    /** Highlight the entry with this id (the controller syncs the active section). */
    public function setActive(string $id): void
    {
        foreach ($this->entries as $i => $entry) {
            if ($entry['id'] !== $id) {
                continue;
            }

            $this->list->select($i);
            $this->invalidate();

            return;
        }
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        if ($this->inputHookHandled($data)) {
            return;
        }
        $kb = $this->getKeybindings();

        if ($kb->matches($data, 'exit_view')) {
            $this->fire($this->onExit);

            return;
        }
        if ($kb->matches($data, 'focus_next_pane')) {
            $this->fire($this->onFocusNext);

            return;
        }
        if ($kb->matches($data, 'find_setting')) {
            $this->events?->dispatch(new SettingsSearchRequested());

            return;
        }
        if ($kb->matches($data, 'move_up')) {
            $this->moveTo($this->prevSelectable($this->list->selected()));

            return;
        }
        if ($kb->matches($data, 'move_down')) {
            $this->moveTo($this->nextSelectable($this->list->selected()));
        }
    }

    /**
     * A click selects (and fires) the entry under the pointer; the header is
     * inert. A drawn ▲/▼ selects the first entry hidden past its edge: the
     * selection is the only viewport this list has, and a step of a few rows
     * from a cursor mid-window would not have moved the window at all.
     */
    public function handleClick(int $relRow): void
    {
        $target = $this->indicatorTargets[$relRow] ?? null;
        if (null !== $target) {
            $this->moveTo($target);

            return;
        }
        $index = $this->rowIndexAt($relRow);
        if (null !== $index && NavEntryKind::Header !== $this->entries[$index]['kind']) {
            $this->moveTo($index);
        }
    }

    #[\Override]
    public function hoverRow(int $relRow): bool
    {
        $index = $this->rowIndexAt($relRow);

        return $this->setHovered(null !== $index && NavEntryKind::Header !== $this->entries[$index]['kind'] ? $index : null);
    }

    private function moveTo(int $index): void
    {
        if ($index !== $this->list->selected()) {
            $this->list->select($index);
            $this->invalidate();
        }
        $id = $this->entries[$index]['id'] ?? null;
        if (null !== $id && null !== $this->onSelect) {
            ($this->onSelect)($id);
        }
    }

    private function prevSelectable(int $from): int
    {
        for ($i = $from - 1; $i >= 0; --$i) {
            if (NavEntryKind::Header !== $this->entries[$i]['kind']) {
                return $i;
            }
        }

        return $from;
    }

    private function nextSelectable(int $from): int
    {
        $count = \count($this->entries);
        for ($i = $from + 1; $i < $count; ++$i) {
            if (NavEntryKind::Header !== $this->entries[$i]['kind']) {
                return $i;
            }
        }

        return $from;
    }

    /**
     * The entry under a widget-relative row, or null when that row holds no
     * entry.
     *
     * The count of non-entry rows above the list is not a constant: {@see
     * render()} reserves its first line for the ▲ indicator as soon as the
     * entries outnumber the pane, and reserves it even when nothing is scrolled
     * yet — the line is blank then, not absent. Counting from the top of the
     * PANE rather than the top of the LIST put every click and hover one row
     * low, so choosing a category selected the one below it.
     */
    private function rowIndexAt(int $relRow): ?int
    {
        // The TRAILING indicator row is not an entry. `indicatorRows` counts
        // only the leading ▲, while an overflowing render reserves the last row
        // for ▼ as well — and `ListSelection::rowIndexAt()` bounds by the total
        // entry count, not by what is visible, so a click on ▼ mapped to
        // `scroll + budget`: the first entry BELOW the window, which is exactly
        // the entry whose existence puts the ▼ there. Clicking "scroll down"
        // navigated to a page you could not see, and hover tinted a row that is
        // never drawn.
        if ($relRow >= ($this->indicatorRows + $this->bodyRows)) {
            return null;
        }

        return $this->list->rowIndexAt($relRow, \count($this->entries), $this->indicatorRows);
    }

    /**
     * A centred ▲/▼ edge row; the caller draws a blank when that side is not
     * clipped, which is the shape {@see \Porta\Widget\TabBarWidget} uses for
     * the same job.
     *
     * A reserved ROW rather than a column in the last cell, which this
     * widget's own floor demands: the nav is drawn as narrow as
     * {@see minWidth()}, and a marker column would come straight out of a name.
     */
    private function indicatorRow(string $glyph, int $row, int $target, int $width): string
    {
        $this->indicatorTargets[$row] = $target;

        return EdgeIndicator::row($glyph, $width, $this->styles);
    }

    /**
     * One row's text before truncation.
     *
     * Shared with {@see minWidth()} deliberately: a bound that measures rows
     * the render does not draw is a bound that drifts the moment either side
     * changes. Language and plugin rows indent under their category and keep
     * their own names — those are identifiers the user typed or installed,
     * not prose to translate.
     *
     * @param Entry $entry
     */
    private function rowText(array $entry): string
    {
        return \in_array($entry['kind'], [NavEntryKind::Language, NavEntryKind::Plugin], strict: true)
            ? '   '.$entry['label']
            : ' '.$this->translator->trans($entry['label']);
    }

    #[\Override]
    public function render(RenderContext $context): array
    {
        $width = $this->panelColumns($context);
        $rows = \max(1, $context->getRows() > 0 ? $context->getRows() : 12);

        // The FileTreeWidget idiom: this flex-0 side pane is rendered twice
        // per frame (intrinsic measure + real pass); one memo serves both.
        $key = [$this->getRenderRevision(), $width, $rows];
        if (null !== $this->memoLines && $key === $this->memoKey) {
            return $this->memoLines;
        }
        $this->memoKey = $key;

        // A list that scrolls has to SAY so. This one is a finite, complete
        // menu of destinations — five sections, one row per installed plugin,
        // then the language catalogue — so a reader reasonably takes what is
        // drawn for all of it. With nine plugins installed it is 28 rows, and
        // on a 24-row terminal the last six simply were not there. The
        // vertical tab strip has marked its overflow this way since it was
        // written; nothing else in the IDE did.
        $overflow = $rows > 2 && \count($this->entries) > $rows;
        // Remembered for the hit test, which runs between frames and cannot
        // recompute it: the pattern {@see \Porta\Widget\FileTreeWidget} uses
        // for a render-time measurement a later click needs.
        $this->indicatorRows = $overflow ? 1 : 0;
        $budget = $overflow ? $rows - 2 : $rows;
        $this->bodyRows = $budget;
        $this->indicatorTargets = [];
        $scroll = $this->list->follow($budget);
        $lines = [];
        if ($overflow) {
            $lines[] = $scroll > 0 ? $this->indicatorRow(Glyphs::SCROLL_UP, 0, $this->prevSelectable($scroll), $width) : AnsiText::blank($width);
        }
        for ($row = 0; $row < $budget; ++$row) {
            $index = $scroll + $row;
            $entry = $this->entries[$index] ?? null;
            if (null === $entry) {
                $lines[] = AnsiText::blank($width);
                continue;
            }

            if (NavEntryKind::Header === $entry['kind']) {
                // The form's fieldset legend: a heading reads as one by its
                // shape, where dim bold was a shade the monochrome theme lost.
                $lines[] = Rule::horizontal($width, $this->styles, $this->translator->trans($entry['label']));
                continue;
            }

            // Focus in the form leaves the quarter bar on the page it shows.
            $selected = $index === $this->list->selected();
            $text = $this->barred(AnsiText::truncate($this->rowText($entry), $width, pad: true), $selected);
            $lines[] = $this->shadeRow($text, $selected, $index === $this->hoveredIndex);
        }
        if ($overflow) {
            $lines[] = ($scroll + $budget) < \count($this->entries)
                ? $this->indicatorRow(Glyphs::SCROLL_DOWN, $rows - 1, $this->nextSelectable($scroll + $budget - 1), $width)
                : AnsiText::blank($width);
        }

        return $this->memoLines = $lines;
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'move_up' => [Key::UP],
            'move_down' => [Key::DOWN],
            'focus_next_pane' => [Key::TAB],
            // Same key on both of the view's stops. Tab lands here, and a Ctrl+F
            // that works on one pane and not the other is the kind of dead key
            // people stop trying.
            'find_setting' => ['ctrl+f'],
            'exit_view' => [Key::ESCAPE],
        ];
    }

    protected function overlayWidth(RenderContext $context): int
    {
        return $this->panelColumns($context);
    }
}
