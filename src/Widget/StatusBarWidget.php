<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsChromeRoot;
use Porta\Container\AsIdeService;
use Porta\Container\AsWidget;
use Porta\Support\AnsiText;
use Porta\Support\Clocks;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Tooltip;
use Porta\Ui\TooltipProvider;
use Porta\Widget\Support\StylesDefault;
use Porta\Workbench\Menu\MenuItem;
use Porta\Workbench\StatusClickSpan;
use Porta\Workbench\StatusMenuSpan;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Single-line status bar. Content is pulled from a provider closure on each
 * frame, so it always reflects the current editor/LSP state.
 */
#[AsChromeRoot]
#[AsIdeService]
#[AsWidget('status')]
final class StatusBarWidget extends AbstractWidget implements TooltipProvider
{
    use StylesDefault;

    /** @var (callable(): array{cells?: list<array{text: string, menu: (\Closure(): list<MenuItem>)|null, tooltip?: ?string}>, left: string, leftTooltip?: ?string, right: string, rightClicks?: list<StatusClickSpan>})|null */
    private $provider;

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
     * The contributed cells, as the provider reports them.
     *
     * @var list<array{text: string, menu: (\Closure(): list<MenuItem>)|null, tooltip?: ?string}>
     */
    private array $cells = [];

    /** What the file part means in full — its path uncut, its ● in words — or null. */
    private ?string $leftTooltip = null;

    /**
     * Every drawn cell that has something to say, from the last render: its
     * span on the row, its identity and its words.
     *
     * @var list<array{from: int, to: int, key: string, text: string}>
     */
    private array $renderedTips = [];

    /** The column under the pointer, or null: the clickable cell there underlines. */
    private ?int $hoverCol = null;

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
     * @param callable(): array{cells?: list<array{text: string, menu: (\Closure(): list<MenuItem>)|null, tooltip?: ?string}>, left: string, leftTooltip?: ?string, right: string, rightClicks?: list<StatusClickSpan>} $provider
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
     * The pointer over column $col (null: off the row). A clickable cell under
     * it underlines — a link's hover, so what answers a click is visible
     * before it is clicked. Returns whether that changed which cell is lit.
     */
    public function hoverAt(?int $col): bool
    {
        $before = $this->litSpan($this->hoverCol);
        $this->hoverCol = $col;
        if ($before === $this->litSpan($col)) {
            return false;
        }
        $this->invalidate();

        return true;
    }

    /**
     * What the cell under the pointer means: a contributed cell's own words,
     * the file part's full path, or what a right-hand cell is and what a click
     * on it does. Anything drawn and cut — the path first — is said whole.
     */
    #[\Override]
    public function tooltipAt(int $row, int $col): ?Tooltip
    {
        $tip = 0 === $row ? \array_find($this->renderedTips, static fn (array $tip): bool => $col >= $tip['from'] && $col < $tip['to']) : null;

        return null === $tip ? null : new Tooltip('status:'.$tip['key'], $tip['text'], 0, $tip['from'], $tip['to']);
    }

    /** @return array{int, int}|null the drawn clickable span under $col, as `[from, to]` */
    private function litSpan(?int $col): ?array
    {
        if (null === $col) {
            return null;
        }
        foreach ([...$this->renderedMenus, ...$this->renderedClicks] as $span) {
            if ($span->contains($col)) {
                return [$span->from, $span->to];
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
        $cells = $segments['cells'] ?? [];
        $this->cellClicks = $segments['rightClicks'] ?? [];
        $this->leftTooltip = $segments['leftTooltip'] ?? null;
        if ($left !== $this->lastLeft || $right !== $this->lastRight || \array_column($cells, 'text') !== \array_column($this->cells, 'text')) {
            $this->cells = $cells;
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
        // A flash takes the left, marked so it never reads as the path it
        // replaced; the cells go with it.
        $cells = null !== $this->flash ? [] : $this->cells;
        $left = null !== $this->flash ? Glyphs::FLASH_MARKER.' '.$this->flash['text'] : $this->lastLeft;
        $right = $this->lastRight;
        $whole = self::prefix(\array_column($cells, 'text')).$left;

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
        //
        // It yields to the left's floor FIRST, which it did not use to. Giving
        // it `columns - 3` and handing the left the remainder meant the file
        // being edited was the first thing sacrificed and a static key legend
        // the last: at 100 columns the name was gone in English, at 140 in
        // German. The right's tail IS that legend, so cutting it here spends
        // the difference on hints nobody needs twice.
        $right = AnsiText::truncate($right, \max(0, $columns - 3 - $this->leftFloor($whole, $columns)));
        $rightWidth = AnsiUtils::visibleWidth($right);
        $leftMax = \max(0, $columns - $rightWidth - 3);

        // The cells are controls, so they are dropped whole — from the end —
        // never cut: a half-drawn branch name is a click target that lies.
        // Only the file part is elided, in the MIDDLE, because it ends with the
        // file's name: a tail cut kept `src/Some…` and threw away the one word
        // that says which file this is.
        $drawn = $cells;
        while ([] !== $drawn && AnsiUtils::visibleWidth(self::prefix(\array_column($drawn, 'text'))) > $leftMax) {
            \array_pop($drawn);
        }
        $prefix = self::prefix(\array_column($drawn, 'text'));
        $file = AnsiText::elideMiddle($left, \max(0, $leftMax - AnsiUtils::visibleWidth($prefix)));

        // Every cell drawn keeps its span — an elided path used to cost the
        // branch its click, and a flash every right-hand cell its own.
        $this->renderedMenus = [];
        $at = 1; // the row's leading space
        $parts = [];
        foreach ($drawn as $cell) {
            $width = AnsiUtils::visibleWidth($cell['text']);
            $hovered = null !== $cell['menu'] && null !== $this->hoverCol && $this->hoverCol >= $at && $this->hoverCol < ($at + $width);
            $parts[] = $hovered ? new Style(underline: true)->apply($cell['text']) : $cell['text'];
            if (null !== $cell['menu']) {
                $this->renderedMenus[] = new StatusMenuSpan($at, $at + $width, $cell['menu']);
            }
            $at += $width + self::SEPARATOR_WIDTH;
        }
        $fileWidth = AnsiUtils::visibleWidth($file);
        $leftText = ([] === $parts ? '' : \implode($this->separator(), $parts).$this->separator()).$file;
        $leftWidth = AnsiUtils::visibleWidth($prefix.$file);

        $gap = \max(1, $columns - $leftWidth - $rightWidth - 2);
        // The right segment starts after the leading space, the left, and the
        // gap — so its cells only get screen columns once the gap is known. A
        // cut right keeps the spans that end before its ellipsis.
        $rightStart = 1 + $leftWidth + $gap;
        $room = $right === $this->lastRight ? $rightWidth : $rightWidth - 1; // a cut's last column is its ellipsis
        $this->renderedClicks = \array_values(\array_map(
            static fn (StatusClickSpan $span): StatusClickSpan => $span->shifted($rightStart),
            \array_filter($this->cellClicks, static fn (StatusClickSpan $span): bool => $span->to <= $room),
        ));
        // The file part says more only where it could: a path cut to fit, or
        // a state its glyph and colour carry (unsaved, a diagnostic) — a card
        // repeating a whole file name is noise.
        $fileSaysMore = null !== $this->leftTooltip && ($file !== $left || \str_contains($this->leftTooltip, "\n"));
        $this->renderedTips = $this->tips($drawn, $fileSaysMore ? $this->leftTooltip : null, $fileWidth);
        $lit = $this->litSpan($this->hoverCol);
        if (null !== $lit && $lit[0] >= $rightStart) {
            $right =
                AnsiText::columnsFrom($right, 0, $lit[0] - $rightStart)
                .new Style(underline: true)->apply(AnsiText::columnsFrom($right, $lit[0] - $rightStart, $lit[1] - $lit[0]))
                .AnsiText::columnsFrom($right, $lit[1] - $rightStart, $rightWidth);
        }
        $line = ' '.$leftText.\str_repeat(' ', $gap).$right.' ';
        // '' ellipsis: chrome already gap-padded to the width — never mark it.
        $line = AnsiText::truncate($line, $columns, pad: true, ellipsis: '');

        return [$this->styles->merge(Role::HeaderBar, Role::CurrentLineNumber)->apply($line)];
    }

    /**
     * Where each drawn thing with something to say sits on the row: the cells
     * in the order drawn, the file part after them when it has more to say —
     * unless a flash has its place, which says itself — and the right-hand
     * cells where they landed.
     *
     * @param list<array{text: string, menu: (\Closure(): list<MenuItem>)|null, tooltip?: ?string}> $drawn
     *
     * @return list<array{from: int, to: int, key: string, text: string}>
     */
    private function tips(array $drawn, ?string $fileTip, int $fileWidth): array
    {
        $tips = [];
        $at = 1; // the row's leading space
        foreach ($drawn as $n => $cell) {
            $width = AnsiUtils::visibleWidth($cell['text']);
            if (null !== ($cell['tooltip'] ?? null)) {
                $tips[] = ['from' => $at, 'to' => $at + $width, 'key' => 'cell:'.$n, 'text' => $cell['tooltip']];
            }
            $at += $width + self::SEPARATOR_WIDTH;
        }
        if (null === $this->flash && null !== $fileTip && $fileWidth > 0) {
            $tips[] = ['from' => $at, 'to' => $at + $fileWidth, 'key' => 'file', 'text' => $fileTip];
        }
        foreach ($this->renderedClicks as $span) {
            if ('' === $span->tooltip) {
                continue;
            }
            $tips[] = ['from' => $span->from, 'to' => $span->to, 'key' => $span->action, 'text' => $span->tooltip];
        }

        return $tips;
    }

    /** ` │ `, the one column of rule between two cells and after the last. */
    private const int SEPARATOR_WIDTH = 3;

    private function separator(): string
    {
        return ' '.$this->styles->of(Role::Rule)->apply(Glyphs::STATUS_SEPARATOR).' ';
    }

    /**
     * The cells' plain run and the separator after it, for measuring.
     *
     * @param list<string> $texts
     */
    private static function prefix(array $texts): string
    {
        return [] === $texts ? '' : \implode(' '.Glyphs::STATUS_SEPARATOR.' ', $texts).' '.Glyphs::STATUS_SEPARATOR.' ';
    }
}
