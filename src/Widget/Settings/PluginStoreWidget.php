<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\Container\AsWidget;
use Porta\Event\SettingsSectionRequested;
use Porta\Help\Layout\ImageLayout;
use Porta\Help\Layout\PageLine;
use Porta\Help\MarkdownRenderer;
use Porta\I18n\Translator;
use Porta\Plugin\Store\StoreAction;
use Porta\Plugin\Store\StoreCategory;
use Porta\Plugin\Store\StoreEntry;
use Porta\Plugin\Store\StoreRow;
use Porta\Support\AnsiText;
use Porta\Ui\CaretVisibility;
use Porta\Ui\CursorStyle;
use Porta\Ui\FilterKeys;
use Porta\Ui\Glyphs;
use Porta\Ui\Graphics\TerminalGraphics;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\Role;
use Porta\Ui\ScreenDensity;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;
use Porta\Widget\HoverableList;
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
use Porta\Widget\Support\HoverTrait;
use Porta\Widget\Support\InputHook;
use Porta\Widget\Support\PanelRowStyle;
use Porta\Widget\Support\WheelScrollable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * The plugin store, the App Store's two pages in a settings pane.
 *
 * The shelf: a **Discover** tab listing every plugin and an **Updates** tab
 * listing the installed ones with a newer release, a category to narrow
 * either by, and a filter. Each plugin takes two rows — its title, badges and
 * button; then who made it, its shelf, its version and when it was released.
 *
 * The page (Enter): the plugin's header and button, its screenshots,
 * **What's New** (the newest section of its changelog), **About** (its
 * README) and **Information**. What the page shows beyond the catalog is not
 * this widget's to fetch: it asks through {@see onAsset()} and is handed each
 * answer through {@see setAsset()}, so nothing here waits on the network.
 *
 * Every button goes back to the controller as the entry pressed; which button
 * it is, and what it does, are the controller's.
 */
#[AsWidget('settings-store', flex: 1)]
final class PluginStoreWidget extends AbstractWidget implements FocusableInterface, HoverableList, MenuOverlayHost, VerticallyExpandableInterface, WheelScrollable
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

    /** Each plugin on the shelf: its title row and its facts row. */
    private const int ENTRY_ROWS = 2;

    /** The title, the rule under it and the tab strip, before any filter field. */
    private const int CHROME_ROWS = 3;

    /** How long a README the page lays out may be, in source lines: a store page is not the manual. */
    private const int README_LINES = 400;

    /** @var list<StoreRow> */
    private array $rows = [];

    /** @var list<StoreRow> the rows the tab, the category and the filter leave */
    private array $shown = [];

    private bool $updatesTab = false;

    private ?StoreCategory $category = null;

    private bool $refreshing = false;

    private int $updateCount = 0;

    private readonly TextField $filter;

    private bool $filtering = false;

    private readonly ListSelection $list;

    /** The package whose page is open, or null on the shelf. */
    private ?string $detail = null;

    /** The page's first drawn line. */
    private int $detailScroll = 0;

    /** The screenshot the page shows. */
    private int $shot = 0;

    /** @var array<string, string|null> each asked-for address and what it answered; absent while it is on its way */
    private array $assets = [];

    /** @var list<string>|null the page's laid-out lines, kept until the page, its width or an asset changes */
    private ?array $detailLines = null;

    /** @var array{?string, int, int} the [package, width, shot] the page was laid out for */
    private array $detailKey = [null, 0, 0];

    /** @var array<int, array{start: int, width: int, target: string}> the tab strip's hit zones, from the last render */
    private array $stripZones = [];

    /** @var array{int, int, int}|null the page's drawn button: row, first column, width; null when scrolled off */
    private ?array $buttonZone = null;

    private int $lastRows = 12;

    /** The width the last frame was drawn at, which is where a shelf row's button sits. */
    private int $drawnWidth = 80;

    /** @var array<string, true> the addresses already asked for, so a page laid out again does not ask twice */
    private array $asked = [];

    private readonly CrumbHeader $header;

    private readonly MarkdownRenderer $markdown;

    private readonly ImageLayout $images;

    /** @var (callable(StoreEntry): void)|null */
    private $onPress;

    /** @var (callable(): void)|null */
    private $onUpdateAll;

    /** @var (callable(string, bool): void)|null an address the page needs: a text, or (true) a picture */
    private $onAsset;

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
        $this->list = new ListSelection();
        $this->header = new CrumbHeader();
        $this->markdown = new MarkdownRenderer($this->styles, graphics: $graphics);
        $this->images = new ImageLayout($graphics);
        $this->setTrail();
        $this->addStyleClass('list');
    }

    /** @param callable(string): void $callback a crumb naming another page was clicked: that page's id */
    public function onCrumb(callable $callback): self
    {
        $this->header->onCrumb(function (string $id) use ($callback): void {
            // The store's own crumb, on its page, is the way back to the shelf.
            'store' === $id ? $this->closeDetail() : $callback($id);
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
        $this->applyFilter();
        // The page's button may have changed: Get became Working, then Open.
        $this->detailLines = null;
        if (null !== $this->detail && null === $this->row($this->detail)) {
            $this->closeDetail();
        }
        $this->invalidate();
    }

    /** What $url answered (null: nothing) — the page lays itself out again with it. */
    public function setAsset(string $url, ?string $body): void
    {
        $this->assets[$url] = $body;
        $this->detailLines = null;
        $this->invalidate();
    }

    /** Back on the shelf, its tab, category and filter as they were. */
    public function closeDetail(): void
    {
        $this->detail = null;
        $this->images->clear();
        $this->markdown->clearImages();
        $this->detailLines = null;
        $this->setTrail();
        $this->invalidate();
    }

    /** The package whose page is open, or null on the shelf. */
    public function detail(): ?string
    {
        return $this->detail;
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        if ($this->inputHookHandled($data)) {
            return;
        }
        $kb = $this->getKeybindings();
        if (null !== $this->detail) {
            $this->detailInput($data, $kb);

            return;
        }
        match (true) {
            $this->filtering && $this->editFilter($data, $kb) => null,
            $kb->matches($data, 'exit_view') => $this->fire($this->onExit),
            $kb->matches($data, 'focus_next_pane') => $this->fire($this->onFocusNext),
            $kb->matches($data, 'show_settings') => $this->events?->dispatch(new SettingsSectionRequested('general')),
            $kb->matches($data, 'filter') => $this->startFilter(),
            $kb->matches($data, 'open') => $this->openDetail(),
            $kb->matches($data, 'press') => $this->pressSelected(),
            $kb->matches($data, 'next_category') => $this->cycleCategory(1),
            $kb->matches($data, 'previous_category') => $this->cycleCategory(-1),
            $kb->matches($data, 'switch_tab') => $this->showUpdates(!$this->updatesTab),
            $kb->matches($data, 'update_all') => $this->fire($this->onUpdateAll),
            ListNavigation::handleKey($data, $kb, $this->list, \count($this->shown), page: $this->entriesFitting()) => $this->invalidate(),
            default => null,
        };
    }

    private function detailInput(string $data, Keybindings $kb): void
    {
        $shots = \count($this->detailRow()?->entry->screenshots ?? []);
        match (true) {
            $kb->matches($data, 'exit_view'), $kb->matches($data, 'back') => $this->closeDetail(),
            $kb->matches($data, 'focus_next_pane') => $this->fire($this->onFocusNext),
            $kb->matches($data, 'open'), $kb->matches($data, 'press') => $this->pressSelected(),
            $kb->matches($data, 'move_up') => $this->scrollDetail(-1),
            $kb->matches($data, 'move_down') => $this->scrollDetail(1),
            $kb->matches($data, 'page_up') => $this->scrollDetail(-\max(1, $this->lastRows - self::CHROME_ROWS)),
            $kb->matches($data, 'page_down') => $this->scrollDetail(\max(1, $this->lastRows - self::CHROME_ROWS)),
            $shots > 1 && $kb->matches($data, 'next_shot') => $this->showShot(($this->shot + 1) % $shots),
            $shots > 1 && $kb->matches($data, 'previous_shot') => $this->showShot(($this->shot + $shots - 1) % $shots),
            default => null,
        };
    }

    /**
     * A click: a crumb on the title row; a tab or the category on the strip;
     * on the shelf, a plugin's button presses it, its row selects it, and the
     * selected row opens its page; on a page, its button.
     */
    public function handleClick(int $relRow, int $relCol = 0): void
    {
        match (true) {
            0 === $relRow => $this->header->click($relCol),
            null !== $this->detail => $this->clickPage($relRow, $relCol),
            (self::CHROME_ROWS - 1) === $relRow => $this->clickStrip($relCol),
            default => $this->clickShelf($relRow, $relCol),
        };
    }

    /** On a page, only its button answers. */
    private function clickPage(int $relRow, int $relCol): void
    {
        $zone = $this->buttonZone;
        if (null !== $zone && $relRow === $zone[0] && $relCol >= $zone[1] && $relCol < ($zone[1] + $zone[2])) {
            $this->pressSelected();
        }
    }

    /** On the shelf: a button presses, a row selects, the selected row opens. */
    private function clickShelf(int $relRow, int $relCol): void
    {
        $index = $this->entryAt($relRow);
        if (null === $index) {
            return;
        }
        // The button is on a plugin's title row, at its right end.
        $onButton = 0 === (($relRow - $this->headerRows()) % self::ENTRY_ROWS) && $relCol >= $this->buttonColumn($index);
        $open = $index === $this->list->selected();
        $this->list->select($index);
        match (true) {
            $onButton => $this->pressSelected(),
            $open => $this->openDetail(),
            default => $this->invalidate(),
        };
    }

    #[\Override]
    public function hoverRow(int $relRow): bool
    {
        return $this->setHovered(null === $this->detail ? $this->entryAt($relRow) : null);
    }

    /** The wheel scrolls a page; on the shelf it is the arrows, as everywhere. */
    #[\Override]
    public function scrollBy(int $delta): void
    {
        if (null !== $this->detail) {
            $this->scrollDetail($delta);

            return;
        }
        $key = $delta < 0 ? "\x1b[A" : "\x1b[B";
        for ($i = 0; $i < \abs($delta); ++$i) {
            $this->handleInput($key);
        }
    }

    #[\Override]
    public function render(RenderContext $context): array
    {
        $width = $context->getColumns();
        $rows = \max(1, $context->getRows() > 0 ? $context->getRows() : 12);
        $this->lastRows = $rows;
        $this->drawnWidth = $width;
        $this->buttonZone = null;

        $row = $this->detailRow();

        return null === $row ? $this->renderShelf($width, $rows) : $this->renderDetail($row, $width, $rows);
    }

    /** @return list<string> */
    private function renderShelf(int $width, int $rows): array
    {
        $hint = $this->refreshing
            ? $this->translator->trans('Asking Packagist…')
            : $this->translator->trans('⏎ details · Space get or update · c category · u updates · / filter');
        $lines = [$this->header->render($width, $this->styles, $hint), Rule::horizontal($width, $this->styles), $this->strip($width)];
        if ($this->filtering) {
            $focused = $this->isFocused();
            \array_push($lines, ...FieldBox::field(
                new FieldSpec($this->translator->trans('Filter')),
                $this->filter,
                FieldState::of($focused),
                $focused ? CaretVisibility::Shown : CaretVisibility::Hidden,
                $width,
                FieldBox::rowsFor($this->density->current()),
                $this->styles,
            ));
        }
        $top = \count($lines);
        if ([] === $this->shown) {
            $lines[] = AnsiText::truncate(' '.$this->styles->of(Role::Dim)->apply($this->emptyText()), $width, pad: true);
        }
        $fit = \max(1, \intdiv($rows - $top, self::ENTRY_ROWS));
        $scroll = $this->list->follow($fit);
        for ($index = $scroll; $index < \min(\count($this->shown), $scroll + $fit); ++$index) {
            $selected = $index === $this->list->selected();
            $hovered = $index === $this->hoveredIndex;
            [$first, $second] = $this->entryLines($this->shown[$index], $width, $selected);
            $lines[] = $this->shadeRow($first, $selected, $hovered);
            $lines[] = $this->shadeRow($second, $selected, $hovered);
        }
        while (\count($lines) < $rows) {
            $lines[] = AnsiText::blank($width);
        }

        return \array_slice($lines, offset: 0, length: $rows);
    }

    /**
     * The tab strip: Discover and Updates as the dock's tabs, then the
     * category it is narrowed to.
     */
    private function strip(int $width): string
    {
        $updates = $this->translator->trans('Updates').($this->updateCount > 0 ? ' ('.$this->updateCount.')' : '');
        $strip = TabStripRenderer::render(
            [
                ['label' => $this->translator->trans('Discover'), 'active' => !$this->updatesTab, 'closable' => false],
                ['label' => $updates, 'active' => $this->updatesTab, 'closable' => false],
            ],
            $width,
            $this->styles,
        );
        $this->stripZones = [];
        foreach ($strip['hitMap'] as $cell) {
            $this->stripZones[] = ['start' => $cell['start'], 'width' => $cell['width'], 'target' => 0 === $cell['index'] ? 'discover' : 'updates'];
        }
        $category = ' '.$this->translator->trans($this->category?->label() ?? 'All categories').' '.Glyphs::EXPANDED;
        $this->stripZones[] = ['start' => $strip['used'] + 1, 'width' => AnsiUtils::visibleWidth($category), 'target' => 'category'];
        $line = AnsiText::truncate($strip['lines'][0] ?? '', $strip['used']).' '.$this->styles->of(Role::TabAccent)->apply($category);

        return AnsiText::truncate($line, $width, pad: true);
    }

    private function clickStrip(int $col): void
    {
        foreach ($this->stripZones as $zone) {
            if ($col < $zone['start'] || $col >= ($zone['start'] + $zone['width'])) {
                continue;
            }
            match ($zone['target']) {
                'discover' => $this->showUpdates(false),
                'updates' => $this->showUpdates(true),
                default => $this->cycleCategory(1),
            };

            return;
        }
    }

    /**
     * A plugin's two shelf rows.
     *
     * @return array{string, string}
     */
    private function entryLines(StoreRow $row, int $width, bool $selected): array
    {
        $entry = $row->entry;
        $badges = [];
        if ($entry->verified) {
            $badges[] = $this->styles->of(Role::TabAccent)->apply(Glyphs::MD_CHECK.' '.$this->translator->trans('Verified'));
        }
        if ($entry->featured) {
            $badges[] = $this->styles->of(Role::TabAccent)->apply($this->translator->trans('Featured'));
        }
        $button = $this->button($row, $selected);
        $buttonWidth = AnsiUtils::visibleWidth($button);
        $title = $this->styles->of(Role::Bright)->apply($entry->title).([] === $badges ? '' : '  '.\implode('  ', $badges));
        $first = AnsiText::truncate(' '.$title, \max(0, $width - $buttonWidth - 1), pad: true).$button.' ';
        $second = AnsiText::truncate('   '.$this->styles->of(Role::Dim)->apply($this->facts($row)), $width, pad: true);

        return [$this->barred(AnsiText::truncate($first, $width, pad: true), $selected), $second];
    }

    /** Who made it, its shelf, its version — or the update it has — when it was released, how often it was got. */
    private function facts(StoreRow $row): string
    {
        $entry = $row->entry;
        $version = null === $row->update ? $entry->version : $row->update->from.' → '.$row->update->to;
        $facts = [$entry->author, $this->translator->trans($entry->category->label()), $version];
        if ($entry->time > 0) {
            $facts[] = \gmdate('Y-m-d', $entry->time);
        }
        if ($entry->downloads > 0) {
            $facts[] = $this->translator->trans('%count% downloads', ['%count%' => \number_format($entry->downloads)]);
        }

        return \implode(' · ', $facts);
    }

    /** The button, `[ Get ]`: bright while it does something, faint when it does not. */
    private function button(StoreRow $row, bool $selected): string
    {
        $caption = '[ '.$this->translator->trans($row->action->caption()).' ]';
        $role = match (true) {
            !$row->action->pressable() => Role::Faint,
            StoreAction::Update === $row->action => Role::Modified,
            $selected => Role::Bright,
            default => Role::TabAccent,
        };

        return $this->styles->of($role)->apply($caption);
    }

    private function buttonWidth(StoreRow $row): int
    {
        return AnsiUtils::visibleWidth('[ '.$this->translator->trans($row->action->caption()).' ]');
    }

    /** The shelf column a click on shown entry $index's title row presses its button from. */
    private function buttonColumn(int $index): int
    {
        return \max(0, $this->drawnWidth - 1 - $this->buttonWidth($this->shown[$index]));
    }

    /** Why the shelf is empty: the first reason that holds, a refresh on its way first. */
    private function emptyText(): string
    {
        // The reasons that hold, in order; the first of them is said.
        $reason = \array_key_first(\array_filter([
            'Asking Packagist…' => $this->refreshing,
            'Every plugin is up to date' => $this->updatesTab,
            'The store is empty: Packagist could not be reached' => [] === $this->rows,
        ]));

        return $this->translator->trans($reason ?? 'No plugin matches');
    }

    /** @return list<string> */
    private function renderDetail(StoreRow $row, int $width, int $rows): array
    {
        $hint = \count($row->entry->screenshots) > 1
            ? $this->translator->trans('Esc back · ⏎ %action% · ←/→ screenshots', ['%action%' => \mb_strtolower($this->translator->trans($row->action->caption()))])
            : $this->translator->trans('Esc back · ⏎ %action%', ['%action%' => \mb_strtolower($this->translator->trans($row->action->caption()))]);
        $chrome = [$this->header->render($width, $this->styles, $hint), Rule::horizontal($width, $this->styles)];
        $body = $this->detailLines($row, $width);
        $room = \max(1, $rows - \count($chrome));
        $this->detailScroll = \max(0, \min($this->detailScroll, \count($body) - $room));
        // The header is the body's first line: its button scrolls with it.
        if (0 === $this->detailScroll) {
            $this->buttonZone = [\count($chrome), $width - 1 - $this->buttonWidth($row), $this->buttonWidth($row)];
        }
        $lines = [...$chrome, ...\array_slice($body, $this->detailScroll, $room)];
        while (\count($lines) < $rows) {
            $lines[] = AnsiText::blank($width);
        }

        return $lines;
    }

    /**
     * The page, laid out once per plugin, width, screenshot and arrived asset.
     *
     * @return list<string>
     */
    private function detailLines(StoreRow $row, int $width): array
    {
        $key = [$row->entry->package, $width, $this->shot];
        if (null !== $this->detailLines && $key === $this->detailKey) {
            return $this->detailLines;
        }
        $this->detailKey = $key;
        $entry = $row->entry;
        $dim = $this->styles->of(Role::Dim);
        $button = $this->button($row, true);
        $lines = [
            AnsiText::truncate(' '.$this->styles->of(Role::Bright)->apply($entry->title), \max(0, $width - AnsiUtils::visibleWidth($button) - 1), pad: true).$button.' ',
            ' '
                .$dim->apply(\implode(' · ', \array_filter([
                    $this->translator->trans('by %author%', ['%author%' => $entry->author]),
                    $this->translator->trans($entry->category->label()),
                    $entry->version,
                    $entry->license,
                    $entry->verified ? Glyphs::MD_CHECK.' '.$this->translator->trans('Verified') : '',
                ]))),
        ];
        if (null !== $entry->replaces) {
            $lines[] = ' '.$this->styles->of(Role::TabAccent)->apply($this->translator->trans('Replaces the built-in %plugin% plugin', ['%plugin%' => $entry->replaces]));
        }
        if (!$entry->compatible) {
            $lines[] = ' '.$this->styles->of(Role::DiagWarn)->apply($this->translator->trans('No release of it runs on this Porta'));
        }
        if ('' !== $entry->description) {
            $lines[] = '';
            $lines[] = ' '.$entry->description;
        }
        $lines = [...$lines, ...$this->preview($entry, $width), ...$this->whatsNew($row, $width), ...$this->about($entry, $width), ...$this->information($row, $width)];

        return $this->detailLines = \array_map(static fn (string $line): string => AnsiText::truncate($line, $width, pad: true), $lines);
    }

    /** @return list<string> */
    private function preview(StoreEntry $entry, int $width): array
    {
        $url = $entry->screenshots[$this->shot] ?? null;
        if (null === $url) {
            return [];
        }
        $count = \count($entry->screenshots);
        $legend = $this->translator->trans('Preview').($count > 1 ? '  '.Glyphs::SCROLL_PREV.' '.($this->shot + 1).'/'.$count.' '.Glyphs::SCROLL_NEXT : '');
        $lines = ['', Rule::horizontal($width, $this->styles, $legend)];
        $bytes = $this->asset($url);
        $image = null === $bytes ? null : $this->images->png($bytes, \max(1, $width - 2));
        $missing = \array_key_exists($url, $this->assets) ? $this->translator->trans('The screenshot could not be shown') : $this->translator->trans('Loading…');
        $shown = null === $image ? [' '.$this->styles->of(Role::Dim)->apply($missing)] : \array_map(static fn (PageLine $line): string => ' '.$line->text, $image);

        return [...$lines, ...$shown];
    }

    /** @return list<string> */
    private function whatsNew(StoreRow $row, int $width): array
    {
        $entry = $row->entry;
        $title = $this->translator->trans("What's New").'  '.$entry->version.($entry->time > 0 ? ' · '.\gmdate('Y-m-d', $entry->time) : '');
        $lines = ['', Rule::horizontal($width, $this->styles, $title)];
        $changelog = null === $entry->changelog ? null : $this->asset($entry->changelog);
        $section = null === $changelog ? '' : self::newestSection($changelog);
        $body = match (true) {
            '' !== $section => $this->markdown->render($section, $width)->lines,
            null !== $entry->changelog && !\array_key_exists($entry->changelog, $this->assets) => [' '.$this->styles->of(Role::Dim)->apply($this->translator->trans('Loading…'))],
            // No changelog to read: the release history says what it can.
            default => $this->history($entry),
        };

        return [...$lines, ...$body];
    }

    /**
     * The newest releases, each with its date.
     *
     * @return list<string>
     */
    private function history(StoreEntry $entry): array
    {
        $dim = $this->styles->of(Role::Dim);

        return \array_map(
            static fn (array $release): string => ' '.$release[0].($release[1] > 0 ? $dim->apply('  '.\gmdate('Y-m-d', $release[1])) : ''),
            \array_slice($entry->history, offset: 0, length: 8),
        );
    }

    /** @return list<string> */
    private function about(StoreEntry $entry, int $width): array
    {
        $readme = null === $entry->readme ? null : $this->asset($entry->readme);
        if (null === $readme) {
            return [];
        }
        $source = \array_slice(\explode("\n", $readme), offset: 0, length: self::README_LINES);
        // The page already says what it is called.
        if (\str_starts_with($source[0] ?? '', '# ')) {
            \array_shift($source);
        }

        return ['', Rule::horizontal($width, $this->styles, $this->translator->trans('About')), ...$this->markdown->render(\implode("\n", $source), $width)->lines];
    }

    /** @return list<string> */
    private function information(StoreRow $row, int $width): array
    {
        $entry = $row->entry;
        $facts = [
            'Provider' => $entry->author,
            'Package' => $entry->package,
            'Category' => $this->translator->trans($entry->category->label()),
            'Version' =>
                $entry->version.('' !== $row->installed && $row->installed !== $entry->version ? ' ('.$this->translator->trans('%version% installed', ['%version%' => $row->installed]).')' : ''),
            'Updated' => $entry->time > 0 ? \gmdate('Y-m-d', $entry->time) : '',
            'License' => $entry->license,
            'Downloads' => $entry->downloads > 0 ? \number_format($entry->downloads) : '',
            'Source' => $entry->source,
        ];
        $lines = ['', Rule::horizontal($width, $this->styles, $this->translator->trans('Information'))];
        $labels = [];
        foreach ($facts as $label => $value) {
            if ('' === $value) {
                continue;
            }
            $labels[$this->translator->trans($label)] = $value;
        }
        $column = \max(\array_map(AnsiUtils::visibleWidth(...), [...\array_keys($labels), ''])) + 2;
        foreach ($labels as $label => $value) {
            $lines[] = ' '.$this->styles->of(Role::Dim)->apply(AnsiText::truncate($label, $column, pad: true)).$value;
        }

        return $lines;
    }

    /**
     * The newest release's section of a Keep-a-Changelog file: the first
     * `## ` heading that names a version (not Unreleased), up to the next.
     */
    public static function newestSection(string $changelog): string
    {
        $section = [];
        $inside = false;
        foreach (\explode("\n", \str_replace("\r\n", replace: "\n", subject: $changelog)) as $line) {
            $heading = \str_starts_with($line, '## ');
            if ($heading && $inside) {
                break;
            }
            if ($heading && 1 === \preg_match('/\d/', $line) && false === \stripos($line, needle: 'unreleased')) {
                $inside = true;

                continue;
            }
            if ($inside) {
                $section[] = $line;
            }
        }

        return \trim(\implode("\n", $section));
    }

    /** What $url answered; null while it is on its way, or when it answered nothing. */
    private function asset(string $url): ?string
    {
        return $this->assets[$url] ?? null;
    }

    /**
     * Ask for what the open page shows that has not been asked for: its
     * changelog, its README and the screenshot on screen. Never from a render:
     * an answer the cache already holds arrives before this returns.
     */
    private function requestAssets(StoreEntry $entry): void
    {
        $wanted = [[$entry->changelog, false], [$entry->readme, false], [$entry->screenshots[$this->shot] ?? null, true]];
        foreach ($wanted as [$url, $image]) {
            if (null === $url || \array_key_exists($url, $this->asked) || null === $this->onAsset) {
                continue;
            }
            $this->asked[$url] = true;
            ($this->onAsset)($url, $image);
        }
    }

    private function detailRow(): ?StoreRow
    {
        return null === $this->detail ? null : $this->row($this->detail);
    }

    private function row(string $package): ?StoreRow
    {
        return \array_find($this->rows, static fn (StoreRow $row): bool => $package === $row->entry->package);
    }

    private function openDetail(): void
    {
        $row = $this->shown[$this->list->selected()] ?? null;
        if (null === $row) {
            return;
        }
        $this->detail = $row->entry->package;
        $this->detailScroll = 0;
        $this->shot = 0;
        $this->detailLines = null;
        $this->setTrail($row->entry->title);
        $this->requestAssets($row->entry);
        $this->invalidate();
    }

    private function pressSelected(): void
    {
        $row = $this->detailRow() ?? $this->shown[$this->list->selected()] ?? null;
        if (null !== $row && null !== $this->onPress && $row->action->pressable()) {
            ($this->onPress)($row->entry);
        }
    }

    private function scrollDetail(int $delta): void
    {
        $this->detailScroll = \max(0, $this->detailScroll + $delta);
        $this->invalidate();
    }

    private function showShot(int $shot): void
    {
        $this->shot = $shot;
        $this->detailLines = null;
        $row = $this->detailRow();
        if (null !== $row) {
            $this->requestAssets($row->entry);
        }
        $this->invalidate();
    }

    private function showUpdates(bool $updates): void
    {
        $this->updatesTab = $updates;
        $this->list->select(0);
        $this->applyFilter();
        $this->invalidate();
    }

    private function cycleCategory(int $step): void
    {
        $choices = [null, ...StoreCategory::cases()];
        $at = (int) \array_search($this->category, $choices, strict: true);
        $this->category = $choices[($at + $step + \count($choices)) % \count($choices)];
        $this->list->select(0);
        $this->applyFilter();
        $this->invalidate();
    }

    private function startFilter(): void
    {
        $this->filtering = true;
        $this->invalidate();
    }

    /** While filtering, the keys belong to the field ({@see FilterKeys}). */
    private function editFilter(string $data, Keybindings $kb): bool
    {
        $edit = FilterKeys::edit($this->filter, $data, $kb);
        $this->filtering = FilterKeys::Left !== $edit;
        if ($edit->refilters()) {
            $this->list->select(0);
            $this->applyFilter();
            $this->invalidate();
        }

        return FilterKeys::Ignored !== $edit;
    }

    /** The rows the tab, the category and the filter leave, featured first. */
    private function applyFilter(): void
    {
        $query = \mb_strtolower(\trim($this->filter->value()));
        $shown = \array_values(\array_filter(
            $this->rows,
            fn (StoreRow $row): bool => (!$this->updatesTab || null !== $row->update) && (null === $this->category || $this->category === $row->entry->category) && $row->entry->matches($query),
        ));
        \usort($shown, static fn (StoreRow $a, StoreRow $b): int => (int) $b->entry->featured <=> (int) $a->entry->featured);
        $this->shown = $shown;
        $this->list->clamp(\count($this->shown));
    }

    /** The shelf rows above the first plugin. */
    private function headerRows(): int
    {
        return self::CHROME_ROWS + ($this->filtering ? FieldBox::rowsFor($this->density->current()) : 0);
    }

    private function entriesFitting(): int
    {
        return \max(1, \intdiv($this->lastRows - $this->headerRows(), self::ENTRY_ROWS));
    }

    /** The shelf entry drawn at widget row $relRow, or null. */
    private function entryAt(int $relRow): ?int
    {
        $offset = $relRow - $this->headerRows();
        if ($offset < 0 || [] === $this->shown) {
            return null;
        }
        $index = $this->list->scroll() + \intdiv($offset, self::ENTRY_ROWS);

        return $index < \count($this->shown) ? $index : null;
    }

    private function setTrail(?string $page = null): void
    {
        $trail = [
            ['label' => $this->translator->trans('Settings'), 'id' => 'general'],
            ['label' => $this->translator->trans('Plugins'), 'id' => 'plugins'],
            ['label' => $this->translator->trans('Store'), 'id' => 'store'],
        ];
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
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'open' => [Key::ENTER],
            'press' => [Key::SPACE],
            'back' => [Key::BACKSPACE],
            'next_shot' => [Key::RIGHT],
            'previous_shot' => [Key::LEFT],
            'next_category' => ['c'],
            'previous_category' => ['shift+c'],
            'switch_tab' => ['u'],
            'update_all' => ['shift+u'],
            'filter' => ['ctrl+f', '/'],
            'delete_char_backward' => [Key::BACKSPACE],
            'show_settings' => ['1'],
            'focus_next_pane' => [Key::TAB],
            'exit_view' => [Key::ESCAPE],
        ];
    }
}
