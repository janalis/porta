<?php

declare(strict_types=1);

namespace Porta\Widget\Git;

use Porta\Container\AsWidget;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\PanelWidth;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Render\TabState;
use Porta\Widget\Render\TabStyle;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The [Changes][History] tab strip heading the git sidebar. Not focusable —
 * the '1'/'2' keys in the lists switch tabs too; this is the mouse target.
 * The active tab is set by the controller, and a click on the inactive one
 * fires onSelect with its mode.
 */
#[AsWidget('git-tabs', flex: 0)]
final class GitTabsWidget extends AbstractWidget implements MenuOverlayHost
{
    use MenuOverlayHostTrait;
    use PanelWidth;

    private const array TABS = ['changes' => ' Changes ', 'history' => ' History '];

    private string $active = 'changes';

    /** The tab under the mouse pointer (visual only), or null. */
    private ?string $hoveredMode = null;
    /** @var list<array{mode: string, start: int, width: int}> tab layout from the last render, for hit-testing */
    private array $layout = [];

    /** @var (callable(string): void)|null a click chose the other tab ('changes'|'history') */
    private $onSelect = null;

    private readonly Styles $styles;

    public function __construct(
        private readonly Translator $translator,
        int $width = 44,
        ?Styles $styles = null,
    ) {
        $this->width = $width;
        $this->styles = $styles ?? Styles::dark();
    }

    protected function minWidth(): int
    {
        return 24;
    }

    protected function maxWidth(): int
    {
        return 200;
    }

    /** @param callable(string): void $callback */
    public function onSelect(callable $callback): self
    {
        $this->onSelect = $callback;

        return $this;
    }

    public function setActive(string $mode): void
    {
        if ($mode !== $this->active) {
            $this->active = $mode;
            $this->invalidate();
        }
    }

    /** The tab under a widget-relative column, from the last render's layout. */
    /** Tint the (inactive) tab under the pointer; null clears. Returns whether it changed. */
    public function hoverMode(?string $mode): bool
    {
        if ($mode === $this->hoveredMode) {
            return false;
        }
        $this->hoveredMode = $mode;
        $this->invalidate();

        return true;
    }

    public function modeAtColumn(int $relCol): ?string
    {
        foreach ($this->layout as $tab) {
            if ($relCol >= $tab['start'] && $relCol < ($tab['start'] + $tab['width'])) {
                return $tab['mode'];
            }
        }

        return null;
    }

    /** A click on the inactive tab switches to it; anywhere else is a no-op. */
    public function handleClick(int $relCol): void
    {
        $mode = $this->modeAtColumn($relCol);
        if (null !== $mode && $mode !== $this->active && null !== $this->onSelect) {
            ($this->onSelect)($mode);
        }
    }

    public function render(RenderContext $context): array
    {
        $width = min($this->width, $context->getColumns());

        $this->layout = [];
        $line = ' ';
        $col = 1;
        foreach (self::TABS as $mode => $rawLabel) {
            $label = $this->translator->trans($rawLabel);
            $tabWidth = mb_strwidth($label);
            $this->layout[] = ['mode' => $mode, 'start' => $col, 'width' => $tabWidth];
            $line .= TabStyle::of($this->styles, TabState::of($mode === $this->active, $mode === $this->hoveredMode))->apply($label);
            $col += $tabWidth + 1;
            $line .= ' ';
        }

        return $this->withMenuOverlay([$this->styles->of(Role::Toolbar)->apply(AnsiText::truncate($line, $width, pad: true))], $width);
    }
}
