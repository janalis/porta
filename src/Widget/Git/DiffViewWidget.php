<?php

declare(strict_types=1);

namespace Porta\Widget\Git;

use Porta\Git\DiffDocument;
use Porta\Highlight\CodeHighlighter;
use Porta\Widget\InputHook;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * The git view's diff pane (GitHub Desktop's right column): dual old/new
 * line-number gutters, cyan hunk headers, ± rows colored over syntax
 * highlighting (deleted lines stay plain red — the pre-image no longer
 * matches the file's highlight state). Scrollable; read-only for history.
 */
final class DiffViewWidget extends AbstractWidget implements FocusableInterface
{
    use FocusableTrait;
    use InputHook;
    use KeybindingsTrait;

    private const string ADD_FG = "\x1b[38;5;114m";
    private const string DEL_FG = "\x1b[38;5;203m";
    private const string HUNK_FG = "\x1b[38;5;75m";
    private const string GUTTER_FG = "\x1b[38;5;240m";

    private ?DiffDocument $document = null;
    private ?string $path = null;
    private string $placeholder = 'No changes selected';
    /** @var list<array{kind: 'hunk'|'line', hunk: int, line: int}> */
    private array $rows = [];
    /** @var array<int, string> highlighted text per flat row index (context/add rows only) */
    private array $highlighted = [];
    private int $scroll = 0;
    private int $lastRows = 12;
    private bool $readOnly = false;

    private readonly CodeHighlighter $highlighter;
    private int $highlightVersion = 0;

    /** @var (callable(): void)|null Tab: move focus to the next git pane */
    private $onFocusNext = null;
    /** @var (callable(): void)|null Esc: leave the git view */
    private $onExit = null;

    public function __construct()
    {
        $this->highlighter = new CodeHighlighter();
    }

    public function onFocusNext(callable $callback): self
    {
        $this->onFocusNext = $callback;

        return $this;
    }

    public function onExit(callable $callback): self
    {
        $this->onExit = $callback;

        return $this;
    }

    public function setReadOnly(bool $readOnly): void
    {
        $this->readOnly = $readOnly;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    /** Show $document for $path (null document = the placeholder message). */
    public function setDocument(?DiffDocument $document, ?string $path, string $placeholder = 'No changes selected'): void
    {
        $this->document = $document;
        $this->path = $path;
        $this->placeholder = $placeholder;
        $this->rows = $document?->rows() ?? [];
        $this->scroll = 0;
        $this->highlightRows();
        $this->invalidate();
    }

    /**
     * Syntax-highlight the context/addition texts in one batch (deleted lines
     * stay plain). The highlighter caches by version, so one bumped counter
     * per load keeps repeated renders free.
     */
    private function highlightRows(): void
    {
        $this->highlighted = [];
        if (null === $this->document || null === $this->path) {
            return;
        }
        $this->highlighter->setFile($this->path);

        $indices = [];
        $texts = [];
        foreach ($this->rows as $i => $row) {
            if ('line' !== $row['kind']) {
                continue;
            }
            $line = $this->document->hunks[$row['hunk']]->lines[$row['line']];
            if ('+' === $line->origin || ' ' === $line->origin) {
                $indices[] = $i;
                $texts[] = $line->text;
            }
        }

        $highlighted = $this->highlighter->highlight($texts, ++$this->highlightVersion);
        foreach ($indices as $n => $rowIndex) {
            $this->highlighted[$rowIndex] = $highlighted[$n] ?? '';
        }
    }

    public function scrollBy(int $lines): void
    {
        $max = max(0, \count($this->rows) - max(1, $this->lastRows - 1));
        $this->scroll = max(0, min($this->scroll + $lines, $max));
        $this->invalidate();
    }

    public function handleInput(string $data): void
    {
        if ($this->inputHookHandled($data)) {
            return;
        }
        $kb = $this->getKeybindings();

        if ($kb->matches($data, 'exit_view')) {
            if (null !== $this->onExit) {
                ($this->onExit)();
            }
        } elseif ($kb->matches($data, 'focus_next_pane')) {
            if (null !== $this->onFocusNext) {
                ($this->onFocusNext)();
            }
        } elseif ($kb->matches($data, 'scroll_up')) {
            $this->scrollBy(-1);
        } elseif ($kb->matches($data, 'scroll_down')) {
            $this->scrollBy(1);
        } elseif ($kb->matches($data, 'page_up')) {
            $this->scrollBy(-max(1, $this->lastRows - 2));
        } elseif ($kb->matches($data, 'page_down')) {
            $this->scrollBy(max(1, $this->lastRows - 2));
        }
    }

    public function render(RenderContext $context): array
    {
        $width = $context->getColumns();
        $rows = max(1, $context->getRows() > 0 ? $context->getRows() : 12);
        $this->lastRows = $rows;

        $title = ' '.($this->path ?? 'Diff').($this->scroll > 0 ? \sprintf('  [scrolled +%d]', $this->scroll) : '');
        $lines = ["\x1b[48;5;237m\x1b[1m".AnsiUtils::truncateToWidth($title, $width, '…', pad: true)."\x1b[0m"];

        if (null === $this->document || [] === $this->rows) {
            $message = "\x1b[38;5;245m ".$this->placeholder."\x1b[0m";
            $lines[] = AnsiUtils::truncateToWidth($message, $width, '…', pad: true);
            while (\count($lines) < $rows) {
                $lines[] = str_repeat(' ', $width);
            }

            return $lines;
        }

        foreach (\array_slice($this->rows, $this->scroll, $rows - 1) as $i => $row) {
            $lines[] = $this->renderRow($this->scroll + $i, $row, $width);
        }
        while (\count($lines) < $rows) {
            $lines[] = str_repeat(' ', $width);
        }

        return $lines;
    }

    /** @param array{kind: 'hunk'|'line', hunk: int, line: int} $row */
    private function renderRow(int $rowIndex, array $row, int $width): string
    {
        \assert(null !== $this->document);
        $hunk = $this->document->hunks[$row['hunk']];

        if ('hunk' === $row['kind']) {
            return self::HUNK_FG.AnsiUtils::truncateToWidth(' '.$hunk->header(), $width, '…', pad: true)."\x1b[0m";
        }

        $line = $hunk->lines[$row['line']];
        $gutter = self::GUTTER_FG
            .str_pad(null === $line->oldNo ? '' : (string) $line->oldNo, 4, ' ', \STR_PAD_LEFT)
            .' '
            .str_pad(null === $line->newNo ? '' : (string) $line->newNo, 4, ' ', \STR_PAD_LEFT)
            .' │'."\x1b[39m";

        // The origin char and gutter mark the row kind; added/context text keeps
        // its syntax colors, deletions render whole-row red (the pre-image no
        // longer matches the file's highlight state).
        $content = match ($line->origin) {
            '+' => self::ADD_FG.'+'."\x1b[39m".($this->highlighted[$rowIndex] ?? $line->text),
            '-' => self::DEL_FG.'-'.$line->text,
            '\\' => "\x1b[38;5;245m ".$line->text,
            default => ' '.($this->highlighted[$rowIndex] ?? $line->text),
        };

        return $gutter.AnsiUtils::truncateToWidth($content, max(1, $width - 11), '…', pad: true)."\x1b[0m";
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'scroll_up' => [Key::UP],
            'scroll_down' => [Key::DOWN],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'focus_next_pane' => [Key::TAB],
            'exit_view' => [Key::ESCAPE],
        ];
    }
}
