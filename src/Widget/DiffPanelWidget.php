<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Widget\Render\HeaderRenderer;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * Bottom panel showing a file's unified diff against HEAD, coloured per line
 * (additions green, deletions red, hunk headers cyan, file headers dimmed).
 * Scrollable; hidden (zero rows) when closed.
 */
final class DiffPanelWidget extends OverlayWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    private int $visibleRows = 14;

    private string $title = 'Diff';
    /** @var list<array{kind: string, text: string}> */
    private array $rows = [];
    private int $scrollBack = 0;

    /**
     * @param list<array{kind: string, text: string}> $rows
     */
    public function open(string $title, array $rows): void
    {
        $this->open = true;
        $this->title = $title;
        $this->rows = $rows;
        $this->scrollBack = 0;
        $this->invalidate();
    }

    public function getTitle(): string
    {
        return $this->title;
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

    public function handleInput(string $data): void
    {
        if (null !== $this->onInput && ($this->onInput)($data)) {
            return;
        }
        if (!$this->open) {
            return;
        }

        $kb = $this->getKeybindings();

        if ($kb->matches($data, 'hide_diff')) {
            $this->close();

            return;
        }
        if ($kb->matches($data, 'scroll_up')) {
            $this->scrollBack = min($this->scrollBack + 3, max(0, \count($this->rows) - $this->visibleRows));
            $this->invalidate();

            return;
        }
        if ($kb->matches($data, 'scroll_down')) {
            $this->scrollBack = max(0, $this->scrollBack - 3);
            $this->invalidate();
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
        $end = \count($this->rows) - $this->scrollBack;
        $start = max(0, $end - $bodyRows);
        $window = \array_slice($this->rows, $start, $bodyRows);

        foreach ($window as $row) {
            $color = self::color($row['kind']);
            $text = AnsiUtils::truncateToWidth($row['text'], $columns, '', pad: true);
            $lines[] = $color.$text."\x1b[0m";
        }
        for ($i = \count($window); $i < $bodyRows; ++$i) {
            $lines[] = str_repeat(' ', $columns);
        }

        return $lines;
    }

    private static function color(string $kind): string
    {
        return match ($kind) {
            'add' => "\x1b[38;5;114m",
            'del' => "\x1b[38;5;203m",
            'hunk' => "\x1b[38;5;75m",
            'meta' => "\x1b[1;38;5;245m",
            default => '',
        };
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'hide_diff' => ['ctrl+alt+d', Key::ESCAPE],
            'scroll_up' => [Key::PAGE_UP],
            'scroll_down' => [Key::PAGE_DOWN],
        ];
    }
}
