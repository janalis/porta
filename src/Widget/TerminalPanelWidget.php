<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Terminal\TerminalBuffer;
use Porta\Widget\Render\HeaderRenderer;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * Bottom terminal panel: renders a {@see TerminalBuffer}'s scrollback and
 * forwards keystrokes to the running process. Hidden (zero rows) when closed.
 */
final class TerminalPanelWidget extends OverlayWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    private int $visibleRows = 12;

    private string $title = 'Terminal';
    private TerminalBuffer $buffer;
    private int $scrollBack = 0; // lines scrolled up from the bottom

    /** @var (callable(string): void)|null forwards input bytes to the process */
    private $onInputForward = null;

    public function __construct()
    {
        $this->buffer = new TerminalBuffer();
    }

    public function open(string $title, TerminalBuffer $buffer): void
    {
        $this->open = true;
        $this->title = $title;
        $this->buffer = $buffer;
        $this->scrollBack = 0;
        $this->invalidate();
    }

    public function setTitle(string $title): void
    {
        if ($this->title !== $title) {
            $this->title = $title;
            $this->invalidate();
        }
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /** Call when new output has been folded into the buffer. */
    public function refresh(): void
    {
        $this->scrollBack = 0; // stick to the bottom on new output
        $this->invalidate();
    }

    /**
     * Scroll the view $lines up into the scrollback (positive) or back down
     * toward the live bottom (negative), clamped to the available history.
     */
    public function scroll(int $lines): void
    {
        $max = max(0, $this->buffer->lineCount() - $this->visibleRows);
        $this->scrollBack = max(0, min($this->scrollBack + $lines, $max));
        $this->invalidate();
    }

    /** Resize the panel to $rows visible rows (clamped). */
    public function setVisibleRows(int $rows): void
    {
        $rows = max(2, min($rows, 200));
        if ($rows !== $this->visibleRows) {
            $this->visibleRows = $rows;
            $this->invalidate();
        }
    }

    public function visibleRows(): int
    {
        return $this->visibleRows;
    }

    public function onInputForward(callable $callback): self
    {
        $this->onInputForward = $callback;

        return $this;
    }

    public function handleInput(string $data): void
    {
        if (null !== $this->onInput && ($this->onInput)($data)) {
            return;
        }
        if (!$this->open) {
            return;
        }

        $kb = $this->getKeybindings();

        if ($kb->matches($data, 'hide_terminal')) {
            $this->close();

            return;
        }
        if ($kb->matches($data, 'scroll_up')) {
            $this->scroll(3);

            return;
        }
        if ($kb->matches($data, 'scroll_down')) {
            $this->scroll(-3);

            return;
        }

        // Everything else is forwarded verbatim to the process.
        if (null !== $this->onInputForward) {
            ($this->onInputForward)($data);
        }
    }

    public function render(RenderContext $context): array
    {
        if (!$this->open) {
            return [];
        }

        $columns = $context->getColumns();
        $rows = max(2, min($this->visibleRows, $context->getRows() > 0 ? $context->getRows() : $this->visibleRows));

        $header = ' '.$this->title.($this->scrollBack > 0 ? \sprintf('  [scrolled +%d]', $this->scrollBack) : '');
        $lines = [HeaderRenderer::render($header, $columns, bg: 235)];

        $bodyRows = $rows - 1;
        $all = $this->buffer->getLines();
        $end = \count($all) - $this->scrollBack;
        $start = max(0, $end - $bodyRows);
        $window = \array_slice($all, $start, $bodyRows);

        foreach ($window as $line) {
            $lines[] = AnsiUtils::truncateToWidth($line, $columns, '', pad: true)."\x1b[0m";
        }
        // Pad to a stable height.
        for ($i = \count($window); $i < $bodyRows; ++$i) {
            $lines[] = str_repeat(' ', $columns);
        }

        return $lines;
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'hide_terminal' => ['ctrl+`', Key::ESCAPE],
            'scroll_up' => [Key::PAGE_UP],
            'scroll_down' => [Key::PAGE_DOWN],
        ];
    }
}
