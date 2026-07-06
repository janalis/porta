<?php

declare(strict_types=1);

namespace Porta\Widget;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Single-line status bar. Content is pulled from a provider closure on each
 * frame, so it always reflects the current editor/LSP state.
 */
final class StatusBarWidget extends AbstractWidget
{
    /** @var (callable(): array{left: string, right: string})|null */
    private $provider = null;

    private string $lastLeft = '';
    private string $lastRight = '';

    /** @var array{text: string, until: float}|null */
    private ?array $flash = null;

    /**
     * @param callable(): array{left: string, right: string} $provider
     */
    public function setProvider(callable $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    /**
     * Show a transient message (e.g. "Saved") for a few seconds.
     */
    public function flash(string $text, float $seconds = 2.5): void
    {
        $this->flash = ['text' => $text, 'until' => microtime(true) + $seconds];
        $this->invalidate();
    }

    public function beforeRender(): void
    {
        if (null !== $this->flash && microtime(true) > $this->flash['until']) {
            $this->flash = null;
            $this->invalidate();
        }

        if (null === $this->provider) {
            return;
        }

        ['left' => $left, 'right' => $right] = ($this->provider)();
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

        $rightWidth = AnsiUtils::visibleWidth($right);
        $leftMax = max(0, $columns - $rightWidth - 3);
        $left = AnsiUtils::truncateToWidth($left, $leftMax, '…');
        $leftWidth = AnsiUtils::visibleWidth($left);

        $gap = max(1, $columns - $leftWidth - $rightWidth - 2);
        $line = ' '.$left.str_repeat(' ', $gap).$right.' ';
        $line = AnsiUtils::truncateToWidth($line, $columns, '', pad: true);

        return ["\x1b[48;5;237m\x1b[38;5;252m".$line."\x1b[0m"];
    }
}
