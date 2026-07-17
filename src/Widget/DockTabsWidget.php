<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsWidget;
use Porta\Support\AnsiText;
use Porta\Ui\DockTab;
use Porta\Ui\LtrPane;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Render\TabStripRenderer;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The bottom dock's tab strip — the single level of bottom-pane tabs: one
 * Toolbar-styled row naming every alive dock tab (one per terminal session,
 * one per list tool, Debug), rendered just above whichever panel owns the
 * dock slot. Content comes from a provider on {@see \Porta\Ui\BottomDock} so
 * it always reflects the models' state; an empty provider result (dock
 * hidden, or a transient picker bypassing the dock) renders zero rows.
 * Clicking a tab reveals its panel, the × closes it (a session tab stops its
 * process); dragging reorders within a family; a press on the empty tail
 * falls through to the panel-resize grab.
 */
#[AsWidget('dock-tabs', flex: 0)]
final class DockTabsWidget extends AbstractWidget implements LtrPane, MenuOverlayHost
{
    use HoverTrait;
    use MenuOverlayHostTrait;

    /** @var (callable(): list<array{tab: DockTab, key: string, label: string, active: bool, status: string}>)|null */
    private $provider = null;

    private string $lastFingerprint = '';

    /** @var list<array{tab: DockTab, key: string, index: int, start: int, width: int, closeStart: int, closeWidth: int}> the last strip's hit map */
    private array $lastTabLayout = [];

    /** @var (callable(DockTab, string): void)|null */
    private $onTabSelected = null;

    /** @var (callable(DockTab, string): void)|null */
    private $onTabClosed = null;

    /** @var (callable(DockTab, int, int): void)|null drag-reorder within a family: family, from, to */
    private $onTabMoved = null;

    private readonly Styles $styles;

    public function __construct(?Styles $styles = null)
    {
        $this->styles = $styles ?? Styles::dark();
    }

    /** @param callable(): list<array{tab: DockTab, key: string, label: string, active: bool, status: string}> $provider */
    public function setProvider(callable $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function onTabSelected(callable $callback): self
    {
        $this->onTabSelected = $callback;

        return $this;
    }

    public function onTabClosed(callable $callback): self
    {
        $this->onTabClosed = $callback;

        return $this;
    }

    public function onTabMoved(callable $callback): self
    {
        $this->onTabMoved = $callback;

        return $this;
    }

    /** A drag over the strip crossed same-family tabs: ask the dock to reorder that model. */
    public function moveTab(DockTab $family, int $from, int $to): void
    {
        if (null !== $this->onTabMoved) {
            ($this->onTabMoved)($family, $from, $to);
        }
    }

    /** Repaint whenever the alive tabs / labels / active tool change. */
    public function beforeRender(): void
    {
        if (null === $this->provider) {
            return;
        }
        $tabs = array_map(static fn (array $tab): array => [$tab['tab']->name, $tab['key'], $tab['label'], $tab['active'], $tab['status']], ($this->provider)());
        $fingerprint = (string) json_encode($tabs);
        if ($fingerprint !== $this->lastFingerprint) {
            $this->lastFingerprint = $fingerprint;
            $this->invalidate();
        }
    }

    /**
     * The family + family-relative index (counting only same-DockTab entries)
     * whose tab body covers $col, or null on the empty tail. Drags reorder
     * within one family — a cross-family or tail endpoint is ignored.
     *
     * @return array{tab: DockTab, index: int}|null
     */
    public function familyIndexAt(int $col): ?array
    {
        $counts = [];
        foreach ($this->lastTabLayout as $entry) {
            $index = $counts[$entry['tab']->name] ?? 0;
            if ($col >= $entry['start'] && $col < ($entry['start'] + $entry['width'])) {
                return ['tab' => $entry['tab'], 'index' => $index];
            }
            $counts[$entry['tab']->name] = $index + 1;
        }

        return null;
    }

    /**
     * A drag SOURCE: the family position at $col excluding its close ×, or null.
     *
     * @return array{tab: DockTab, index: int}|null
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
     * @return array{tab: DockTab, key: string}|null
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
     * Tint the tool tab under the pointer; returns whether it changed. The
     * strip's hover key is a column, not a row, so it maps the pointer itself
     * and only the state comes from {@see HoverTrait}.
     */
    public function hoverTabAt(int $col): bool
    {
        $hovered = null;
        foreach ($this->lastTabLayout as $entry) {
            if ($col < $entry['start'] || $col >= ($entry['start'] + $entry['width'])) {
                continue;
            }

            $hovered = $entry['index'];
            break;
        }

        return $this->setHovered($hovered);
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

            if (null !== $this->onTabClosed) {
                ($this->onTabClosed)($entry['tab'], $entry['key']);
            }

            return true;
        }
        foreach ($this->lastTabLayout as $entry) {
            if (!($col >= $entry['start'] && $col < ($entry['start'] + $entry['width']))) {
                continue;
            }

            if (null !== $this->onTabSelected) {
                ($this->onTabSelected)($entry['tab'], $entry['key']);
            }

            return true;
        }

        return false;
    }

    /** @return list<string> */
    public function render(RenderContext $context): array
    {
        $tabs = null !== $this->provider ? ($this->provider)() : [];
        $this->lastTabLayout = [];
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
        $hovered = $this->hoveredIndex;
        $strip = TabStripRenderer::render(
            array_map(static fn (array $tab, int $i): array => ['label' => $tab['label'], 'active' => $tab['active'], 'hovered' => $i === $hovered && !$tab['active']], $tabs, array_keys($tabs)),
            $columns,
            $this->styles,
        );
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

        // The active tool's status sits right-aligned in whatever room the tabs leave.
        if ('' !== $status) {
            $room = $columns - $used - 2;
            $text = AnsiUtils::truncateToWidth($status, max(0, $room), '…', pad: false);
            $pad = max(0, $columns - $used - mb_strwidth($text) - 1);
            $line .= str_repeat(' ', $pad).$this->styles->merge(Role::Toolbar, Role::Dim)->apply($text);
        }

        // A Toolbar-background base under the whole row, then the styled tabs.
        return $this->withMenuOverlay([$this->styles->of(Role::Toolbar)->apply(AnsiText::padRight($line, $columns))], $columns);
    }
}
