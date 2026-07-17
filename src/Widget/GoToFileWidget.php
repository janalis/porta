<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsWidget;
use Porta\I18n\Translator;
use Porta\Search\FileIndex;
use Porta\Ui\CaretVisibility;
use Porta\Ui\CursorStyle;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\Role;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;
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
#[AsWidget('goto-file')]
final class GoToFileWidget extends OverlayWidget implements FocusableInterface, HoverableList
{
    use FocusableTrait;
    use HoverTrait;
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

    private readonly Styles $styles;

    public function __construct(
        private readonly Translator $translator,
        private readonly string $rootDir,
        ?FileIndex $files = null,
        ?Styles $styles = null,
        ?CursorStyle $cursorStyle = null,
    ) {
        $this->styles = $styles ?? Styles::dark();
        $this->files = $files ?? new FileIndex($rootDir);
        $this->query = new TextField(style: $cursorStyle);
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

            return;
        }
        if (ListNavigation::handleKey($data, $kb, $this->list, \count($this->results))) {
            $this->invalidate();

            return;
        }
        if ($kb->matches($data, 'pick')) {
            $picked = $this->results[$this->list->selected()] ?? null;
            $this->close();
            if (null !== $picked && null !== $this->onPick) {
                ($this->onPick)($this->rootDir.'/'.$picked);
            }

            return;
        }
        if ($kb->matches($data, 'delete_char_backward')) {
            if ($this->query->backspace()) {
                $this->refilter();
                $this->invalidate();
            }

            return;
        }
        if ($this->query->insert($data)) {
            $this->refilter();
            $this->invalidate();
        }
    }

    /** Wheel over the popup: move the selection like the arrow keys. */
    public function moveSelection(int $delta): void
    {
        if ($delta < 0) {
            $this->list->moveUp(-$delta);
        }
        if ($delta > 0) {
            $this->list->moveDown(\count($this->results), $delta);
        }
        $this->invalidate();
    }

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

    /** Tint the result row under the pointer (widget-relative row); returns whether it changed. */
    public function hoverRow(int $relRow): bool
    {
        return $this->setHovered($this->rowIndexAt($relRow));
    }

    /** Result index at the given widget-relative row, or null if it is not a result row. */
    private function rowIndexAt(int $relRow): ?int
    {
        // Row 0 is the query header; results are windowed to MAX_RESULTS.
        return $this->list->rowIndexAt($relRow, min(\count($this->results), self::MAX_RESULTS));
    }

    public function render(RenderContext $context): array
    {
        if (!$this->open) {
            return [];
        }

        $columns = $context->getColumns();

        $lines = [];
        $header = ' '.$this->translator->trans('Go to File:').' '.$this->query->render(cursor: $this->focused ? CaretVisibility::Shown : CaretVisibility::Hidden);
        $lines[] = HeaderRenderer::render($header, $columns, $this->styles->of(Role::HeaderBar)->withBold(), suffix: 'Esc');

        foreach (\array_slice($this->results, offset: 0, length: self::MAX_RESULTS) as $i => $path) {
            $style = $this->styles->of(match (true) {
                $i === $this->list->selected() => Role::SelectedRow,
                $i === $this->hoveredIndex => Role::RowHighlightBg,
                default => Role::PopupRow,
            });
            $name = basename($path);
            $dir = ltrim(substr($path, offset: 0, length: -\strlen($name)), characters: '/');
            $text = ' '.$name.'  '.$this->styles->of(Role::CompletionHint)->getAnsiRestore().$dir;
            $lines[] = $style->apply(AnsiUtils::truncateToWidth($text, $columns, '…', pad: true));
        }

        if ([] === $this->results && '' !== $this->query->value()) {
            // '' ellipsis: a fixed short filler row — never worth a truncation mark.
            $lines[] = $this->styles->merge(Role::RowHighlightBg, Role::CompletionHint)->apply(AnsiUtils::truncateToWidth(' '.$this->translator->trans('no matches'), $columns, '', pad: true));
        }

        return $lines;
    }

    // --- Matching -------------------------------------------------------------

    /** Re-run the query. Drops the hover tint — the rows move, the pointer has not. */
    private function refilter(): void
    {
        $this->clearHover();
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
