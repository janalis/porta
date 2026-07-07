<?php

declare(strict_types=1);

namespace Porta\Widget\Git;

use Porta\Ui\Style;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The [Changes][History] tab strip heading the git sidebar. Not focusable —
 * the '1'/'2' keys in the lists switch tabs too; this is the mouse target.
 * The active tab is set by the controller, and a click on the inactive one
 * fires onSelect with its mode.
 */
final class GitTabsWidget extends AbstractWidget implements MenuOverlayHost
{
    use MenuOverlayHostTrait;

    private const array TABS = ['changes' => ' Changes ', 'history' => ' History '];

    private string $active = 'changes';
    /** @var list<array{mode: string, start: int, width: int}> tab layout from the last render, for hit-testing */
    private array $layout = [];

    /** @var (callable(string): void)|null a click chose the other tab ('changes'|'history') */
    private $onSelect = null;

    public function __construct(
        private readonly int $width = 44,
    ) {}

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
    public function modeAtColumn(int $relCol): ?string
    {
        foreach ($this->layout as $tab) {
            if ($relCol >= $tab['start'] && $relCol < $tab['start'] + $tab['width']) {
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
        foreach (self::TABS as $mode => $label) {
            $tabWidth = mb_strwidth($label);
            $this->layout[] = ['mode' => $mode, 'start' => $col, 'width' => $tabWidth];
            $line .= ($mode === $this->active ? Style::SELECTED_ROW."\x1b[1m" : "\x1b[48;5;237m\x1b[38;5;250m").$label."\x1b[0m\x1b[48;5;235m";
            $col += $tabWidth + 1;
            $line .= ' ';
        }

        return $this->withMenuOverlay(["\x1b[48;5;235m".AnsiUtils::truncateToWidth($line, $width, '…', pad: true)."\x1b[0m"], $width);
    }
}
