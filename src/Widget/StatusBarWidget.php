<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsIdeService;
use Porta\Container\AsWidget;
use Porta\Support\AnsiText;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Single-line status bar. Content is pulled from a provider closure on each
 * frame, so it always reflects the current editor/LSP state.
 */
#[AsIdeService]
#[AsWidget('status')]
final class StatusBarWidget extends AbstractWidget
{
    /** @var (callable(): array{left: string, right: string, branchStart?: int|null, branchEnd?: int|null})|null */
    private $provider = null;

    private string $lastLeft = '';
    private string $lastRight = '';
    /** The branch cell's column range within `left` (from the provider), or null. */
    private ?int $branchStart = null;
    private ?int $branchEnd = null;
    /** @var array{int, int}|null the branch's on-screen column span from the last render, for hit-testing */
    private ?array $branchSpan = null;

    /** @var array{text: string, until: float}|null */
    private ?array $flash = null;

    private readonly ClockInterface $clock;
    private readonly Styles $styles;

    /** The clock is injected by the container; a MockClock makes flash expiry testable. */
    public function __construct(?ClockInterface $clock = null, ?Styles $styles = null)
    {
        $this->clock = $clock ?? new NativeClock();
        $this->styles = $styles ?? Styles::dark();
    }

    /**
     * @param callable(): array{left: string, right: string, branchStart?: int|null, branchEnd?: int|null} $provider
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
    public function branchColumnSpan(): ?array
    {
        return $this->branchSpan;
    }

    /** The clock's current instant as float seconds (with sub-second precision). */
    private function nowSeconds(): float
    {
        /** @var numeric-string $seconds */
        $seconds = $this->clock->now()->format('U.u');

        return (float) $seconds;
    }

    /**
     * Show a transient message (e.g. "Saved") for a few seconds.
     */
    public function flash(string $text, float $seconds = 2.5): void
    {
        $this->flash = ['text' => $text, 'until' => $this->nowSeconds() + $seconds];
        $this->invalidate();
    }

    public function beforeRender(): void
    {
        $now = $this->nowSeconds();
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
        $this->branchStart = $segments['branchStart'] ?? null;
        $this->branchEnd = $segments['branchEnd'] ?? null;
        if ($left !== $this->lastLeft || $right !== $this->lastRight) {
            $this->lastLeft = $left;
            $this->lastRight = $right;
            $this->invalidate();
        }
    }

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

        // The branch (leftmost in `left`) is offset by the one leading space in
        // the row. Only offer its hit region when the whole label is on screen —
        // not while a flash replaced `left`, nor when truncation clipped it.
        $this->branchSpan =
            null !== $this->flash || null === $this->branchStart || null === $this->branchEnd || $this->branchEnd > $leftWidth
                ? null
                : [1 + $this->branchStart, 1 + $this->branchEnd];

        $gap = max(1, $columns - $leftWidth - $rightWidth - 2);
        $line = ' '.$left.str_repeat(' ', $gap).$right.' ';
        // '' ellipsis: chrome already gap-padded to the width — never mark it.
        $line = AnsiText::truncate($line, $columns, pad: true, ellipsis: '');

        return [$this->styles->merge(Role::HeaderBar, Role::CurrentLineNumber)->apply($line)];
    }
}
