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
use Porta\Workbench\StatusClickSpan;
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

    /** @var (callable(): array{left: string, right: string, menus?: list<StatusMenuSpan>, rightClicks?: list<StatusClickSpan>})|null */
    private $provider = null;

    /**
     * The narrowest the left may be squeezed while it still has something to
     * say — enough for an elided path to keep a directory AND the filename
     * (`src/Some/De…pleClass.php` is 24). Below this the row is telling the
     * user which keys exist instead of which file they are in.
     */
    private const int LEFT_FLOOR = 24;

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

    /**
     * Left-clickable right-hand cells, relative to the RIGHT segment as the
     * provider reports them.
     *
     * @var list<StatusClickSpan>
     */
    private array $cellClicks = [];

    /**
     * Their on-screen spans from the last render.
     *
     * @var list<StatusClickSpan>
     */
    private array $renderedClicks = [];

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
     * @param callable(): array{left: string, right: string, menus?: list<StatusMenuSpan>, rightClicks?: list<StatusClickSpan>} $provider
     */
    public function setProvider(callable $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

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
     * The action a status cell at column $col runs on a LEFT click, or null
     * when nothing there is actionable.
     *
     * The right-hand cells — problem counts, the caret position, the LSP
     * status — were plain strings until now, so the numbers people look at
     * most did nothing at all when clicked.
     */
    public function clickAt(int $col): ?string
    {
        foreach ($this->renderedClicks as $span) {
            if ($span->contains($col)) {
                return $span->action;
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

    /**
     * Take back a flash, if it is still the one on screen.
     *
     * For a message that belongs to something IN PROGRESS rather than to
     * something that happened — a drag's "Moving 3 files" stops being true the
     * moment the drag ends, and sitting out its remaining seconds would cover
     * whatever the drop itself has to say.
     *
     * Guarded by the text rather than clearing whatever is there, so a message
     * posted since — a save, an error — is never silently swallowed by whoever
     * flashed before it.
     */
    public function withdrawFlash(string $text): void
    {
        if (null === $this->flash || $this->flash['text'] !== $text) {
            return;
        }
        $this->flash = null;
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
        $this->cellClicks = $segments['rightClicks'] ?? [];
        if ($left !== $this->lastLeft || $right !== $this->lastRight) {
            $this->lastLeft = $left;
            $this->lastRight = $right;
            $this->invalidate();
        }
    }

    /**
     * Columns held back for the left side before the right may take the rest.
     *
     * Never more than the left actually has to show, and never more than half
     * the row — on a 40-column split, starving the caret position to spell a
     * path in full would trade one missing fact for another.
     */
    private function leftFloor(string $left, int $columns): int
    {
        return \min(AnsiUtils::visibleWidth($left), self::LEFT_FLOOR, \intdiv($columns, num2: 2));
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
            $rightMax = \max(0, $columns - \min(AnsiUtils::visibleWidth($left), $columns - 3) - 3);
            $right = AnsiText::truncate($right, $rightMax);
        }

        // The right side is the pane's own facts plus the key legend, and it can
        // be wider than the terminal ON ITS OWN — the graph pane's runs to 78
        // columns, so a vertical split at 70 is enough. Cutting it here rather
        // than letting the composed row overflow is what makes the cut legible:
        // the whole-row truncate below passes an EMPTY ellipsis, because by
        // then the row is meant to be exactly the width already, so an over-wide
        // legend used to come out chopped mid-word with nothing to mark it.
        // Clipped click spans fall out of the map below on their own, since
        // that filter reads the width computed from the truncated string.
        //
        // It yields to the left's floor FIRST, which it did not use to. Giving
        // it `columns - 3` and handing the left the remainder meant the file
        // being edited was the first thing sacrificed and a static key legend
        // the last: at 100 columns the name was gone in English, at 140 in
        // German. The right's tail IS that legend, so cutting it here spends
        // the difference on hints nobody needs twice.
        $right = AnsiText::truncate($right, \max(0, $columns - 3 - $this->leftFloor($left, $columns)));
        $rightWidth = AnsiUtils::visibleWidth($right);
        $leftMax = \max(0, $columns - $rightWidth - 3);
        // Elided in the MIDDLE, because the left ends with the file's name and
        // begins with the contributed cells: a tail cut kept `src/Some…` and
        // threw away the one word that says which file this is.
        $whole = $left;
        $left = AnsiText::elideMiddle($left, $leftMax);
        $leftWidth = AnsiUtils::visibleWidth($left);

        // Cells are offset by the one leading space in the row. Only offer a
        // hit region when the whole label is on screen — not while a flash
        // replaced `left`, and not once anything was elided out of it: the
        // spans are offsets into the UNCUT string, and a middle cut moves
        // every column after it while leaving the widths that filter would
        // compare still plausible.
        $this->renderedMenus =
            null !== $this->flash || $left !== $whole
                ? []
                : \array_values(\array_map(
                    static fn (StatusMenuSpan $span): StatusMenuSpan => $span->shifted(1),
                    \array_filter($this->cellMenus, static fn (StatusMenuSpan $span): bool => $span->to <= $leftWidth),
                ));

        $gap = \max(1, $columns - $leftWidth - $rightWidth - 2);
        // The right segment starts after the leading space, the left, and the
        // gap — so its cells only get screen columns once the gap is known.
        // A flash steals the row, and a truncated right side would put the
        // spans in the wrong place, so neither offers a hit region.
        $rightStart = 1 + $leftWidth + $gap;
        $this->renderedClicks = null !== $this->flash
            ? []
            : \array_values(\array_map(
                static fn (StatusClickSpan $span): StatusClickSpan => $span->shifted($rightStart),
                \array_filter($this->cellClicks, static fn (StatusClickSpan $span): bool => $span->to <= $rightWidth),
            ));
        $line = ' '.$left.\str_repeat(' ', $gap).$right.' ';
        // '' ellipsis: chrome already gap-padded to the width — never mark it.
        $line = AnsiText::truncate($line, $columns, pad: true, ellipsis: '');

        return [$this->styles->merge(Role::HeaderBar, Role::CurrentLineNumber)->apply($line)];
    }
}
