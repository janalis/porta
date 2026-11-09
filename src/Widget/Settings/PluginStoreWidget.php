<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\Container\AsWidget;
use Porta\Event\SettingsSectionRequested;
use Porta\Help\Layout\ImageLayout;
use Porta\I18n\Translator;
use Porta\Plugin\Store\Art;
use Porta\Plugin\Store\StoreAction;
use Porta\Plugin\Store\StoreCategory;
use Porta\Plugin\Store\StoreEntry;
use Porta\Plugin\Store\StoreRow;
use Porta\Settings\InstalledPlugin;
use Porta\Settings\SettingsRow;
use Porta\Support\AnsiText;
use Porta\Ui\CaretVisibility;
use Porta\Ui\CursorStyle;
use Porta\Ui\FilterKeys;
use Porta\Ui\Graphics\TerminalGraphics;
use Porta\Ui\ScreenDensity;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;
use Porta\Widget\PaneNavigationCallbacks;
use Porta\Widget\Render\FieldBox;
use Porta\Widget\Render\FieldSpec;
use Porta\Widget\Render\FieldState;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Render\Rule;
use Porta\Widget\Render\TabStripRenderer;
use Porta\Widget\Support\ArrowScrollTrait;
use Porta\Widget\Support\FillsPaneTrait;
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
 * The plugin store, the Settings › Plugins page, laid out as the App Store.
 *
 * Four tabs: **Discover** — the featured plugins, then a shelf per category;
 * **Categories**, each opening its whole shelf; **Updates**, the installed
 * plugins with a newer release and Update all; and **Installed**, every
 * plugin here — from the store or not — with where an install lands and a
 * field that installs from a git URL, a path or a package. A search narrows
 * whichever tab is shown. The shelf is laid out by {@see StoreShelf}, whose
 * item map every key, click and hover reads.
 *
 * Enter opens a plugin's page ({@see StorePage}): its header and button, a
 * strip of facts, its screenshots, What's New, About and Information. What
 * the page shows beyond the catalog is not this widget's to fetch: it asks
 * through {@see onAsset()} and is handed each answer through
 * {@see setAsset()}, so nothing here waits on the network. Each plugin's
 * icon and pictures are handed in likewise, through {@see setArt()}.
 *
 * Every button goes back to the controller as the entry pressed; which button
 * it is, and what it does, are the controller's.
 */
#[AsWidget('settings-store', flex: 1)]
final class PluginStoreWidget extends AbstractWidget implements FocusableInterface, MenuOverlayHost, VerticallyExpandableInterface, WheelScrollable
{
    use ArrowScrollTrait;
    use FillsPaneTrait;
    use FocusableTrait;
    use InputHook;
    use KeybindingsTrait;
    use MenuOverlayHostTrait;
    use PaneNavigationCallbacks;
    use PanelRowStyle;

    /** The settings key the install field's source is handed to the page under. */
    public const string INSTALL_KEY = 'pluginInstall';

    /** @var list<StoreRow> */
    private array $rows = [];

    /** @var list<InstalledPlugin> */
    private array $installed = [];

    /** The "Save changes to" row the Installed tab leads with, or null where there is nothing to choose. */
    private ?SettingsRow $scope = null;

    private bool $refreshing = false;

    private int $updateCount = 0;

    private StoreTab $tab = StoreTab::Discover;

    /** The category whose whole shelf is shown, or null. */
    private ?StoreCategory $category = null;

    private readonly TextField $filter;

    private bool $filtering = false;

    /** The Installed tab's install field. */
    private readonly TextField $source;

    /** Whether the install field takes the keys: Enter opened it. */
    private bool $editing = false;

    /** The selected item's key, or null for the first. */
    private ?string $selected = null;

    /** The key of the item under the pointer, and whether the pointer is on its button. */
    private ?string $hovered = null;

    private bool $hoveredButton = false;

    /** @var list<StoreItem> the items the last frame drew */
    private array $items = [];

    /** The shelf's first drawn body line. */
    private int $scroll = 0;

    /** Whether each frame scrolls the selection into view: until the wheel scrolls the shelf away from it. */
    private bool $follow = true;

    /** The shelf rows above its body, in the last frame. */
    private int $chromeRows = 3;

    private readonly StoreDetail $page;

    /** @var array<string, Art> each package's pictures, as they are found */
    private array $art = [];

    /** Where the shelf's pictures are drawn. */
    private readonly ImageLayout $pictures;

    /** @var array<int, array{start: int, width: int, target: StoreTab}> the tab strip's hit zones, from the last render */
    private array $stripZones = [];

    /** The row the tab strip's labels are on, from the last render. */
    private int $stripRow = 2;

    private int $lastRows = 12;

    private readonly CrumbHeader $header;

    /** @var (callable(StoreEntry): void)|null */
    private $onPress;

    /** @var (callable(): void)|null */
    private $onUpdateAll;

    /** @var (callable(string, bool): void)|null an address the page needs: a text, or (true) a picture */
    private $onAsset;

    /** @var (callable(string, string): void)|null the install field or the scope changed: its key and value */
    private $onChange;

    /** @var (callable(SettingsRow, int, int, int, int): void)|null the scope select's list was asked for */
    private $onOpenChoice;

    public function __construct(
        private readonly Translator $translator,
        ?Styles $styles = null,
        ?CursorStyle $cursorStyle = null,
        private readonly ScreenDensity $density = new ScreenDensity(),
        ?TerminalGraphics $graphics = null,
        private readonly ?EventDispatcherInterface $events = null,
    ) {
        $this->initStyles($styles);
        $this->filter = new TextField(style: $cursorStyle);
        $this->source = new TextField(style: $cursorStyle);
        $this->header = new CrumbHeader();
        $this->page = new StoreDetail($translator, $this->styles, $graphics);
        $this->pictures = new ImageLayout($graphics);
        $this->setTrail();
        $this->addStyleClass('list');
    }

    /** @param callable(string): void $callback a crumb naming another page was clicked: that page's id */
    public function onCrumb(callable $callback): self
    {
        $this->header->onCrumb(function (string $id) use ($callback): void {
            // The store's own crumb, below it, is the way back to its shelf.
            'plugins' === $id && (null !== $this->detail() || null !== $this->category) ? $this->showTab($this->tab) : $callback($id);
        });

        return $this;
    }

    /** @param callable(StoreEntry): void $callback a plugin's button was pressed */
    public function onPress(callable $callback): self
    {
        $this->onPress = $callback;

        return $this;
    }

    /** @param callable(): void $callback Update all was pressed */
    public function onUpdateAll(callable $callback): self
    {
        $this->onUpdateAll = $callback;

        return $this;
    }

    /** @param callable(string, bool): void $callback the page needs this address: a text, or (true) a picture */
    public function onAsset(callable $callback): self
    {
        $this->onAsset = $callback;

        return $this;
    }

    /** @param callable(string, string): void $callback the install field was submitted ({@see INSTALL_KEY}) or the scope stepped: key and value */
    public function onChange(callable $callback): self
    {
        $this->onChange = $callback;

        return $this;
    }

    /** @param callable(SettingsRow, int, int, int, int): void $callback the scope select's list, under it: row, column, the row it is drawn from, width */
    public function onOpenChoice(callable $callback): self
    {
        $this->onOpenChoice = $callback;

        return $this;
    }

    /** Tint the link crumb at column $col of the title row, or none (null); returns whether that changed. */
    public function hoverCrumb(?int $col): bool
    {
        if (!$this->header->hover($col)) {
            return false;
        }
        $this->invalidate();

        return true;
    }

    /**
     * What the store shows: its rows, whether a refresh is on its way, and
     * how many installed plugins have a newer release.
     *
     * @param list<StoreRow> $rows
     */
    public function setStore(array $rows, bool $refreshing, int $updateCount): void
    {
        $this->rows = $rows;
        $this->refreshing = $refreshing;
        $this->updateCount = $updateCount;
        // The page's button may have changed: Get became Working, then Open.
        $this->page->stale();
        $package = $this->page->package();
        if (null !== $package && null === $this->row($package)) {
            $this->closeDetail();
        }
        $this->invalidate();
    }

    /**
     * What the Installed tab shows: every installed plugin, and the scope an
     * install lands in (null where there is nothing to choose).
     *
     * @param list<InstalledPlugin> $installed
     */
    public function setInstalled(array $installed, ?SettingsRow $scope): void
    {
        $this->installed = $installed;
        $this->scope = $scope;
        $this->invalidate();
    }

    /** What $url answered (null: nothing) — the page lays itself out again with it. */
    public function setAsset(string $url, ?string $body): void
    {
        $this->page->answer($url, $body);
        $this->invalidate();
    }

    /**
     * $package's pictures, found: its tile, and its page when it is open,
     * show them; what its page shows of them is asked for.
     */
    public function setArt(string $package, Art $art): void
    {
        $this->art[$package] = $art;
        $row = $this->detailRow();
        if (null !== $row && $package === $row->entry->package) {
            $this->requestAssets($row->entry);
        }
        $this->invalidate();
    }

    /** Back on the shelf, its tab, category and search as they were. */
    public function closeDetail(): void
    {
        $this->page->close();
        $this->follow = true;
        $this->setTrail();
        $this->invalidate();
    }

    /** The package whose page is open, or null on the shelf. */
    public function detail(): ?string
    {
        return $this->page->package();
    }

    /** Show $tab, from its top: the page asks for the Installed tab when the search lands on the install field. */
    public function showTab(StoreTab $tab, ?string $select = null): void
    {
        if (null !== $this->detail()) {
            $this->closeDetail();
        }
        $this->tab = $tab;
        $this->category = null;
        $this->editing = false;
        $this->selected = $select;
        $this->scroll = 0;
        $this->follow = true;
        $this->setTrail();
        $this->invalidate();
    }

    public function tab(): StoreTab
    {
        return $this->tab;
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();
        // The app's hook first, asked once.
        $hooked = $this->inputHookHandled($data);
        match (true) {
            !$hooked && null !== $this->detail() => $this->detailInput($data, $kb),
            // Then a field that is open: the search, or the install field.
            $hooked, $this->filtering && $this->editFilter($data, $kb), $this->editing && $this->editSource($data, $kb) => null,
            default => $this->shelfInput($data, $kb),
        };
    }

    private function shelfInput(string $data, Keybindings $kb): void
    {
        match (true) {
            $kb->matches($data, 'exit_view') => null === $this->category ? $this->fire($this->onExit) : $this->back(),
            $kb->matches($data, 'back') => $this->back(),
            $kb->matches($data, 'focus_next_pane') => $this->fire($this->onFocusNext),
            $kb->matches($data, 'show_settings') => $this->events?->dispatch(new SettingsSectionRequested('general')),
            $kb->matches($data, 'filter') => $this->startFilter(),
            $kb->matches($data, 'open') => $this->activate(),
            $kb->matches($data, 'press') => $this->press(),
            $kb->matches($data, 'next_tab') => $this->stepTab(1),
            $kb->matches($data, 'previous_tab') => $this->stepTab(-1),
            $kb->matches($data, 'show_updates') => $this->showTab(StoreTab::Updates),
            $kb->matches($data, 'update_all') => $this->fire($this->onUpdateAll),
            $kb->matches($data, 'move_up') => $this->move(-1, 0),
            $kb->matches($data, 'move_down') => $this->move(1, 0),
            $kb->matches($data, 'move_left') => $this->move(0, -1),
            $kb->matches($data, 'move_right') => $this->move(0, 1),
            $kb->matches($data, 'page_up') => $this->move(-$this->bodyRows(), 0),
            $kb->matches($data, 'page_down') => $this->move($this->bodyRows(), 0),
            default => null,
        };
    }

    private function detailInput(string $data, Keybindings $kb): void
    {
        $shots = \count($this->shots($this->detailRow()?->entry));
        $page = \max(1, $this->lastRows - 2);
        match (true) {
            $kb->matches($data, 'exit_view'), $kb->matches($data, 'back') => $this->closeDetail(),
            $kb->matches($data, 'focus_next_pane') => $this->fire($this->onFocusNext),
            $kb->matches($data, 'open'), $kb->matches($data, 'press') => $this->pressDetail(),
            $kb->matches($data, 'move_up') => $this->scrollDetail(-1),
            $kb->matches($data, 'move_down') => $this->scrollDetail(1),
            $kb->matches($data, 'page_up') => $this->scrollDetail(-$page),
            $kb->matches($data, 'page_down') => $this->scrollDetail($page),
            $shots > 1 && $kb->matches($data, 'move_right') => $this->showShot(\min($shots - 1, $this->page->shot() + 1)),
            $shots > 1 && $kb->matches($data, 'move_left') => $this->showShot(\max(0, $this->page->shot() - 1)),
            default => null,
        };
    }

    /**
     * A click: a crumb on the title row; a tab on the strip; on the shelf, a
     * button presses, an item selects, the selected item opens; on a page,
     * its button.
     */
    public function handleClick(int $relRow, int $relCol = 0): void
    {
        match (true) {
            0 === $relRow => $this->header->click($relCol),
            null !== $this->detail() => $this->clickPage($relRow, $relCol),
            $relRow < $this->chromeRows => $this->clickStrip($relRow, $relCol),
            default => $this->clickShelf($relRow, $relCol),
        };
    }

    /** On a page, only its button answers. */
    private function clickPage(int $relRow, int $relCol): void
    {
        if ($this->page->onButton($relRow - 2, $relCol)) {
            $this->pressDetail();
        }
    }

    /** On the shelf: a button presses, an item selects, the selected item opens. */
    private function clickShelf(int $relRow, int $relCol): void
    {
        $row = $relRow - $this->chromeRows + $this->scroll;
        $item = \array_find($this->items, static fn (StoreItem $item): bool => $item->contains($row, $relCol));
        if (null === $item) {
            return;
        }
        $open = $item->key === $this->selected;
        $this->selected = $item->key;
        match (true) {
            $item->onButton($row, $relCol) => $this->press(),
            $open || StoreItemKind::SeeAll === $item->kind || StoreItemKind::Scope === $item->kind => $this->activate(),
            default => $this->invalidate(),
        };
    }

    /**
     * Tint the item under the pointer at ($relRow, $relCol), or none (null);
     * returns whether that changed.
     */
    public function hoverAt(?int $relRow, int $relCol = 0): bool
    {
        $row = null === $relRow || null !== $this->detail() ? null : $relRow - $this->chromeRows + $this->scroll;
        $item = null === $row || $row < $this->scroll ? null : \array_find($this->items, static fn (StoreItem $item): bool => $item->contains($row, $relCol));
        $button = null !== $item && null !== $row && $item->onButton($row, $relCol);
        $pageButton = null !== $this->detail() && null !== $relRow && $this->page->onButton($relRow - 2, $relCol);
        $key = $pageButton ? 'page' : $item?->key;
        if ($key === $this->hovered && ($button || $pageButton) === $this->hoveredButton) {
            return false;
        }
        $this->hovered = $key;
        $this->hoveredButton = $button || $pageButton;
        $this->invalidate();

        return true;
    }

    /** The pointer left the store: nothing is tinted. Returns whether that changed. */
    public function clearHover(): bool
    {
        return $this->hoverAt(null);
    }

    /** The wheel scrolls the shelf or the page, leaving the selection where it is. */
    #[\Override]
    public function scrollBy(int $delta): void
    {
        if (null !== $this->detail()) {
            $this->scrollDetail($delta);

            return;
        }
        $this->scroll = \max(0, $this->scroll + $delta);
        $this->follow = false;
        $this->invalidate();
    }

    #[\Override]
    public function render(RenderContext $context): array
    {
        $width = $context->getColumns();
        $rows = \max(1, $context->getRows() > 0 ? $context->getRows() : 12);
        $this->lastRows = $rows;
        $row = $this->detailRow();
        $lines = null === $row ? $this->renderShelf($width, $rows) : $this->renderDetail($row, $width, $rows);
        while (\count($lines) < $rows) {
            $lines[] = AnsiText::blank($width);
        }

        return \array_slice($lines, offset: 0, length: $rows);
    }

    /** @return list<string> */
    private function renderShelf(int $width, int $rows): array
    {
        $hint = $this->refreshing
            ? $this->translator->trans('Asking Packagist…')
            : $this->translator->trans('⏎ details · Space get · [ ] tabs · / search');
        $lines = [$this->header->render($width, $this->styles, $hint), ...$this->strip($width)];
        if ($this->filtering) {
            $focused = $this->isFocused();
            \array_push($lines, ...FieldBox::field(
                new FieldSpec($this->translator->trans('Search')),
                $this->filter,
                FieldState::of($focused),
                $focused ? CaretVisibility::Shown : CaretVisibility::Hidden,
                $width,
                FieldBox::rowsFor($this->density->current()),
                $this->styles,
            ));
        }
        $this->chromeRows = \count($lines);
        $shelf = $this->shelf($width);
        if (null === \array_find($this->items, fn (StoreItem $item): bool => $item->key === $this->selected)) {
            // What was selected is not on this shelf: its first item is — a
            // plugin, not the "See all" over it.
            $first = \array_find($this->items, static fn (StoreItem $item): bool => StoreItemKind::SeeAll !== $item->kind);
            $this->selected = $first?->key;
            $shelf = $this->shelf($width);
        }
        $body = $shelf->lines();
        $room = \max(1, $rows - $this->chromeRows);
        $item = $this->item();
        if ($this->follow && null !== $item) {
            // The first item brings the shelf's top with it, its heading and all.
            $top = $item === $this->items[0] ? 0 : $item->top();
            $this->scroll = \min($this->scroll, $top);
            $this->scroll = \max($this->scroll, $item->row + $item->height - $room);
        }
        $this->scroll = \max(0, \min($this->scroll, \count($body) - $room));
        // The pictures this shelf no longer draws are freed in the terminal.
        $this->pictures->sweep();

        return [...$lines, ...\array_slice($body, $this->scroll, $room)];
    }

    /** The shelf laid out for this frame, its item map kept for the keys and the pointer. */
    private function shelf(int $width): StoreShelf
    {
        $shelf = new StoreShelf(
            $this->translator,
            $this->styles,
            $width,
            FieldBox::rowsFor($this->density->current(), $width),
            fn (string $text, string $key): string => $this->shadeRow($text, $key === $this->selected, $key === $this->hovered && $key !== $this->selected),
            $this->selected,
            $this->hovered,
            $this->hoveredButton,
            $this->isFocused() && !$this->filtering,
            art: $this->art,
            images: $this->pictures,
        );
        $query = \mb_strtolower(\trim($this->filter->value()));
        $matching = $this->matching($this->rows, $query);
        $browsing = StoreTab::Discover === $this->tab || StoreTab::Categories === $this->tab;
        $updates = \array_values(\array_filter($matching, static fn (StoreRow $row): bool => null !== $row->update));
        match (true) {
            $browsing && null !== $this->category => $shelf->flat(
                $this->translator->trans($this->category->label()),
                \array_values(\array_filter($matching, fn (StoreRow $row): bool => $this->category === $row->entry->category)),
            ),
            $browsing && [] === $this->rows => $shelf->say($this->emptyText()),
            $browsing && '' !== $query => $shelf->flat($this->translator->trans('Results for "%query%"', ['%query%' => \trim($this->filter->value())]), $matching),
            StoreTab::Discover === $this->tab => $shelf->discover($this->rows),
            StoreTab::Categories === $this->tab => $shelf->categories($this->rows),
            StoreTab::Updates === $this->tab && [] === $updates => $shelf->say($this->translator->trans('Every plugin is up to date')),
            StoreTab::Updates === $this->tab => $shelf->updates($updates),
            default => $shelf->installed(
                \array_values(\array_filter(
                    $this->installed,
                    static fn (InstalledPlugin $plugin): bool => '' === $query || \str_contains(\mb_strtolower($plugin->name.' '.$plugin->description), $query),
                )),
                $this->rows,
                $this->scope,
                $this->source,
                $this->editing,
            ),
        };
        if ([] === $shelf->items() && [] !== $this->rows && $browsing) {
            $shelf->say($this->translator->trans('No plugin matches'));
        }
        $this->items = $shelf->items();

        return $shelf;
    }

    /**
     * The rows a search leaves, featured first.
     *
     * @param list<StoreRow> $rows
     *
     * @return list<StoreRow>
     */
    private function matching(array $rows, string $query): array
    {
        $shown = \array_values(\array_filter($rows, static fn (StoreRow $row): bool => $row->entry->matches($query)));
        \usort($shown, static fn (StoreRow $a, StoreRow $b): int => (int) $b->entry->featured <=> (int) $a->entry->featured);

        return $shown;
    }

    /**
     * The tab strip: Discover, Categories, Updates and Installed as the dock's tabs.
     *
     * @return list<string>
     */
    private function strip(int $width): array
    {
        $tabs = [];
        foreach (StoreTab::cases() as $tab) {
            $label = $this->translator->trans($tab->label());
            if (StoreTab::Updates === $tab && $this->updateCount > 0) {
                $label .= ' ('.$this->updateCount.')';
            }
            $tabs[] = ['label' => $label, 'active' => $tab === $this->tab, 'closable' => false];
        }
        // Roomy, the strip draws the rule under the title itself, the pills' upper halves in it.
        $rows = TabStripRenderer::edgedRowsFor($this->density->current());
        $strip = TabStripRenderer::render($tabs, $width, $this->styles, rows: $rows, paneFocused: $this->isFocused());
        $lines = $strip['lines'];
        if (1 === $rows) {
            \array_unshift($lines, Rule::horizontal($width, $this->styles));
        }
        $this->stripRow = 1 + \count($lines) - \count($strip['lines']) + $strip['labelRow'];
        $this->stripZones = [];
        foreach ($strip['hitMap'] as $cell) {
            $this->stripZones[] = ['start' => $cell['start'], 'width' => $cell['width'], 'target' => StoreTab::cases()[$cell['index']]];
        }

        return \array_map(static fn (string $line): string => AnsiText::truncate($line, $width, pad: true), $lines);
    }

    private function clickStrip(int $relRow, int $col): void
    {
        if ($relRow !== $this->stripRow) {
            return;
        }
        $zone = \array_find($this->stripZones, static fn (array $zone): bool => $col >= $zone['start'] && $col < ($zone['start'] + $zone['width']));
        if (null !== $zone) {
            $this->showTab($zone['target']);
        }
    }

    /** Why the store is empty: a refresh on its way, or Packagist out of reach. */
    private function emptyText(): string
    {
        return $this->translator->trans($this->refreshing ? 'Asking Packagist…' : 'The store is empty: Packagist could not be reached');
    }

    /** @return list<string> */
    private function renderDetail(StoreRow $row, int $width, int $rows): array
    {
        $caption = \mb_strtolower($this->translator->trans($row->action->caption()));
        $hint = \count($this->shots($row->entry)) > 1
            ? $this->translator->trans('Esc back · ⏎ %action% · ←/→ screenshots', ['%action%' => $caption])
            : $this->translator->trans('Esc back · ⏎ %action%', ['%action%' => $caption]);
        $chrome = [$this->header->render($width, $this->styles, $hint), Rule::horizontal($width, $this->styles)];
        $controls = FieldBox::rowsFor($this->density->current(), $width);
        $room = \max(1, $rows - \count($chrome));

        return [...$chrome, ...$this->page->lines($row, $width, $controls, $room, 'page' === $this->hovered && $this->hoveredButton, $this->art[$row->entry->package] ?? null)];
    }

    /**
     * Ask for what the open page shows that has not been asked for: its
     * changelog, its README and the screenshot on screen. Never from a render:
     * an answer the cache already holds arrives before this returns.
     */
    private function requestAssets(StoreEntry $entry): void
    {
        $ask = $this->onAsset;
        if (null !== $ask) {
            foreach ($this->page->unasked($entry, $this->shots($entry)) as [$url, $image]) {
                $ask($url, $image);
            }
        }
    }

    /**
     * The screenshots $entry's page shows.
     *
     * @return list<string>
     */
    private function shots(?StoreEntry $entry): array
    {
        return null === $entry ? [] : StorePage::shots($entry, $this->art[$entry->package] ?? null);
    }

    private function detailRow(): ?StoreRow
    {
        $package = $this->page->package();

        return null === $package ? null : $this->row($package);
    }

    private function row(string $package): ?StoreRow
    {
        return \array_find($this->rows, static fn (StoreRow $row): bool => $package === $row->entry->package);
    }

    /** The selected item, from the last frame's map. */
    private function item(): ?StoreItem
    {
        return \array_find($this->items, fn (StoreItem $item): bool => $item->key === $this->selected);
    }

    /**
     * Move the selection $rows down (up when negative) the grid, or $columns
     * across a row: to the item nearest the selected one's column in the
     * nearest row that way.
     */
    private function move(int $rows, int $columns): void
    {
        $from = $this->item();
        if (null === $from) {
            return;
        }
        $target = $from->row + $rows;
        $candidates = \array_filter($this->items, static fn (StoreItem $item): bool => match (true) {
            $columns < 0 => $item->row === $from->row && $item->col < $from->col,
            $columns > 0 => $item->row === $from->row && $item->col > $from->col,
            $rows < 0 => $item->row < $from->row,
            default => $item->row > $from->row,
        });
        if ([] === $candidates) {
            return;
        }
        // The nearest row the step reaches, then the nearest column in it.
        \usort(
            $candidates,
            static fn (StoreItem $a, StoreItem $b): int => (
                [
                    0 === $columns ? \abs($a->row - $target) : 0,
                    \abs($a->col - $from->col),
                ] <=> [
                    0 === $columns ? \abs($b->row - $target) : 0,
                    \abs($b->col - $from->col),
                ]
            ),
        );
        $this->selected = $candidates[0]->key;
        $this->follow = true;
        $this->invalidate();
    }

    /** The shelf rows a page key moves by. */
    private function bodyRows(): int
    {
        return \max(1, $this->lastRows - $this->chromeRows);
    }

    /** Enter: a plugin's page, a category's shelf, Update all, the scope's list. */
    private function activate(): void
    {
        $item = $this->item();
        match ($item?->kind) {
            StoreItemKind::Tile => null === $item->store ? $this->openPluginPage((string) $item->plugin) : $this->openDetail($item->store),
            StoreItemKind::SeeAll, StoreItemKind::Category => $this->showCategory($item->category),
            StoreItemKind::UpdateAll => $this->fire($this->onUpdateAll),
            StoreItemKind::Scope => $this->openChoice($item),
            StoreItemKind::Install => $this->startEditing(),
            default => null,
        };
    }

    private function startEditing(): void
    {
        // One field takes the keys at a time: the search closes, its query kept.
        $this->filtering = false;
        $this->editing = true;
        $this->invalidate();
    }

    /** Space: the selected plugin's button, or what Enter does on anything else. */
    private function press(): void
    {
        $item = $this->item();
        if (StoreItemKind::Tile !== $item?->kind) {
            $this->activate();

            return;
        }
        $row = $item->store;
        match (true) {
            // An installed plugin's button is Update when the store has a newer release, Open otherwise.
            null !== $item->plugin && StoreAction::Update !== $row?->action => $this->openPluginPage($item->plugin),
            null !== $row && null !== $this->onPress && $row->action->pressable() => ($this->onPress)($row->entry),
            default => null,
        };
    }

    private function pressDetail(): void
    {
        $row = $this->detailRow();
        if (null !== $row && null !== $this->onPress && $row->action->pressable()) {
            ($this->onPress)($row->entry);
        }
    }

    private function openPluginPage(string $name): void
    {
        $this->events?->dispatch(new SettingsSectionRequested('plugin:'.$name, 'pluginEnabled'));
    }

    private function openChoice(StoreItem $item): void
    {
        $row = $this->chromeRows + $item->row - $this->scroll;
        if (null !== $this->scope && null !== $this->onOpenChoice) {
            ($this->onOpenChoice)($this->scope, $row + $item->height, $item->col, $row, $item->width);
        }
    }

    private function openDetail(StoreRow $row): void
    {
        $this->page->open($row->entry->package);
        $this->setTrail($row->entry->title);
        $this->requestAssets($row->entry);
        $this->invalidate();
    }

    private function showCategory(?StoreCategory $category): void
    {
        $this->category = $category;
        $this->selected = null;
        $this->scroll = 0;
        $this->follow = true;
        $this->setTrail();
        $this->invalidate();
    }

    /** Backspace, or Esc in a category, on the shelf: the shelf the category was opened from. */
    private function back(): void
    {
        if (null !== $this->category) {
            $key = 'category:'.$this->category->value;
            $this->showCategory(null);
            // Back on Categories, on the category just left.
            $this->selected = StoreTab::Categories === $this->tab ? $key : null;
        }
    }

    private function stepTab(int $step): void
    {
        $tabs = StoreTab::cases();
        $at = (int) \array_search($this->tab, $tabs, strict: true);
        $this->showTab($tabs[($at + $step + \count($tabs)) % \count($tabs)]);
    }

    private function scrollDetail(int $delta): void
    {
        $this->page->scrollBy($delta);
        $this->invalidate();
    }

    private function showShot(int $shot): void
    {
        $this->page->showShot($shot);
        $row = $this->detailRow();
        if (null !== $row) {
            $this->requestAssets($row->entry);
        }
        $this->invalidate();
    }

    private function startFilter(): void
    {
        $this->filtering = true;
        $this->invalidate();
    }

    /** While searching, the keys belong to the field ({@see FilterKeys}). */
    private function editFilter(string $data, Keybindings $kb): bool
    {
        $edit = FilterKeys::edit($this->filter, $data, $kb);
        $this->filtering = FilterKeys::Left !== $edit;
        if ($edit->refilters()) {
            $this->selected = null;
            $this->scroll = 0;
            $this->follow = true;
            $this->invalidate();
        }

        return FilterKeys::Ignored !== $edit;
    }

    /**
     * The install field, open, takes what is typed: Enter installs it, Esc
     * closes it; the arrows close it and move on.
     */
    private function editSource(string $data, Keybindings $kb): bool
    {
        $handled = match (true) {
            $kb->matches($data, 'open') => $this->submitSource(),
            $kb->matches($data, 'exit_view') => $this->stopEditing(),
            $kb->matches($data, 'delete_char_backward') => $this->eraseSource(),
            default => $this->source->insert($data),
        };
        if (!$handled) {
            $this->editing = false;
        }
        $this->invalidate();

        return $handled;
    }

    private function submitSource(): bool
    {
        $source = \trim($this->source->value());
        $this->stopEditing();
        if ('' !== $source && null !== $this->onChange) {
            ($this->onChange)(self::INSTALL_KEY, $source);
        }

        return true;
    }

    /** Backspace in the install field is the field's, even with nothing left to erase. */
    private function eraseSource(): bool
    {
        $this->source->backspace();

        return true;
    }

    private function stopEditing(): bool
    {
        $this->source->clear();
        $this->editing = false;

        return true;
    }

    private function setTrail(?string $page = null): void
    {
        $trail = [
            ['label' => $this->translator->trans('Settings'), 'id' => 'general'],
            ['label' => $this->translator->trans('Plugins'), 'id' => 'plugins'],
        ];
        if (null !== $this->category && null === $page) {
            $page = $this->translator->trans($this->category->label());
        }
        if (null !== $page) {
            $trail[] = ['label' => $page, 'id' => null];
        }
        $this->header->set($trail);
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'move_up' => [Key::UP],
            'move_down' => [Key::DOWN],
            'move_left' => [Key::LEFT],
            'move_right' => [Key::RIGHT],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'open' => [Key::ENTER],
            'press' => [Key::SPACE],
            'back' => [Key::BACKSPACE],
            'next_tab' => [']'],
            'previous_tab' => ['['],
            'show_updates' => ['u'],
            'update_all' => ['shift+u'],
            'filter' => ['ctrl+f', '/'],
            'delete_char_backward' => [Key::BACKSPACE],
            'show_settings' => ['1'],
            'focus_next_pane' => [Key::TAB],
            'exit_view' => [Key::ESCAPE],
        ];
    }
}
