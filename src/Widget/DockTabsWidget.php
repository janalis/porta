<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsWidget;
use Porta\Support\AnsiText;
use Porta\Ui\DockTab;
use Porta\Ui\LtrPane;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\TabCloseGlyph;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The bottom dock's tab strip: one Toolbar-styled row naming the alive dock
 * tools (Terminal / the results panel's current tool / Debug), rendered just
 * above whichever panel owns the dock slot. Content comes from a provider on
 * {@see \Porta\Ui\BottomDock} so it always reflects the panels' state; an
 * empty provider result (dock hidden, or a transient picker bypassing the
 * dock) renders zero rows. Clicking a tab reveals its panel, the × closes
 * the tool; a press on the empty tail falls through to the panel-resize grab.
 */
#[AsWidget('dock-tabs', flex: 0)]
final class DockTabsWidget extends AbstractWidget implements LtrPane
{
    /** @var (callable(): list<array{tab: DockTab, key: string, label: string, active: bool, status: string}>)|null */
    private $provider = null;

    private string $lastFingerprint = '';

    /** @var list<array{tab: DockTab, key: string, start: int, width: int, closeStart: int, closeWidth: int}> the last strip's hit map */
    private array $lastTabLayout = [];

    /** @var (callable(DockTab, string): void)|null */
    private $onTabSelected = null;

    /** @var (callable(DockTab, string): void)|null */
    private $onTabClosed = null;

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

        /** @var list<array{tab: array{tab: DockTab, key: string, label: string, active: bool, status: string}, text: string, width: int, closeOffset: int}> $parts */
        $parts = [];
        $status = '';
        $activeIndex = 0;
        foreach ($tabs as $i => $tab) {
            if ($tab['active']) {
                $status = $tab['status'];
                $activeIndex = $i;
            }
            $prefix = ' '.$tab['label'].' ';
            $text = $prefix.'× ';
            $parts[] = ['tab' => $tab, 'text' => $text, 'width' => mb_strwidth($text) + 1, 'closeOffset' => mb_strwidth($prefix)];
        }
        // Drop leading tabs until the active one fits (the terminal session
        // strip's overflow rule) — an unbounded tab pile must never hide the
        // tab you are looking at.
        $start = 0;
        while ($start < $activeIndex && array_sum(array_column(\array_slice($parts, $start, $activeIndex - $start + 1), 'width')) > $columns) {
            ++$start;
        }

        $line = '';
        $used = 0;
        foreach (\array_slice($parts, $start) as $part) {
            if (($used + $part['width']) > $columns) {
                break;
            }
            $tab = $part['tab'];
            $style = $tab['active'] ? $this->styles->merge(Role::Toolbar, Role::Bright)->withBold() : $this->styles->merge(Role::Toolbar, Role::Subtle);
            $close = $tab['active'] ? TabCloseGlyph::active($this->styles) : TabCloseGlyph::inactive($this->styles);
            $line .= $style->apply(str_replace('×', $close, $part['text'])).$this->styles->of(Role::Placeholder)->apply('│');
            $this->lastTabLayout[] = ['tab' => $tab['tab'], 'key' => $tab['key'], 'start' => $used, 'width' => $part['width'], 'closeStart' => $used + $part['closeOffset'], 'closeWidth' => 2];
            $used += $part['width'];
        }

        // The active tool's status sits right-aligned in whatever room the tabs leave.
        if ('' !== $status) {
            $room = $columns - $used - 2;
            $text = AnsiUtils::truncateToWidth($status, max(0, $room), '…', pad: false);
            $pad = max(0, $columns - $used - mb_strwidth($text) - 1);
            $line .= str_repeat(' ', $pad).$this->styles->merge(Role::Toolbar, Role::Dim)->apply($text);
        }

        // A Toolbar-background base under the whole row, then the styled tabs.
        return [$this->styles->of(Role::Toolbar)->apply(AnsiText::padRight($line, $columns))];
    }
}
