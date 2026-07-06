<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Editor\Document;
use Porta\Input\TextInput;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\CursorShape;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * In-file find/replace bar (Ctrl+F / Ctrl+R). Renders as a single line above
 * the status bar; hidden (zero rows) when closed.
 *
 * Search is incremental and smart-case: an all-lowercase query matches
 * case-insensitively, any uppercase makes it exact.
 */
final class FindBarWidget extends OverlayWidget implements FocusableInterface
{
    use FocusableTrait;
    use InputHook;
    use KeybindingsTrait;

    private const int MAX_MATCHES = 5000;

    private bool $withReplace = false;
    private string $query = '';
    private string $replacement = '';
    private int $activeField = 0; // 0 = find, 1 = replace

    /** @var list<array{line: int, start: int, end: int}> byte offsets */
    private array $matches = [];
    private int $currentIndex = -1;
    private int $lastDocVersion = -1;

    private ?Document $document = null;

    /** @var (callable(): void)|null called when matches/current change */
    private $onUpdate = null;

    public function open(Document $document, bool $withReplace, string $initialQuery = ''): void
    {
        $this->document = $document;
        $this->open = true;
        $this->withReplace = $withReplace;
        $this->activeField = 0;
        if ('' !== $initialQuery) {
            $this->query = $initialQuery;
        }
        $this->lastDocVersion = -1;
        $this->recompute(jumpToNearest: true);
        $this->invalidate();
    }

    protected function resetState(): void
    {
        $this->matches = [];
        $this->currentIndex = -1;
        $this->notifyUpdate();
    }

    public function onUpdate(callable $callback): self
    {
        $this->onUpdate = $callback;

        return $this;
    }

    /**
     * Current matches grouped per line for the editor to highlight.
     *
     * @return array<int, list<array{start: int, end: int, current: bool}>>
     */
    public function getMatchesByLine(): array
    {
        $byLine = [];
        foreach ($this->matches as $i => $match) {
            $byLine[$match['line']][] = [
                'start' => $match['start'],
                'end' => $match['end'],
                'current' => $i === $this->currentIndex,
            ];
        }

        return $byLine;
    }

    public function handleInput(string $data): void
    {
        if ($this->inputHookHandled($data)) {
            return;
        }
        if (!$this->open || null === $this->document) {
            return;
        }

        $kb = $this->getKeybindings();

        if ($kb->matches($data, 'close')) {
            $this->close();

            return;
        }

        if ($kb->matches($data, 'find_next')) {
            $this->refreshIfStale();
            $this->gotoMatch($this->currentIndex + 1);

            return;
        }

        if ($kb->matches($data, 'find_prev')) {
            $this->refreshIfStale();
            $this->gotoMatch($this->currentIndex - 1);

            return;
        }

        if ($kb->matches($data, 'switch_field')) {
            if ($this->withReplace) {
                $this->activeField = 1 - $this->activeField;
                $this->invalidate();
            }

            return;
        }

        if ($kb->matches($data, 'replace_one')) {
            $this->replaceCurrent();

            return;
        }

        if ($kb->matches($data, 'replace_all')) {
            $this->replaceAll();

            return;
        }

        if ($kb->matches($data, 'delete_char_backward')) {
            $field = 0 === $this->activeField ? 'query' : 'replacement';
            if ('' !== $this->{$field}) {
                $this->{$field} = substr($this->{$field}, 0, -1);
                if ('query' === $field) {
                    $this->recompute(jumpToNearest: true);
                }
                $this->invalidate();
            }

            return;
        }

        // Plain text input
        if (TextInput::isPrintable($data)) {
            if (0 === $this->activeField) {
                $this->query .= $data;
                $this->recompute(jumpToNearest: true);
            } else {
                $this->replacement .= $data;
            }
            $this->invalidate();
        }
    }

    public function render(RenderContext $context): array
    {
        if (!$this->open) {
            return [];
        }

        $columns = $context->getColumns();
        $count = \count($this->matches);
        $position = $count > 0 ? \sprintf('%d/%d', $this->currentIndex + 1, $count) : '0/0';

        $cursor = $this->focused ? AnsiUtils::cursorMarker(CursorShape::Bar) : '';
        $findField = ' Find: '.$this->query.(0 === $this->activeField ? $cursor : '').'  ';
        $replaceField = $this->withReplace ? 'Replace: '.$this->replacement.(1 === $this->activeField ? $cursor : '').'  ' : '';

        $hints = $this->withReplace
            ? '⏎/F3 next  ⇧F3 prev  ⇥ field  ⌥R replace  ⌥A all  esc'
            : '⏎/F3 next  ⇧F3 prev  esc';

        $left = $findField.$replaceField."\x1b[38;5;245m[{$position}]\x1b[39m";
        $line = $left.'  '."\x1b[38;5;242m".$hints."\x1b[39m";
        $line = AnsiUtils::truncateToWidth($line, $columns, '…', pad: true);

        return ["\x1b[48;5;235m".$line."\x1b[0m"];
    }

    // --- Search internals ---------------------------------------------------

    private function refreshIfStale(): void
    {
        if (null !== $this->document && $this->document->getVersion() !== $this->lastDocVersion) {
            $this->recompute(jumpToNearest: false);
        }
    }

    private function recompute(bool $jumpToNearest): void
    {
        $this->matches = [];
        $this->currentIndex = -1;

        if (null === $this->document || '' === $this->query) {
            $this->notifyUpdate();

            return;
        }

        $this->lastDocVersion = $this->document->getVersion();
        $caseInsensitive = strtolower($this->query) === $this->query;
        $needle = $caseInsensitive ? strtolower($this->query) : $this->query;
        $needleLen = \strlen($this->query);

        foreach ($this->document->getLines() as $lineIndex => $line) {
            $haystack = $caseInsensitive ? strtolower($line) : $line;
            $offset = 0;
            while (false !== ($pos = strpos($haystack, $needle, $offset))) {
                $this->matches[] = ['line' => (int) $lineIndex, 'start' => $pos, 'end' => $pos + $needleLen];
                $offset = $pos + max(1, $needleLen);
                if (\count($this->matches) >= self::MAX_MATCHES) {
                    break 2;
                }
            }
        }

        if ($jumpToNearest && [] !== $this->matches) {
            $cursorLine = $this->document->getCursorLine();
            $cursorCol = $this->document->getCursorCol();
            foreach ($this->matches as $i => $match) {
                if ($match['line'] > $cursorLine || $match['line'] === $cursorLine && $match['start'] >= $cursorCol) {
                    $this->gotoMatch($i);

                    return;
                }
            }
            $this->gotoMatch(0);

            return;
        }

        $this->notifyUpdate();
    }

    private function gotoMatch(int $index): void
    {
        $count = \count($this->matches);
        if (0 === $count) {
            $this->currentIndex = -1;
            $this->notifyUpdate();

            return;
        }
        $this->currentIndex = (($index % $count) + $count) % $count;
        $match = $this->matches[$this->currentIndex];
        /** @var Document $document */
        $document = $this->document;
        $document->setCursor($match['line'], $match['start']);
        $this->notifyUpdate();
        $this->invalidate();
    }

    private function replaceCurrent(): void
    {
        if (!$this->withReplace || $this->currentIndex < 0 || !isset($this->matches[$this->currentIndex])) {
            return;
        }
        $match = $this->matches[$this->currentIndex];
        /** @var Document $doc */
        $doc = $this->document;
        $doc->setCursor($match['line'], $match['start']);
        $doc->beginSelect();
        $doc->setCursor($match['line'], $match['end'], keepSelection: true);
        $doc->insert($this->replacement);

        $keepIndex = $this->currentIndex;
        $this->recompute(jumpToNearest: false);
        $this->gotoMatch(min($keepIndex, \count($this->matches) - 1));
    }

    private function replaceAll(): void
    {
        if (!$this->withReplace || [] === $this->matches) {
            return;
        }
        /** @var Document $doc */
        $doc = $this->document;
        // Bottom-up so earlier offsets stay valid
        foreach (array_reverse($this->matches) as $match) {
            $doc->setCursor($match['line'], $match['start']);
            $doc->beginSelect();
            $doc->setCursor($match['line'], $match['end'], keepSelection: true);
            $doc->insert($this->replacement);
        }
        $this->recompute(jumpToNearest: false);
    }

    private function notifyUpdate(): void
    {
        if (null !== $this->onUpdate) {
            ($this->onUpdate)();
        }
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'close' => [Key::ESCAPE],
            'find_next' => [Key::ENTER, Key::F3],
            'find_prev' => ['shift+f3'],
            'switch_field' => [Key::TAB],
            'replace_one' => ['alt+r'],
            'replace_all' => ['alt+a'],
            'delete_char_backward' => [Key::BACKSPACE],
        ];
    }
}
