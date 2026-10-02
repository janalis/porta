<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\Config\LanguageCatalog;
use Porta\Container\AsWidget;
use Porta\Event\SettingsSearchRequested;
use Porta\I18n\Translator;
use Porta\Layout\ResizableSidebar;
use Porta\Support\AnsiText;
use Porta\Ui\Density;
use Porta\Ui\Glyphs;
use Porta\Ui\ListSelection;
use Porta\Ui\Role;
use Porta\Ui\ScreenDensity;
use Porta\Ui\Theme\Styles;
use Porta\Widget\HoverableList;
use Porta\Widget\PaneNavigationCallbacks;
use Porta\Widget\Render\EdgeIndicator;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Render\Rule;
use Porta\Widget\Render\SelectionBar;
use Porta\Widget\Render\TreeRow;
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
 * The settings view's left navigation sidebar, a tree in the file tree's
 * shape: the {@see SECTIONS} pages, then a Plugins folder — a page itself —
 * holding one page per installed plugin, then a Languages folder holding one
 * per configurable language. A folder opens and closes as a tree's does (←,
 * →, Enter or a click). Selecting a page (arrow/click) fires onSelect with
 * its id ('general' | 'interface' | 'highlighting' | 'keybindings' |
 * 'commandLine' | 'plugins' | 'plugin:<name>' | 'lang:<id>'); the Languages
 * folder is no page, so landing on it fires nothing and the page stays.
 *
 * On a roomy screen a rule runs between every two rows; on a compact one the
 * rules give their rows back. Self-sizing so the sidebar can be dragged
 * wider/narrower — and {@see minWidth()} measures the rows rather than
 * naming a number, because the row set here grows.
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
    ];

    /**
     * The floor under {@see minWidth()}'s measurement — what this nav was
     * allowed to be when the number was a literal 14, kept so a bare install
     * drags exactly as far as it always did.
     */
    private const int NARROWEST = 14;

    /**
     * The ceiling over it, and not cosmetic:
     * {@see \Porta\Layout\LayoutController::clampSidebarWidth()} applies
     * minWidth OUTSIDE its max-ratio and neighbour caps, so a minimum derived
     * from content with no bound of its own would let one long plugin name
     * win against the terminal's own width. 24 is what {@see PanelWidth}
     * asks for a side panel generally, and the tree's disclosure column adds
     * two to every row: Italian's `   Scorciatoie da tastiera` is 26. Past
     * it, rows truncate as before.
     */
    private const int WIDEST_MINIMUM = 26;

    /** @var list<Entry> */
    private array $entries;

    /** @var array<string, bool> each folder ('plugins', 'languages') and whether it is open */
    private array $expanded = ['plugins' => true, 'languages' => true];

    /** @var list<int> the entries a closed folder does not hide, in order — what the list navigates */
    private array $visible = [];

    /** @var list<string>|null rendered-lines memo — the flex-0 side pane renders twice per frame otherwise */
    private ?array $memoLines = null;

    /** @var array{int, int, int, ?Density} the [revision, width, rows, density] the memo was rendered for */
    private array $memoKey = [-1, -1, -1, null];

    /** @var array<int, int> which visible row each screen row of the last render drew; dividers and blanks own none */
    private array $rowOwner = [];

    /** The density the last render drew for, so a flip repaints. */
    private ?Density $drawnDensity = null;

    /** @var array<int, int> the ▲/▼ rows the last render drew, and the visible row a click on each selects */
    private array $indicatorTargets = [];
    private readonly ListSelection $list;

    /** @var (callable(string): void)|null selection moved to this page id */
    private $onSelect;

    public function __construct(
        private readonly Translator $translator,
        int $width = 22,
        ?Styles $styles = null,
        // Trailing and optional, as the widget rule has it. Only Ctrl+F uses
        // it: section selection is a callback because the view owns it, while
        // the picker is nobody's in particular.
        private readonly ?EventDispatcherInterface $events = null,
        // The rules between rows are drawn only on a roomy screen, and a nav
        // built bare has no screen to ask, so it draws none.
        private readonly ?ScreenDensity $density = null,
    ) {
        $this->width = $width;
        $this->initStyles($styles);
        $this->entries = $this->buildEntries([]);
        $this->list = new ListSelection();
        $this->refreshVisible();
        $this->addStyleClass('list'); // the theme's .list::selected(:focus) rules style the selection
    }

    /**
     * The nav rows: the top-level pages, the Plugins folder with one page per
     * active plugin, then the Languages folder and its pages.
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
        }
        $entries[] = ['id' => 'plugins', 'label' => 'Plugins', 'kind' => NavEntryKind::Folder];
        foreach ($pluginNames as $name) {
            $entries[] = ['id' => 'plugin:'.$name, 'label' => $name, 'kind' => NavEntryKind::Plugin];
        }
        $entries[] = ['id' => null, 'label' => 'Languages', 'kind' => NavEntryKind::Folder];
        foreach (LanguageCatalog::LANGUAGES as $id => $name) {
            $entries[] = ['id' => 'lang:'.$id, 'label' => $name, 'kind' => NavEntryKind::Language];
        }

        return $entries;
    }

    /**
     * The folder key of a folder entry: 'plugins' or 'languages'.
     *
     * @param Entry $entry
     */
    private static function folderKey(array $entry): string
    {
        return $entry['id'] ?? 'languages';
    }

    /**
     * Rebuild which entries show — every one a closed folder does not hide —
     * keeping the cursor on the entry it was on, or on its folder when that
     * folder just closed over it.
     */
    private function refreshVisible(): void
    {
        $current = $this->visible[$this->list->selected()] ?? 0;
        $this->visible = [];
        foreach ($this->entries as $index => $entry) {
            $folder = $entry['kind']->folder();
            if (null === $folder || $this->expanded[$folder]) {
                $this->visible[] = $index;
            }
        }
        $this->list->select($this->positionOf($current) ?? $this->positionOf($this->folderOf($current)) ?? 0);
    }

    /** The visible row showing entry $index, or null when a closed folder hides it. */
    private function positionOf(?int $index): ?int
    {
        $position = null === $index ? false : \array_search($index, $this->visible, strict: true);

        return false === $position ? null : $position;
    }

    /** The entry index of the folder entry $index sits in, or null at the top level. */
    private function folderOf(int $index): ?int
    {
        $folder = $this->entries[$index]['kind']->folder();
        if (null === $folder) {
            return null;
        }

        return \array_find_key($this->entries, static fn (array $entry): bool => NavEntryKind::Folder === $entry['kind'] && self::folderKey($entry) === $folder);
    }

    /** @return Entry */
    private function selectedEntry(): array
    {
        // Indexed, not searched: the visible list always holds the top-level
        // rows, and the selection is always clamped into it.
        return $this->entries[$this->visible[$this->list->selected()]];
    }

    /**
     * Rebuild the per-plugin pages (a plugin was installed or removed),
     * keeping the selection on the same id — or falling back to the Plugins
     * page when the selected plugin just vanished.
     *
     * @param list<string> $names
     */
    public function setPluginEntries(array $names): void
    {
        $selected = $this->selectedId();
        $this->entries = $this->buildEntries($names);
        $this->visible = [];
        $this->refreshVisible();
        $ids = \array_column($this->entries, 'id');
        $this->setActive(null !== $selected && \in_array($selected, $ids, strict: true) ? $selected : 'plugins');
        $this->invalidate();
    }

    /**
     * The narrowest this may be dragged: wide enough to READ every row it
     * draws, not a number frozen against the rows it drew once. Every
     * installed plugin is a row, and translated rows make it worse than the
     * English screen shows: French renders Keybindings as `Raccourcis
     * clavier`, 19 columns.
     */
    #[\Override]
    public function minWidth(): int
    {
        $widest = 0;
        foreach ($this->entries as $entry) {
            $widest = \max($widest, AnsiUtils::visibleWidth($this->rowText($entry, SelectionBar::None)));
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

    /**
     * The page $id's trail for the page header: `Settings › Interface`,
     * `Settings › Plugins › git`, `Settings › Languages › PHP`. Each crumb
     * carries the page it leads to — Settings leads to General, Languages to
     * none, being no page.
     *
     * Section names are translated here and plugin/language names deliberately
     * are not (they are identifiers the user typed or installed), which is the
     * same rule the nav renders by.
     *
     * @return list<array{label: string, id: ?string}>
     */
    public function trailFor(string $id): array
    {
        $trail = [['label' => $this->translator->trans('Settings'), 'id' => 'general']];
        foreach ($this->entries as $index => $entry) {
            if ($entry['id'] !== $id) {
                continue;
            }
            $folder = $this->folderOf($index);
            if (null !== $folder) {
                $trail[] = ['label' => $this->label($this->entries[$folder]), 'id' => $this->entries[$folder]['id']];
            }
            $trail[] = ['label' => $this->label($entry), 'id' => $id];
        }

        return $trail;
    }

    /**
     * An entry's display name: section and folder names translated, plugin
     * and language names as they are.
     *
     * @param Entry $entry
     */
    private function label(array $entry): string
    {
        return \in_array($entry['kind'], [NavEntryKind::Section, NavEntryKind::Folder], strict: true) ? $this->translator->trans($entry['label']) : $entry['label'];
    }

    /** The id of the entry under the cursor ('general' | 'keybindings' | 'plugins' | 'plugin:<name>' | 'lang:<id>'), or null on the Languages folder. */
    public function selectedId(): ?string
    {
        return $this->selectedEntry()['id'];
    }

    /**
     * Put the cursor on the page with this id (the controller syncs the
     * active section), opening its folder first: a page reached from the
     * search, the page keys or the header is never left hidden.
     */
    public function setActive(string $id): void
    {
        foreach ($this->entries as $i => $entry) {
            if ($entry['id'] !== $id) {
                continue;
            }

            $folder = $entry['kind']->folder();
            if (null !== $folder && !$this->expanded[$folder]) {
                $this->expanded[$folder] = true;
                $this->refreshVisible();
            }
            $this->list->select($this->positionOf($i) ?? 0);
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
            $this->moveTo(\max(0, $this->list->selected() - 1));

            return;
        }
        if ($kb->matches($data, 'move_down')) {
            $this->moveTo(\min(\count($this->visible) - 1, $this->list->selected() + 1));

            return;
        }
        if ($kb->matches($data, 'collapse')) {
            $this->collapse();

            return;
        }
        if ($kb->matches($data, 'expand')) {
            $this->setFolderOpen(true);

            return;
        }
        if ($kb->matches($data, 'toggle')) {
            $this->setFolderOpen(!$this->isOpenFolder($this->selectedEntry()));
        }
    }

    /**
     * ← as in the tree: an open folder closes; a page inside a folder steps
     * out onto it.
     */
    private function collapse(): void
    {
        $entry = $this->selectedEntry();
        if ($this->isOpenFolder($entry)) {
            $this->setFolderOpen(false);

            return;
        }
        $folder = $this->folderOf($this->visible[$this->list->selected()]);
        if (null !== $folder) {
            $this->moveTo((int) $this->positionOf($folder));
        }
    }

    /** @param Entry $entry */
    private function isOpenFolder(array $entry): bool
    {
        return NavEntryKind::Folder === $entry['kind'] && $this->expanded[self::folderKey($entry)];
    }

    /** Open or close the folder under the cursor; a page is left as it is. */
    // @mago-expect lint:no-boolean-flag-parameter -- open or closed is the folder's one state, not a second responsibility.
    private function setFolderOpen(bool $open): void
    {
        $entry = $this->selectedEntry();
        if (NavEntryKind::Folder !== $entry['kind'] || $this->isOpenFolder($entry) === $open) {
            return;
        }
        $this->expanded[self::folderKey($entry)] = $open;
        $this->refreshVisible();
        $this->invalidate();
    }

    /**
     * A click selects (and fires) the row under the pointer, and a folder
     * opens or closes as in the tree; a rule between rows is nobody's. A
     * drawn ▲/▼ selects the first row hidden past its edge: the selection is
     * the only viewport this list has, and a step of a few rows from a
     * cursor mid-window would not have moved the window at all.
     */
    public function handleClick(int $relRow): void
    {
        $edge = $this->indicatorTargets[$relRow] ?? null;
        $target = $edge ?? $this->rowOwner[$relRow] ?? null;
        if (null === $target) {
            return;
        }
        $this->moveTo($target);
        if (null === $edge) {
            $this->setFolderOpen(!$this->isOpenFolder($this->selectedEntry()));
        }
    }

    #[\Override]
    public function hoverRow(int $relRow): bool
    {
        return $this->setHovered($this->rowOwner[$relRow] ?? null);
    }

    /** Move the cursor to visible row $position; a page there is shown. */
    private function moveTo(int $position): void
    {
        if ($position !== $this->list->selected()) {
            $this->list->select($position);
            $this->invalidate();
        }
        $id = $this->selectedEntry()['id'];
        if (null !== $id && null !== $this->onSelect) {
            ($this->onSelect)($id);
        }
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
     * One row's text before truncation and shading, the tree's shape: the
     * bar, the indent, the disclosure (two blanks on a page), the name.
     *
     * Shared with {@see minWidth()} deliberately: a bound that measures rows
     * the render does not draw is a bound that drifts the moment either side
     * changes.
     *
     * @param Entry $entry
     */
    private function rowText(array $entry, SelectionBar $bar): string
    {
        $depth = null === $entry['kind']->folder() ? 0 : 1;
        $expanded = NavEntryKind::Folder === $entry['kind'] ? $this->isOpenFolder($entry) : null;

        return TreeRow::text($bar, $depth, $expanded, $this->label($entry));
    }

    #[\Override]
    public function beforeRender(): void
    {
        // A cached pane is not rendered again on its own: a density flip has
        // to invalidate it, or the rules stay as they were.
        if ($this->drawnDensity !== $this->density?->current()) {
            $this->invalidate();
        }
    }

    #[\Override]
    public function render(RenderContext $context): array
    {
        $width = $this->panelColumns($context);
        $rows = \max(1, $context->getRows() > 0 ? $context->getRows() : 12);
        $density = $this->density?->current();
        $this->drawnDensity = $density;

        // The FileTreeWidget idiom: this flex-0 side pane is rendered twice
        // per frame (intrinsic measure + real pass); one memo serves both.
        $key = [$this->getRenderRevision(), $width, $rows, $density];
        if (null !== $this->memoLines && $key === $this->memoKey) {
            return $this->memoLines;
        }
        $this->memoKey = $key;

        // A rule between every two rows on a roomy screen: each row then
        // costs two lines but the last, which needs no rule after it.
        $ruled = Density::Roomy === $density;
        $count = \count($this->visible);
        $needed = $ruled ? (2 * $count) - 1 : $count;
        // A list that scrolls has to SAY so. This one is a finite, complete
        // menu of destinations, so a reader reasonably takes what is drawn
        // for all of it; with nine plugins installed it overflowed a 24-row
        // terminal and its last rows simply were not there.
        $overflow = $rows > 2 && $needed > $rows;
        $budget = $overflow ? $rows - 2 : $rows;
        $this->indicatorTargets = [];
        $this->rowOwner = [];
        $scroll = $this->list->follow($ruled ? \intdiv($budget + 1, num2: 2) : $budget);
        $lines = [];
        // The ▲ row only once something IS above: at the top the list starts
        // on row 0 — a blank there read as a stray empty line over General.
        // Nothing moves when it scrolls: one entry down, the ▲ takes row 0 and
        // the next entry stays on row 1.
        if ($overflow && $scroll > 0) {
            $lines[] = $this->indicatorRow(Glyphs::SCROLL_UP, 0, $scroll - 1, $width);
        }
        if ($overflow && 0 === $scroll) {
            ++$budget;
        }
        $position = $scroll;
        $top = \count($lines);
        while ((\count($lines) - $top) < $budget && $position < $count) {
            if ($ruled && $position > $scroll) {
                $lines[] = Rule::horizontal($width, $this->styles);
                if ((\count($lines) - $top) >= $budget) {
                    break;
                }
            }
            $this->rowOwner[\count($lines)] = $position;
            $lines[] = $this->row($position, $width);
            ++$position;
        }
        while ((\count($lines) - $top) < $budget) {
            $lines[] = AnsiText::blank($width);
        }
        if ($overflow) {
            $lines[] = $position < $count
                ? $this->indicatorRow(Glyphs::SCROLL_DOWN, $rows - 1, $position, $width)
                : AnsiText::blank($width);
        }

        return $this->memoLines = $lines;
    }

    /**
     * Visible row $position, shaded as the tree shades its rows: a folder
     * in the accent, the hover tint under the pointer, the selection over
     * both. Focus in the form leaves the quarter bar on the page it shows.
     */
    private function row(int $position, int $width): string
    {
        $entry = $this->entries[$this->visible[$position]];
        $selected = $position === $this->list->selected();
        $text = AnsiText::truncate($this->rowText($entry, SelectionBar::of($selected, $this->isFocused())), $width, pad: true);
        if (NavEntryKind::Folder === $entry['kind'] && !$selected) {
            $text = $this->styles->of(Role::TabAccent)->apply($text);
        }

        return $this->shadeRow($text, $selected, $position === $this->hoveredIndex);
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'move_up' => [Key::UP],
            'move_down' => [Key::DOWN],
            // The tree's own keys for a folder: ← closes it (or steps out
            // of it), → opens it, Enter or Space flips it.
            'collapse' => [Key::LEFT],
            'expand' => [Key::RIGHT],
            'toggle' => [Key::ENTER, Key::SPACE],
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
