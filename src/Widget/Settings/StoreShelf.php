<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\Help\Layout\ImageLayout;
use Porta\I18n\BaseDirection;
use Porta\I18n\Translator;
use Porta\Plugin\Store\Art;
use Porta\Plugin\Store\StoreAction;
use Porta\Plugin\Store\StoreCategory;
use Porta\Plugin\Store\StoreRow;
use Porta\Settings\InstalledPlugin;
use Porta\Settings\SettingsRow;
use Porta\Support\AnsiText;
use Porta\Ui\CaretVisibility;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\FieldBox;
use Porta\Widget\Render\FieldSpec;
use Porta\Widget\Render\FieldState;
use Porta\Widget\Render\Rule;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The store's shelf laid out for one frame: the lines under the tab strip and
 * the {@see StoreItem} map of where each thing the keyboard stops on was drawn.
 *
 * Discover is the App Store's front page — the featured plugins, then a
 * shelf per category of as many tiles as two rows of the grid hold, each with
 * "See all". A category shows its whole shelf; a search, every match. Tiles
 * stand two to a row where the pane has room for two, one where it does not.
 *
 * Built afresh for each frame, from what the widget holds; it keeps nothing.
 */
final class StoreShelf
{
    /** The narrowest the install field stands beside the scope select. */
    private const int FIELD_WIDTH = 30;

    /** The columns between two tiles of a row. */
    private const int GAP = 2;

    /** The tallest a featured plugin's picture stands over its tile. */
    public const int HERO_ROWS = 10;

    /** @var list<string> */
    private array $lines = [];

    /** @var list<StoreItem> */
    private array $items = [];

    private readonly StoreTile $tile;

    /** The interface's direction: a mirrored row holds each of its cells in place. */
    private readonly BaseDirection $direction;

    /** @var 1|2 how many tiles stand in a row */
    private readonly int $columns;

    private readonly int $tileWidth;

    /**
     * @param positive-int                         $rows     the screen's control height: {@see FieldBox::ROWS}, or 1
     * @param \Closure(string, string): string     $shade    a drawn line of the item keyed so, shaded as the selected and the hovered item are
     * @param string|null                          $selected the selected item's key
     * @param string|null                          $hovered  the key of the item under the pointer
     * @param bool                                 $focused  whether the widget takes the keys, so the selected field shows its caret
     * @param array<string, Art>                   $art      each package's pictures, as far as they are found
     * @param ImageLayout|null                     $images   where pictures are drawn; null draws none
     */
    public function __construct(
        private readonly Translator $translator,
        private readonly Styles $styles,
        private readonly int $width,
        private readonly int $rows,
        private readonly \Closure $shade,
        private readonly ?string $selected,
        private readonly ?string $hovered,
        private readonly bool $hoveredButton,
        private readonly bool $focused,
        // The banner's grid is no wider than its tiles: one featured plugin spans it.
        int $tiles = 2,
        private readonly array $art = [],
        private readonly ?ImageLayout $images = null,
    ) {
        $this->direction = $translator->isRtl() ? BaseDirection::Rtl : BaseDirection::Ltr;
        $this->tile = new StoreTile($styles, $this->direction);
        $this->columns = $tiles > 1 && $width >= ((2 * StoreTile::MIN_WIDTH) + self::GAP + 1) ? 2 : 1;
        $this->tileWidth = 2 === $this->columns ? \intdiv($width - self::GAP - 1, num2: 2) : $width - 1;
    }

    /**
     * The front page: the featured plugins, then each category's shelf.
     *
     * @param list<StoreRow> $rows
     */
    public function discover(array $rows): void
    {
        $featured = \array_values(\array_filter($rows, static fn (StoreRow $row): bool => $row->entry->featured));
        if ([] !== $featured) {
            $this->banner($this->translator->trans('Featured'), $featured);
        }
        // Two rows of the grid: as many as a glance takes in.
        $cap = 2 * $this->columns;
        foreach (StoreCategory::cases() as $category) {
            $shelf = \array_values(\array_filter($rows, static fn (StoreRow $row): bool => $category === $row->entry->category));
            if ([] === $shelf) {
                continue;
            }
            $more = \count($shelf) > $cap ? $category : null;
            $this->shelf($this->translator->trans($category->label()), $category->value, \array_slice($shelf, offset: 0, length: $cap), $more);
        }
    }

    /**
     * One category's whole shelf, or a search's every match: a heading and the grid.
     *
     * @param list<StoreRow> $rows
     */
    public function flat(string $heading, array $rows): void
    {
        $this->shelf($heading, 'all', $rows, null);
    }

    /**
     * The Categories tab: each category, how many plugins it has, and the way into its shelf.
     *
     * @param list<StoreRow> $rows
     */
    public function categories(array $rows): void
    {
        $this->blank();
        foreach (StoreCategory::cases() as $category) {
            $count = \count(\array_filter($rows, static fn (StoreRow $row): bool => $category === $row->entry->category));
            $key = 'category:'.$category->value;
            $label = $this->translator->trans($category->label());
            $tail = $this->styles->of(Role::Dim)->apply((string) $count).' '.Glyphs::NAV_FORWARD;
            $room = $this->width - 1 - AnsiUtils::visibleWidth($tail) - 1;
            $text = ' '.StoreTile::text(0 === $count ? $this->styles->of(Role::Dim)->apply($label) : $label, $room - 1, $this->direction).StoreTile::cell($tail, $this->direction);
            $this->add(new StoreItem($key, StoreItemKind::Category, \count($this->lines), 0, 1, $this->width, category: $category), [$text]);
            if (FieldBox::ROWS === $this->rows) {
                $this->lines[] = ' '.Rule::horizontal($this->width - 2, $this->styles);
            }
        }
    }

    /**
     * The Updates tab: Update all, then each plugin with a newer release.
     *
     * @param list<StoreRow> $rows the rows with an update
     */
    public function updates(array $rows): void
    {
        $this->blank();
        $caption = $this->translator->trans('Update all');
        $width = FieldBox::controlWidth($caption);
        $heading = ' '.$this->styles->of(Role::Bright)->withBold()->apply($this->translator->trans('Updates'));
        $button = FieldBox::button($caption, FieldState::Idle, 'update-all' === $this->hovered, $width, $this->rows, $this->styles);
        $col = \max(0, $this->width - $width - 1);
        $row = \count($this->lines);
        $middle = \intdiv(\count($button), num2: 2);
        foreach ($button as $i => $line) {
            $this->lines[] = AnsiText::truncate(
                StoreTile::text($middle === $i ? $heading : '', $col, $this->direction).StoreTile::cell($this->shadeIf($line, 'update-all'), $this->direction),
                $this->width,
                pad: true,
            );
        }
        $this->items[] = new StoreItem('update-all', StoreItemKind::UpdateAll, $row, $col, \count($button), $width, button: [$row, $col, $width, \count($button)]);
        $this->grid($rows, 'updates', [], $row);
    }

    /**
     * The Installed tab: where an install lands and the field that installs
     * from anywhere, then every installed plugin.
     *
     * @param list<InstalledPlugin> $installed
     * @param list<StoreRow>        $rows      every store row, for the installed ones it knows
     * @param bool                  $editing   whether the install field takes the keys
     */
    // @mago-expect lint:no-boolean-flag-parameter -- the field is drawn open or shut; which, is the widget's state.
    public function installed(array $installed, array $rows, ?SettingsRow $scope, TextField $source, bool $editing): void
    {
        $this->blank();
        $row = \count($this->lines);
        $band = \array_fill(start_index: 0, count: $this->rows, value: '');
        $col = 1;
        if (null !== $scope) {
            $labels = \array_map(static fn (string $option): string => $scope->optionLabels[$option] ?? $option, $scope->options ?? []);
            // Boxed, the label rides the top border; on one row it stands before the box.
            $label = AnsiUtils::visibleWidth($scope->label);
            $natural = FieldBox::ROWS === $this->rows ? \max(FieldBox::selectWidth($labels), $label + 6) : FieldBox::selectWidth($labels) + $label + 1;
            // Beside the field while both read whole; over it where they would not.
            $beside = ($natural + self::FIELD_WIDTH + 4) <= $this->width;
            $width = \min($natural, $this->width - 2);
            $value = $scope->optionLabels[(string) $scope->value] ?? (string) $scope->value;
            // A select never takes the keys: selected, it is shaded, its frame idle.
            $select = FieldBox::select(new FieldSpec($scope->label), $value, FieldState::Idle, $width, $this->rows, $this->styles);
            foreach ($select as $i => $line) {
                $band[$i] = ' '.StoreTile::cell($this->shadeIf($line, 'scope'), $this->direction).'  ';
            }
            $this->items[] = new StoreItem('scope', StoreItemKind::Scope, $row, $col, $this->rows, $width);
            if (!$beside) {
                foreach ($band as $line) {
                    $this->lines[] = AnsiText::truncate($line, $this->width, pad: true);
                }
                $band = [];
                $row = \count($this->lines);
            }
            $col = $beside ? $col + $width + 2 : 1;
        }
        $width = \max(FieldBox::MIN_BOXED, $this->width - $col - 1);
        // The field takes the keys once Enter opened it; selected, it is only shaded.
        $field = FieldBox::field(
            new FieldSpec($this->translator->trans('Install from URL or path'), $this->translator->trans('⏎ to type a git URL, local path, or vendor/name package')),
            $source,
            FieldState::of($editing && $this->focused),
            $editing && $this->focused ? CaretVisibility::Shown : CaretVisibility::Hidden,
            $width,
            $this->rows,
            $this->styles,
        );
        foreach ($field as $i => $line) {
            $this->lines[] = AnsiText::truncate(AnsiText::padRight($band[$i] ?? '', $col).StoreTile::cell($this->shadeIf($line, 'install'), $this->direction), $this->width, pad: true);
        }
        $this->items[] = new StoreItem('install', StoreItemKind::Install, $row, $col, $this->rows, $width);
        $this->shelf($this->translator->trans('Installed'), 'installed', $installed, null, $rows);
        if ([] === $installed) {
            $this->say($this->translator->trans('No plugin is installed'));
        }
    }

    /** A line saying why the shelf is empty. */
    public function say(string $text): void
    {
        $this->lines[] = ' '.StoreTile::text($this->styles->of(Role::Dim)->apply($text), $this->width - 1, $this->direction);
    }

    /** @return list<string> */
    public function lines(): array
    {
        return $this->lines;
    }

    /** @return list<StoreItem> */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * A heading, its "See all" when $more has more than it shows, and the
     * grid — each tile a store row or an installed plugin.
     *
     * @param list<StoreRow|InstalledPlugin> $tiles
     * @param list<StoreRow>                 $known the store rows an installed plugin is looked up in
     */
    private function shelf(string $heading, string $shelf, array $tiles, ?StoreCategory $more, array $known = []): void
    {
        $this->blank();
        $title = ' '.$this->styles->of(Role::Bright)->withBold()->apply($heading);
        if (null === $more) {
            $this->lines[] = StoreTile::text($title, $this->width, $this->direction);
        }
        if (null !== $more) {
            $link = $this->translator->trans('See all').' '.Glyphs::NAV_FORWARD;
            $linkWidth = AnsiUtils::visibleWidth($link);
            $key = 'see:'.$more->value;
            $style = $this->styles->of(Role::TabAccent);
            $link = ($key === $this->hovered ? $style->withUnderline() : $style)->apply($link);
            $col = \max(0, $this->width - $linkWidth - 1);
            $this->lines[] = AnsiText::truncate(StoreTile::text($title, $col, $this->direction).StoreTile::cell($this->shadeIf($link, $key), $this->direction), $this->width, pad: true);
            $this->items[] = new StoreItem($key, StoreItemKind::SeeAll, \count($this->lines) - 1, $col, 1, $linkWidth, category: $more);
        }
        $this->grid($tiles, $shelf, $known, \count($this->lines) - 1);
    }

    /**
     * The featured shelf, roomy: its grid inside a box with the heading in its
     * top border — the App Store's banner. Compact, a plain shelf.
     *
     * @param list<StoreRow> $tiles
     */
    private function banner(string $heading, array $tiles): void
    {
        if (FieldBox::ROWS !== $this->rows) {
            $this->shelf($heading, 'featured', $tiles, null);

            return;
        }
        $inner = new self(
            $this->translator,
            $this->styles,
            $this->width - 1 - FieldBox::CHROME_COLS,
            $this->rows,
            $this->shade,
            $this->selected,
            $this->hovered,
            $this->hoveredButton,
            $this->focused,
            \count($tiles),
            $this->art,
            $this->images,
        );
        $inner->grid($tiles, 'featured', [], -1);
        $this->blank();
        $top = \count($this->lines);
        foreach (FieldBox::frame(new FieldSpec($heading), $inner->lines, FieldState::Idle, $this->width - 1, $this->styles) as $line) {
            $this->lines[] = StoreTile::cell(AnsiText::truncate(' '.$line, $this->width, pad: true), $this->direction);
        }
        // Inside the border and its air: a row down, three columns across.
        foreach ($inner->items as $item) {
            $this->items[] = $item->moved($top + 1, 1 + (FieldBox::CHROME_COLS / 2), $top);
        }
    }

    /**
     * The tiles, as many to a row as the pane has room for, a rule between rows.
     *
     * @param list<StoreRow|InstalledPlugin> $tiles
     * @param list<StoreRow>                 $known
     * @param int                            $heading the line the shelf's heading is on, brought into view with its first row
     */
    private function grid(array $tiles, string $shelf, array $known, int $heading): void
    {
        $height = StoreTile::height($this->rows);
        foreach (\array_chunk($tiles, $this->columns) as $index => $chunk) {
            if ($index > 0 && FieldBox::ROWS === $this->rows) {
                $this->lines[] = $this->rules(\count($chunk));
            }
            $hero = 'featured' === $shelf && FieldBox::ROWS === $this->rows ? $this->heroes($chunk) : -1;
            // The row's pictures come into view with it; else the first row brings its heading.
            $reveal = match (true) {
                $hero >= 0 => $hero,
                0 === $index => $heading,
                default => -1,
            };
            $top = \count($this->lines);
            $band = \array_fill(start_index: 0, count: $height, value: ' ');
            foreach ($chunk as $column => $tile) {
                $col = 1 + ($column * ($this->tileWidth + self::GAP));
                $item = $tile instanceof StoreRow ? $this->storeItem($tile, $shelf) : $this->installedItem($tile, $known);
                $drawn = $this->tile->render($item['card'], $this->tileWidth, $this->rows, $item['key'] === $this->hovered && $this->hoveredButton);
                $button = $drawn['button'];
                $this->items[] = new StoreItem(
                    $item['key'],
                    StoreItemKind::Tile,
                    $top,
                    $col,
                    $height,
                    $this->tileWidth,
                    store: $item['store'],
                    plugin: $item['plugin'],
                    button: [$top + $button[0], $col + $button[1], $button[2], $button[3]],
                    reveal: $reveal,
                );
                foreach ($drawn['lines'] as $i => $line) {
                    $band[$i] = AnsiText::padRight($band[$i], $col).$this->shadeIf($line, $item['key']);
                }
            }
            foreach ($band as $line) {
                $this->lines[] = AnsiText::truncate($line, $this->width, pad: true);
            }
        }
    }

    /**
     * The pictures of a row of featured plugins, over their tiles, as tall as
     * the tallest needs up to {@see HERO_ROWS}; returns the row they start
     * on, or -1 when none of them has one.
     *
     * @param list<StoreRow|InstalledPlugin> $chunk
     */
    private function heroes(array $chunk): int
    {
        $pictures = [];
        $rows = 0;
        foreach ($chunk as $column => $tile) {
            $png = $tile instanceof StoreRow ? $this->art[$tile->entry->package]->hero ?? null : null;
            $fits = null === $png ? null : $this->images?->fits($png, $this->tileWidth - 1, self::HERO_ROWS);
            // Its size is in its header; whether it can be drawn here is the drawing.
            if (null === $png || null === $fits || null === $this->images?->boxed($png, $this->tileWidth - 1, $fits[1])) {
                continue;
            }
            $pictures[$column] = $png;
            $rows = \max($rows, $fits[1]);
        }
        if ([] === $pictures) {
            return -1;
        }
        $top = \count($this->lines);
        $band = \array_fill(start_index: 0, count: $rows, value: ' ');
        foreach ($pictures as $column => $png) {
            $col = 1 + ($column * ($this->tileWidth + self::GAP));
            foreach ($this->images?->boxed($png, $this->tileWidth - 1, $rows) ?? [] as $i => $line) {
                $band[$i] = AnsiText::padRight($band[$i], $col).StoreTile::cell($line, $this->direction);
            }
        }
        foreach ($band as $line) {
            $this->lines[] = AnsiText::truncate($line, $this->width, pad: true);
        }

        return $top;
    }

    /**
     * A store plugin's picture, drawn into the tile's icon box; null when it
     * has none to draw.
     *
     * @return list<string>|null
     */
    private function icon(?string $package): ?array
    {
        $png = null === $package ? null : $this->art[$package]->icon ?? null;
        [$columns, $rows] = FieldBox::ROWS === $this->rows ? StoreTile::ICON : StoreTile::COMPACT_ICON;

        return null === $png ? null : $this->images?->boxed($png, $columns, $rows);
    }

    /**
     * A store plugin's tile.
     *
     * @return array{key: string, card: StoreCard, store: StoreRow, plugin: null}
     */
    private function storeItem(StoreRow $row, string $shelf): array
    {
        $entry = $row->entry;
        $badges = [];
        if ($entry->verified) {
            $badges[] = Glyphs::MD_CHECK.' '.$this->translator->trans('Verified');
        }
        if ($entry->featured) {
            $badges[] = $this->translator->trans('Featured');
        }
        $card = new StoreCard(
            $entry->title,
            [] === $badges ? '' : $this->styles->of(Role::TabAccent)->apply(\implode('  ', $badges)),
            '' === $entry->description ? $this->translator->trans($entry->category->label()) : $entry->description,
            $this->facts($row),
            $this->translator->trans($row->action->caption()),
            $row->action->pressable(),
            $this->icon($entry->package),
        );

        return ['key' => 'tile:'.$shelf.':'.$entry->package, 'card' => $card, 'store' => $row, 'plugin' => null];
    }

    /**
     * An installed plugin's tile: Update when the store has a newer release of
     * it, Open — its Settings page — otherwise.
     *
     * @param list<StoreRow> $known
     *
     * @return array{key: string, card: StoreCard, store: ?StoreRow, plugin: string}
     */
    private function installedItem(InstalledPlugin $plugin, array $known): array
    {
        $row = self::installedRow($plugin->name, $known);
        $update = null !== $row && StoreAction::Update === $row->action;
        $facts = \implode(' · ', \array_filter([$plugin->facts, null === $row?->update ? '' : $row->update->from.' → '.$row->update->to]));
        $card = new StoreCard(
            $row?->entry->title ?? $plugin->name,
            $row?->entry->verified ?? false ? $this->styles->of(Role::TabAccent)->apply(Glyphs::MD_CHECK.' '.$this->translator->trans('Verified')) : '',
            '' === $plugin->description ? $row?->entry->description ?? '' : $plugin->description,
            $facts,
            $this->translator->trans($update ? 'Update' : 'Open'),
            true,
            $this->icon($row?->entry->package),
        );

        return ['key' => 'installed:'.$plugin->name, 'card' => $card, 'store' => $row, 'plugin' => $plugin->name];
    }

    /**
     * The store row that describes the installed copy of $name: one whose
     * button is about that copy — never a takeover offered beside it, whose
     * button is Get.
     *
     * @param list<StoreRow> $rows
     */
    public static function installedRow(string $name, array $rows): ?StoreRow
    {
        return \array_find(
            $rows,
            static fn (StoreRow $row): bool => (
                $name === $row->entry->name
                && \in_array($row->action, [StoreAction::Open, StoreAction::Update, StoreAction::BuiltIn, StoreAction::Enable, StoreAction::Working], strict: true)
            ),
        );
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

        return \implode(' · ', \array_filter($facts));
    }

    /** The rule under each tile of a grid row, one per column drawn. */
    private function rules(int $count): string
    {
        $line = ' ';
        for ($column = 0; $column < $count; ++$column) {
            $line = AnsiText::padRight($line, 1 + ($column * ($this->tileWidth + self::GAP))).Rule::horizontal($this->tileWidth - 1, $this->styles);
        }

        return AnsiText::truncate($line, $this->width, pad: true);
    }

    private function shadeIf(string $text, string $key): string
    {
        return ($this->shade)($text, $key);
    }

    /** @param list<string> $lines the item's lines, shaded when it is the selected or hovered one */
    private function add(StoreItem $item, array $lines): void
    {
        $this->items[] = $item;
        foreach ($lines as $line) {
            $this->lines[] = $this->shadeIf($line, $item->key);
        }
    }

    private function blank(): void
    {
        $this->lines[] = AnsiText::blank($this->width);
    }
}
