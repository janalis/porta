<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsChromeRoot;
use Porta\Container\AsIdeService;
use Porta\Container\AsWidget;
use Porta\Event\DockTabActivationRequested;
use Porta\Event\DockTabCloseRequested;
use Porta\Event\DockTabMoveRequested;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\LtrPane;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Tooltip;
use Porta\Ui\TooltipProvider;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Render\Rule;
use Porta\Widget\Render\TabStripRenderer;
use Porta\Widget\Support\ColumnHover;
use Porta\Widget\Support\PaneFocusProbe;
use Porta\Widget\Support\ProviderFingerprint;
use Porta\Widget\Support\StripScroll;
use Porta\Widget\Support\StylesDefault;
use Porta\Workbench\Dock\DockTab;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The bottom dock's tab strip — the single level of bottom-pane tabs: one
 * Toolbar-styled row naming every alive dock tab (one per terminal session,
 * one per list tool, Debug), rendered just above whichever panel owns the
 * dock slot. Content comes from a provider on {@see \Porta\Workbench\Dock\BottomDock} so
 * it always reflects the models' state; an empty provider result (dock
 * hidden, or a transient picker bypassing the dock) renders zero rows.
 * Clicking a tab reveals its panel, the ✖ closes it (a session tab stops its
 * process); dragging reorders within a family; a press on the empty tail
 * falls through to the panel-resize grab.
 */
#[AsChromeRoot]
#[AsIdeService]
#[AsWidget('dock-tabs', flex: 0)]
final class DockTabsWidget extends AbstractWidget implements LtrPane, MenuOverlayHost, TooltipProvider
{
    use ColumnHover;
    use MenuOverlayHostTrait;
    use PaneFocusProbe;

    /** @use ProviderFingerprint<list<array{tab: ?DockTab, key: string, label: string, active: bool, status: string}>> */
    use ProviderFingerprint;
    use StripScroll;
    use StylesDefault;

    /**
     * The pointer is on the strip's resize tail (the row doubles as the dock's
     * height grab). Heavy-rules the empty tail so the drag-to-resize reads —
     * the {@see SeparatorWidget} affordance for the sidebar boundary, here for
     * the horizontal one. Hover-only: the strip is already a visible boundary,
     * so at rest the tail stays plain.
     */
    private bool $grabHovered = false;

    /** @var list<array{tab: ?DockTab, key: string, index: int, start: int, width: int, closeStart: int, closeWidth: int}> the last strip's hit map */
    private array $lastTabLayout = [];

    /** The active index the strip window last followed, so a manual scroll survives parked frames. */
    private ?int $lastActive = null;

    /** @var positive-int one row, or {@see TabStripRenderer::EDGED_ROWS} on a roomy screen */
    private int $stripRows = 1;

    /** The panel focus the last render drew, so a focus change repaints the lid. */
    private bool $drawnFocus = false;

    /**
     * What the strip's cells say under the pointer, from the last render: a
     * stopped session's ○, a status cut to fit, the resize grab.
     *
     * @var list<array{row: int, rows: int, from: int, to: int, key: string, text: string}>
     */
    private array $tips = [];

    public function __construct(
        ?Styles $styles = null,
        private readonly Translator $translator = new Translator('en'),
        private readonly ?EventDispatcherInterface $events = null,
    ) {
        $this->initStyles($styles);
    }

    /**
     * A stopped session's ○ says what it means; a status cut to fit says
     * itself whole; the empty tail says it is the panel's resize grab.
     */
    #[\Override]
    public function tooltipAt(int $row, int $col): ?Tooltip
    {
        $tip = \array_find($this->tips, static fn (array $tip): bool => $row >= $tip['row'] && $row < ($tip['row'] + $tip['rows']) && $col >= $tip['from'] && $col < $tip['to']);

        return null === $tip ? null : new Tooltip('dock:'.$tip['key'], $tip['text'], $tip['row'], $tip['from'], $tip['to'], $tip['rows']);
    }

    /** A drag over the strip crossed same-family tabs: ask the dock to reorder that model. */
    public function moveTab(?DockTab $family, int $from, int $to): void
    {
        $this->events?->dispatch(new DockTabMoveRequested($family, $from, $to));
    }

    /** Repaint whenever the alive tabs / labels / active tool change. */
    #[\Override]
    public function beforeRender(): void
    {
        if (null === $this->provider) {
            return;
        }
        $tabs = \array_map(static fn (array $tab): array => [self::family($tab['tab'], $tab['key']), $tab['key'], $tab['label'], $tab['active'], $tab['status']], ($this->provider)());
        $this->refreshFingerprint($tabs);
        if ($this->paneFocused() !== $this->drawnFocus) {
            $this->invalidate(); // the active lid's weight follows the panel's focus
        }
    }

    /** @param positive-int $rows the strip's height, from the terminal's ({@see TabStripRenderer::edgedRowsFor()}) */
    public function setStripRows(int $rows): void
    {
        if ($rows !== $this->stripRows) {
            $this->stripRows = $rows;
            $this->invalidate();
        }
    }

    /** @return positive-int */
    public function stripRows(): int
    {
        return $this->stripRows;
    }

    /**
     * A strip entry's drag family. A contributed panel has a null DockTab and
     * is its OWN family, keyed by its id — so a drag can never carry it into
     * the session or tool families, which is the same thing the null buys in
     * the registry.
     *
     */
    private static function family(?DockTab $tab, string $key): string
    {
        return $tab->name ?? 'panel:'.$key;
    }

    /**
     * The family + family-relative index (counting only same-family entries)
     * whose tab body covers $col, or null on the empty tail. Drags reorder
     * within one family — a cross-family or tail endpoint is ignored.
     *
     * @return array{tab: ?DockTab, index: int}|null
     */
    public function familyIndexAt(int $col): ?array
    {
        $counts = [];
        foreach ($this->lastTabLayout as $entry) {
            $index = $counts[self::family($entry['tab'], $entry['key'])] ?? 0;
            if ($col >= $entry['start'] && $col < ($entry['start'] + $entry['width'])) {
                return ['tab' => $entry['tab'], 'index' => $index];
            }
            $counts[self::family($entry['tab'], $entry['key'])] = $index + 1;
        }

        return null;
    }

    /**
     * A drag SOURCE: the family position at $col excluding its close ✖, or null.
     *
     * @return array{tab: ?DockTab, index: int}|null
     */
    public function dragSourceAt(int $col): ?array
    {
        foreach ($this->lastTabLayout as $entry) {
            if ($col >= $entry['closeStart'] && $col < ($entry['closeStart'] + $entry['closeWidth'])) {
                return null; // a close click must not arm a reorder
            }
        }

        return $this->familyIndexAt($col);
    }

    /**
     * The dock identity of the tab whose body covers $col, or null on the
     * empty tail — the right-click context-menu lookup.
     *
     * @return array{tab: ?DockTab, key: string}|null
     */
    public function tabAt(int $col): ?array
    {
        foreach ($this->lastTabLayout as $entry) {
            if ($col >= $entry['start'] && $col < ($entry['start'] + $entry['width'])) {
                return ['tab' => $entry['tab'], 'key' => $entry['key']];
            }
        }

        return null;
    }

    /** The strip column where the given tab's body starts, or null when it is not on the strip. */
    public function anchorFor(DockTab $tab, string $key): ?int
    {
        foreach ($this->lastTabLayout as $entry) {
            if ($entry['tab'] === $tab && $entry['key'] === $key) {
                return $entry['start'];
            }
        }

        return null;
    }

    /**
     * Tint the tool tab under the pointer; returns whether the hovered tab
     * changed (the router's repaint gate). The stored key is the column itself,
     * re-resolved at render time; the resolve here, against the last frame's
     * layout, only detects the change so a move within one tab does not repaint.
     */
    public function hoverTabAt(int $col): bool
    {
        return $this->storeHover($col, $this->indexAt($col));
    }

    /** The absolute tab index whose body covers $col in the last rendered strip, or null. */
    private function indexAt(int $col): ?int
    {
        foreach ($this->lastTabLayout as $entry) {
            if ($col >= $entry['start'] && $col < ($entry['start'] + $entry['width'])) {
                return $entry['index'];
            }
        }

        return null;
    }

    /** Whether $col lands on a tab body (vs the empty resize tail past the last tab). */
    public function isOnTab(int $col): bool
    {
        return null !== $this->indexAt($col);
    }

    /**
     * The pointer is on the strip's resize tail: heavy-rule it. Returns whether
     * it changed — the router's repaint gate, folded in with the tab tint. The
     * affordance is the glyph, not a shade, so it survives
     * {@see \Porta\Ui\Theme\MonochromeTheme} like the pane dividers. Paired with
     * {@see clearGrab()}, the same shape as {@see SeparatorWidget::hover()}.
     */
    public function hoverGrab(): bool
    {
        if ($this->grabHovered) {
            return false;
        }
        $this->grabHovered = true;
        $this->invalidate();

        return true;
    }

    /** The pointer left the resize tail; returns whether it changed. */
    public function clearGrab(): bool
    {
        if (!$this->grabHovered) {
            return false;
        }
        $this->grabHovered = false;
        $this->invalidate();

        return true;
    }

    /**
     * A click on the strip (widget-relative column): the close ✖ zone closes
     * that tool, elsewhere on a tab reveals it. Returns whether the column
     * landed on a tab — the caller lets a miss (the empty tail) fall through
     * to the panel-resize grab.
     */
    public function handleTabClick(int $col): bool
    {
        $close = \array_find(
            $this->lastTabLayout,
            static fn (array $entry): bool => $col >= $entry['closeStart'] && $col < ($entry['closeStart'] + $entry['closeWidth']),
        );
        $tab = null === $close
            ? \array_find(
                $this->lastTabLayout,
                static fn (array $entry): bool => $col >= $entry['start'] && $col < ($entry['start'] + $entry['width']),
            )
            : null;
        if (null !== $close) {
            $this->events?->dispatch(new DockTabCloseRequested($close['tab'], $close['key']));
        }
        if (null !== $tab) {
            $this->events?->dispatch(new DockTabActivationRequested($tab['tab'], $tab['key']));
        }

        return null !== $close || null !== $tab;
    }

    /** @return list<string> */
    #[\Override]
    public function render(RenderContext $context): array
    {
        $tabs = null !== $this->provider ? ($this->provider)() : [];
        $this->lastTabLayout = [];
        $this->indicatorZones = [];
        $this->tips = [];
        if ([] === $tabs) {
            return [];
        }

        $columns = $context->getColumns();

        $status = '';
        foreach ($tabs as $tab) {
            if (!$tab['active']) {
                continue;
            }

            $status = $tab['status'];
        }
        $strip = TabStripRenderer::render(
            \array_map(
                // A session tab and a tool tab are different families: a drag
                // reorders within one and ignores a cross-family endpoint, so
                // the strip draws that boundary heavier.
                static fn (array $tab): array => [
                    'label' => $tab['label'],
                    'active' => $tab['active'],
                    'family' => self::family($tab['tab'], $tab['key']),
                ],
                $tabs,
            ),
            $columns,
            $this->styles,
            $this->hoverCol,
            $this->scroll,
            $this->lastActive,
            $this->stripRows,
            paneFocused: $this->drawnFocus = $this->paneFocused(),
        );
        $this->scroll = $strip['scroll'];
        $this->lastActive = $strip['lastActive'];
        $this->indicatorZones = $strip['indicatorZones'];
        // The renderer's hit map is index-based; re-key it with each entry's dock identity.
        foreach ($strip['hitMap'] as $zone) {
            $tab = $tabs[$zone['index']];
            if (\str_ends_with($tab['label'], ' '.Glyphs::SESSION_STOPPED)) {
                $this->tips[] = [
                    'row' => 0,
                    'rows' => $this->stripRows,
                    'from' => $zone['start'],
                    'to' => $zone['start'] + $zone['width'],
                    'key' => 'stopped:'.$tab['key'],
                    'text' => $this->translator->trans('The process has exited'),
                ];
            }
            $this->lastTabLayout[] = [
                'tab' => $tab['tab'],
                'key' => $tab['key'],
                'index' => $zone['index'],
                'start' => $zone['start'],
                'width' => $zone['width'],
                'closeStart' => $zone['closeStart'],
                'closeWidth' => $zone['closeWidth'],
            ];
        }
        $used = $strip['used'];
        $roomy = $this->stripRows > 1;

        // The strip's top row doubles as the dock's height grab: under the
        // pointer its empty tail heavy-rules so the drag-to-resize reads. At
        // rest a roomy strip's grab is a rule, the dock's top edge; a compact
        // strip's is blank. Every fill is one column, so no width moves.
        $fill = match (true) {
            $this->grabHovered => Glyphs::PANEL_RESIZE_GRAB,
            $roomy => Glyphs::FRAME_HORIZONTAL,
            default => ' ',
        };
        $room = \max(0, $columns - $used);
        $grab = $this->styles->of($roomy && !$this->grabHovered ? Role::Rule : Role::Toolbar)->apply(\str_repeat($fill, $room));

        // The active tool's status sits right-aligned on the tail: on a roomy
        // strip, level with the labels — between the grab above it and the
        // baseline below, which the tabs stand on. A compact strip's one row
        // is its grab until a status takes it.
        $whole = $status;
        $status = '' === $status ? '' : $this->statusTail($status, $room, $roomy ? ' ' : $fill, $roomy ? $this->styles->of(Role::Dim) : $this->styles->merge(Role::Toolbar, Role::Dim));
        // The status is cut from its tail — the key reminders — first, so the
        // whole of it is the tooltip where it lies; then the grab, where the
        // top row's tail is still bare.
        if ('' !== $whole && !\str_contains(AnsiUtils::stripAnsiCodes($status), $whole)) {
            $this->tips[] = ['row' => $roomy ? 1 : 0, 'rows' => 1, 'from' => $used, 'to' => $columns, 'key' => 'status', 'text' => $whole];
        }
        $this->tips[] = ['row' => 0, 'rows' => 1, 'from' => $used, 'to' => $columns, 'key' => 'grab', 'text' => $this->translator->trans('Drag to resize the panel')];
        $base = $roomy ? new Style() : $this->styles->of(Role::Toolbar);
        $rows = [];
        foreach ($strip['lines'] as $row => $line) {
            $tail = match (true) {
                !$roomy => '' === $status ? $grab : $status,
                0 === $row => $grab,
                $row === $strip['footRow'] => Rule::horizontal($room, $this->styles),
                default => $status,
            };
            // Compact, a Toolbar-background base under the whole row; roomy,
            // the pills sit on the pane itself.
            $rows[] = $base->apply(AnsiText::padRight($line.$tail, $columns));
        }

        return $rows;
    }

    /** The status, right-aligned in $room columns over $fill, in $style. */
    private function statusTail(string $status, int $room, string $fill, Style $style): string
    {
        $text = AnsiText::truncate($status, \max(0, $room - 2), pad: false);
        $pad = \max(0, $room - AnsiUtils::visibleWidth($text) - 1);

        return \str_repeat($fill, $pad).$style->apply($text);
    }
}
