<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\I18n\Translator;
use Porta\Search\FileIndex;
use Porta\Ui\ColorPalette;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\Palette;
use Porta\Ui\Role;
use Porta\Ui\Style;
use Porta\Ui\TextField;
use Porta\Widget\Render\HeaderRenderer;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * Fuzzy go-to-file palette (Ctrl+P), rendered as a strip at the top:
 * a query line plus up to N scored results. Hidden (zero rows) when closed.
 */
final class GoToFileWidget extends OverlayWidget implements FocusableInterface
{
    use FocusableTrait;
    use InputHook;
    use KeybindingsTrait;

    private const int MAX_RESULTS = 8;

    private readonly TextField $query;
    private readonly ListSelection $list;
    private readonly FileIndex $files;

    /** @var list<string> current filtered results (relative paths) */
    private array $results = [];

    /** @var (callable(string): void)|null absolute path picked */
    private $onPick = null;

    private readonly Palette $palette;

    public function __construct(
        private readonly Translator $translator,
        private readonly string $rootDir,
        ?FileIndex $files = null,
        ?Palette $palette = null,
    ) {
        $this->palette = $palette ?? ColorPalette::dark();
        $this->files = $files ?? new FileIndex($rootDir);
        $this->query = new TextField();
        $this->list = new ListSelection();
    }

    public function onPick(callable $callback): self
    {
        $this->onPick = $callback;

        return $this;
    }

    public function open(): void
    {
        $this->open = true;
        $this->query->clear();
        $this->list->reset();
        $this->files->refresh(); // re-walk lazily
        $this->refilter();
        $this->invalidate();
    }

    public function handleInput(string $data): void
    {
        if ($this->inputHookHandled($data)) {
            return;
        }
        if (!$this->open) {
            return;
        }

        $kb = $this->getKeybindings();

        if ($kb->matches($data, 'close')) {
            $this->close();
        } elseif (ListNavigation::handleKey($data, $kb, $this->list, \count($this->results))) {
            $this->invalidate();
        } elseif ($kb->matches($data, 'pick')) {
            $picked = $this->results[$this->list->selected()] ?? null;
            $this->close();
            if (null !== $picked && null !== $this->onPick) {
                ($this->onPick)($this->rootDir.'/'.$picked);
            }
        } elseif ($kb->matches($data, 'delete_char_backward')) {
            if ($this->query->backspace()) {
                $this->refilter();
                $this->invalidate();
            }
        } elseif ($this->query->insert($data)) {
            $this->refilter();
            $this->invalidate();
        }
    }

    /** Route a left-click at the given widget-relative row: a result row picks that file. */
    public function handleClick(int $relRow): void
    {
        $index = $this->rowIndexAt($relRow);
        if (null === $index) {
            return;
        }
        $this->list->select($index);
        $picked = $this->results[$index];
        $this->close();
        if (null !== $this->onPick) {
            ($this->onPick)($this->rootDir.'/'.$picked);
        }
    }

    /** Highlight the result row under the pointer (widget-relative row); returns whether it changed. */
    public function hoverRow(int $relRow): bool
    {
        if ($this->list->selectIfChanged($this->rowIndexAt($relRow))) {
            $this->invalidate();

            return true;
        }

        return false;
    }

    /** Result index at the given widget-relative row, or null if it is not a result row. */
    private function rowIndexAt(int $relRow): ?int
    {
        $i = $relRow - 1; // row 0 is the query header
        if ($i < 0 || $i >= self::MAX_RESULTS) {
            return null;
        }

        return $i < \count($this->results) ? $i : null;
    }

    public function render(RenderContext $context): array
    {
        if (!$this->open) {
            return [];
        }

        $columns = $context->getColumns();

        $lines = [];
        $header = ' '.$this->translator->trans('Go to File:').' '.$this->query->render(cursor: $this->focused);
        $lines[] = HeaderRenderer::render($header, $columns, $this->palette->of(Role::HeaderBar)."\x1b[1m");

        foreach (\array_slice($this->results, 0, self::MAX_RESULTS) as $i => $path) {
            $selected = $i === $this->list->selected();
            $bg = $selected ? $this->palette->of(Role::SelectedRow) : $this->palette->of(Role::PopupRow);
            $name = basename($path);
            $dir = ltrim(substr($path, 0, -\strlen($name)), '/');
            $text = ' '.$name.'  '.$this->palette->of(Role::CompletionHint).$dir;
            $lines[] = $bg.AnsiUtils::truncateToWidth($text, $columns, '…', pad: true)."\x1b[0m";
        }

        if ([] === $this->results && '' !== $this->query->value()) {
            $lines[] =
                $this->palette->of(Role::RowHighlightBg)
                .$this->palette->of(Role::CompletionHint)
                .AnsiUtils::truncateToWidth(' '.$this->translator->trans('no matches'), $columns, '', pad: true)
                ."\x1b[0m";
        }

        return $lines;
    }

    // --- Matching -------------------------------------------------------------

    private function refilter(): void
    {
        $this->list->reset();
        $this->results = $this->files->search($this->query->value(), self::MAX_RESULTS);
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'close' => [Key::ESCAPE],
            'move_up' => [Key::UP],
            'move_down' => [Key::DOWN],
            'pick' => [Key::ENTER],
            'delete_char_backward' => [Key::BACKSPACE],
        ];
    }
}
