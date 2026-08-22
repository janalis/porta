<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsChromeRoot;
use Porta\Container\AsIdeService;
use Porta\Container\AsWidget;
use Porta\Support\AnsiText;
use Porta\Support\Clocks;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Support\StylesDefault;
use Porta\Workbench\Menu\MenuItem;
use Porta\Workbench\StatusMenuSpan;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Single-line status bar. Content is pulled from a provider closure on each
 * frame, so it always reflects the current editor/LSP state.
 */
#[AsChromeRoot]
#[AsIdeService]
#[AsWidget('status')]
final class StatusBarWidget extends AbstractWidget
{
    use StylesDefault;

    /** @var (callable(): array{left: string, right: string, menus?: list<StatusMenuSpan>})|null */
    private $provider = null;

    private string $lastLeft = '';
    private string $lastRight = '';
    /**
     * Clickable cells, as the provider reports them.
     *
     * @var list<StatusMenuSpan>
     */
    private array $cellMenus = [];

    /**
     * Their on-screen spans from the last render.
     *
     * @var list<StatusMenuSpan>
     */
    private array $renderedMenus = [];

    /** @var array{text: string, until: float}|null */
    private ?array $flash = null;

    /** The clock is injected by the container; a MockClock makes flash expiry testable. */
    public function __construct(
        private readonly ClockInterface $clock = new NativeClock(),
        ?Styles $styles = null,
    ) {
        $this->initStyles($styles);
    }

    /**
     * @param callable(): array{left: string, right: string, menus?: list<StatusMenuSpan>} $provider
     */
    public function setProvider(callable $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    /**
     * The git-branch cell's on-screen column span [start, end) from the last
     * render, or null when there is no branch (or it was clipped / a flash hid
     * it). The router uses this to route a right-click on the branch.
     *
     * @return array{int, int}|null
     */
    /**
     * The menu a status cell at column $col offers, or null when nothing
     * clickable is there.
     *
     * Replaces branchColumnSpan(): the router used to read one hardcoded span
     * and pair it with one hardcoded menu, which is how the status bar came to
     * know what a git branch was.
     *
     * @return (\Closure(): list<MenuItem>)|null
     */
    public function menuAt(int $col): ?\Closure
    {
        foreach ($this->renderedMenus as $span) {
            if ($span->contains($col)) {
                return $span->items;
            }
        }

        return null;
    }

    /**
     * Show a transient message (e.g. "Saved") for a few seconds.
     */
    public function flash(string $text, float $seconds = 2.5): void
    {
        $this->flash = ['text' => $text, 'until' => Clocks::seconds($this->clock) + $seconds];
        $this->invalidate();
    }

    #[\Override]
    public function beforeRender(): void
    {
        $now = Clocks::seconds($this->clock);
        if (null !== $this->flash && $now > $this->flash['until']) {
            $this->flash = null;
            $this->invalidate();
        }

        if (null === $this->provider) {
            return;
        }

        $segments = ($this->provider)();
        $left = $segments['left'];
        $right = $segments['right'];
        $this->cellMenus = $segments['menus'] ?? [];
        if ($left !== $this->lastLeft || $right !== $this->lastRight) {
            $this->lastLeft = $left;
            $this->lastRight = $right;
            $this->invalidate();
        }
    }

    #[\Override]
    public function render(RenderContext $context): array
    {
        $columns = $context->getColumns();
        $left = null !== $this->flash ? $this->flash['text'] : $this->lastLeft;
        $right = $this->lastRight;

        // A flash is transient information the user must read, so it wins the
        // width fight: the right side (whose tail is the static key legend)
        // yields to it. The steady-state left (the file path) yields as before.
        if (null !== $this->flash) {
            $rightMax = max(0, $columns - min(AnsiUtils::visibleWidth($left), $columns - 3) - 3);
            $right = AnsiText::truncate($right, $rightMax);
        }

        $rightWidth = AnsiUtils::visibleWidth($right);
        $leftMax = max(0, $columns - $rightWidth - 3);
        $left = AnsiText::truncate($left, $leftMax);
        $leftWidth = AnsiUtils::visibleWidth($left);

        // Cells are offset by the one leading space in the row. Only offer a
        // hit region when the whole label is on screen — not while a flash
        // replaced `left`, nor when truncation clipped it.
        $this->renderedMenus = null !== $this->flash
            ? []
            : array_values(array_map(
                static fn (StatusMenuSpan $span): StatusMenuSpan => $span->shifted(1),
                array_filter($this->cellMenus, static fn (StatusMenuSpan $span): bool => $span->to <= $leftWidth),
            ));

        $gap = max(1, $columns - $leftWidth - $rightWidth - 2);
        $line = ' '.$left.str_repeat(' ', $gap).$right.' ';
        // '' ellipsis: chrome already gap-padded to the width — never mark it.
        $line = AnsiText::truncate($line, $columns, pad: true, ellipsis: '');

        return [$this->styles->merge(Role::HeaderBar, Role::CurrentLineNumber)->apply($line)];
    }
}
