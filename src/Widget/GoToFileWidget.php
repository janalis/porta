<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Input\TextInput;
use Porta\Support\FuzzyMatcher;
use Porta\Ui\ListSelection;
use Porta\Widget\Render\HeaderRenderer;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\CursorShape;
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
    use KeybindingsTrait;

    private const int MAX_FILES = 20000;
    private const int MAX_RESULTS = 8;

    private string $query = '';
    private readonly ListSelection $list;

    /** @var list<string>|null relative paths, cached per open */
    private ?array $files = null;
    /** @var list<string> current filtered results (relative paths) */
    private array $results = [];

    /** @var (callable(string): void)|null absolute path picked */
    private $onPick = null;

    public function __construct(
        private readonly string $rootDir,
    ) {
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
        $this->query = '';
        $this->list->reset();
        $this->files = null; // re-walk lazily
        $this->refilter();
        $this->invalidate();
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

        if ($kb->matches($data, 'close')) {
            $this->close();
        } elseif ($kb->matches($data, 'move_up')) {
            $this->list->moveUp();
            $this->invalidate();
        } elseif ($kb->matches($data, 'move_down')) {
            $this->list->moveDown(\count($this->results));
            $this->invalidate();
        } elseif ($kb->matches($data, 'pick')) {
            $picked = $this->results[$this->list->selected()] ?? null;
            $this->close();
            if (null !== $picked && null !== $this->onPick) {
                ($this->onPick)($this->rootDir.'/'.$picked);
            }
        } elseif ($kb->matches($data, 'delete_char_backward')) {
            if ('' !== $this->query) {
                $this->query = substr($this->query, 0, -1);
                $this->refilter();
                $this->invalidate();
            }
        } elseif (TextInput::isPrintable($data)) {
            $this->query .= $data;
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
        $index = $this->rowIndexAt($relRow);
        if (null !== $index && $index !== $this->list->selected()) {
            $this->list->select($index);
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
        $cursor = $this->focused ? AnsiUtils::cursorMarker(CursorShape::Bar) : '';

        $lines = [];
        $header = ' Go to File: '.$this->query.$cursor;
        $lines[] = HeaderRenderer::render($header, $columns);

        foreach (\array_slice($this->results, 0, self::MAX_RESULTS) as $i => $path) {
            $selected = $i === $this->list->selected();
            $bg = $selected ? "\x1b[48;5;31m\x1b[38;5;231m" : "\x1b[48;5;236m\x1b[38;5;250m";
            $name = basename($path);
            $dir = ltrim(substr($path, 0, -\strlen($name)), '/');
            $text = ' '.$name.'  '."\x1b[38;5;244m".$dir;
            $lines[] = $bg.AnsiUtils::truncateToWidth($text, $columns, '…', pad: true)."\x1b[0m";
        }

        if ([] === $this->results && '' !== $this->query) {
            $lines[] = "\x1b[48;5;236m\x1b[38;5;244m".AnsiUtils::truncateToWidth(' no matches', $columns, '', pad: true)."\x1b[0m";
        }

        return $lines;
    }

    // --- Matching -------------------------------------------------------------

    private function refilter(): void
    {
        $this->files ??= $this->walk();
        $this->list->reset();

        if ('' === $this->query) {
            $this->results = \array_slice($this->files, 0, self::MAX_RESULTS);

            return;
        }

        /** @var list<array{0: int, 1: string}> $scored */
        $scored = [];
        $query = strtolower($this->query);
        foreach ($this->files as $path) {
            // Lower-cased haystack so the basename bonus applies without
            // camelCase noise; the basename offset is the primary segment.
            $lower = strtolower($path);
            $boundary = \strlen($lower) - \strlen(basename($path));
            $score = FuzzyMatcher::score($lower, $query, $boundary);
            if (null !== $score) {
                $scored[] = [$score, $path];
            }
        }
        usort($scored, static fn (array $a, array $b) => $b[0] <=> $a[0] ?: \strlen($a[1]) <=> \strlen($b[1]));
        /** @var list<string> $paths */
        $paths = array_column(\array_slice($scored, 0, self::MAX_RESULTS), 1);
        $this->results = $paths;
    }

    /** @return list<string> relative paths */
    private function walk(): array
    {
        $finder = new Finder()
            ->files()
            ->in($this->rootDir)
            ->ignoreDotFiles(false)
            ->ignoreVCS(false)
            ->exclude(['.git', 'node_modules', '.idea', '.vscode'])
            ->notName('.DS_Store');

        $files = [];
        foreach (new \LimitIterator($finder->getIterator(), 0, self::MAX_FILES) as $file) {
            $files[] = $file->getRelativePathname();
        }
        sort($files);

        return $files;
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
