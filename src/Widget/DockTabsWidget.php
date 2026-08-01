<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsChromeRoot;
use Porta\Container\AsIdeService;
use Porta\Container\AsWidget;
use Porta\Event\DockTabActivationRequested;
use Porta\Event\DockTabCloseRequested;
use Porta\Event\DockTabMoveRequested;
use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\LtrPane;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Render\TabStripRenderer;
use Porta\Widget\Support\ColumnHover;
use Porta\Widget\Support\ProviderFingerprint;
use Porta\Widget\Support\StripScroll;
use Porta\Widget\Support\StylesDefault;
use Porta\Workbench\Dock\DockTab;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The bottom dock's tab strip — the single level of bottom-pane tabs: one
 * Toolbar-styled row naming every alive dock tab (one per terminal session,
 * one per list tool, Debug), rendered just above whichever panel owns the
 * dock slot. Content comes from a provider on {@see \Porta\Workbench\Dock\BottomDock} so
 * it always reflects the models' state; an empty provider result (dock
 * hidden, or a transient picker bypassing the dock) renders zero rows.
 * Clicking a tab reveals its panel, the × closes it (a session tab stops its
 * process); dragging reorders within a family; a press on the empty tail
 * falls through to the panel-resize grab.
 */
#[AsChromeRoot]
#[AsIdeService]
#[AsWidget('dock-tabs', flex: 0)]
final class DockTabsWidget extends AbstractWidget implements LtrPane, MenuOverlayHost
{
    use ColumnHover;
    use MenuOverlayHostTrait;

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

    public function __construct(
        ?Styles $styles = null,
        private readonly ?EventDispatcherInterface $events = null,
    ) {
        $this->initStyles($styles);
    }

    /** A drag over the strip crossed same-family tabs: ask the dock to reorder that model. */
    public function moveTab(?DockTab $family, int $from, int $to): void
    {
        $this->events?->dispatch(new DockTabMoveRequested($family, $from, $to));
    }

    /** Repaint whenever the alive tabs / labels / active tool change. */
    public function beforeRender(): void
    {
        if (null === $this->provider) {
            return;
        }
        $tabs = array_map(static fn (array $tab): array => [self::family($tab['tab'], $tab['key']), $tab['key'], $tab['label'], $tab['active'], $tab['status']], ($this->provider)());
        $this->refreshFingerprint($tabs);
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
     * A drag SOURCE: the family position at $col excluding its close ×, or null.
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
     * A click on the strip (widget-relative column): the close × zone closes
     * that tool, elsewhere on a tab reveals it. Returns whether the column
     * landed on a tab — the caller lets a miss (the empty tail) fall through
     * to the panel-resize grab.
     */
    public function handleTabClick(int $col): bool
    {
        foreach ($this->lastTabLayout as $entry) {
            if (!($col >= $entry['closeStart'] && $col < ($entry['closeStart'] + $entry['closeWidth']))) {
                continue;
            }

            $this->events?->dispatch(new DockTabCloseRequested($entry['tab'], $entry['key']));

            return true;
        }
        foreach ($this->lastTabLayout as $entry) {
            if (!($col >= $entry['start'] && $col < ($entry['start'] + $entry['width']))) {
                continue;
            }

            $this->events?->dispatch(new DockTabActivationRequested($entry['tab'], $entry['key']));

            return true;
        }

        return false;
    }

    /** @return list<string> */
    public function render(RenderContext $context): array
    {
        $tabs = null !== $this->provider ? ($this->provider)() : [];
        $this->lastTabLayout = [];
        $this->indicatorZones = [];
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
            array_map(
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
        );
        $this->scroll = $strip['scroll'];
        $this->lastActive = $strip['lastActive'];
        $this->indicatorZones = $strip['indicatorZones'];
        // The renderer's hit map is index-based; re-key it with each entry's dock identity.
        foreach ($strip['hitMap'] as $zone) {
            $tab = $tabs[$zone['index']];
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
        $line = $strip['line'];
        $used = $strip['used'];

        // The row doubles as the dock's height grab: under the pointer its empty
        // tail heavy-rules so the drag-to-resize reads, at rest it stays plain.
        // Both fills are one column, so no width moves.
        $fill = $this->grabHovered ? Glyphs::PANEL_RESIZE_GRAB : ' ';

        // The tail fills the room the tabs leave; the active tool's status, when
        // there is one, sits right-aligned on top of it.
        $tail = str_repeat($fill, max(0, $columns - $used));
        if ('' !== $status) {
            $room = $columns - $used - 2;
            $text = AnsiText::truncate($status, max(0, $room), pad: false);
            $pad = max(0, $columns - $used - mb_strwidth($text) - 1);
            $tail = str_repeat($fill, $pad).$this->styles->merge(Role::Toolbar, Role::Dim)->apply($text);
        }
        $line .= $tail;

        // A Toolbar-background base under the whole row, then the styled tabs.
        return $this->withMenuOverlay([$this->styles->of(Role::Toolbar)->apply(AnsiText::padRight($line, $columns))], $columns);
    }
}
