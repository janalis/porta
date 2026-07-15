<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsWidget;
use Porta\Editor\Document;
use Porta\I18n\Translator;
use Porta\Search\SearchPattern;
use Porta\Support\AnsiText;
use Porta\Ui\ColorPalette;
use Porta\Ui\CursorStyle;
use Porta\Ui\Palette;
use Porta\Ui\Role;
use Porta\Ui\TextField;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * In-file find/replace bar (Ctrl+F / Ctrl+R). Renders as a single line above
 * the status bar; hidden (zero rows) when closed.
 *
 * Search is incremental and smart-case: an all-lowercase query matches
 * case-insensitively, any uppercase makes it exact. Alt+X toggles regex mode
 * and Alt+W whole-word (the Find-in-Files modal uses Alt+R for regex, but
 * that key is replace-one here — muscle memory wins). In regex mode the
 * replacement is a preg replacement pattern ($1/\1 backreferences work,
 * \$ escapes a literal dollar); in literal mode it is inserted verbatim.
 */
#[AsWidget('find-bar')]
final class FindBarWidget extends OverlayWidget implements FocusableInterface
{
    use FocusableTrait;
    use InputHook;
    use KeybindingsTrait;

    private const int MAX_MATCHES = 5000;

    private bool $withReplace = false;
    private readonly TextField $query;
    private readonly TextField $replacement;
    private int $activeField = 0; // 0 = find, 1 = replace

    /** Session-lifetime search options (survive close/reopen, like the modal's). */
    private bool $regex = false;
    private bool $wholeWord = false;
    private bool $patternInvalid = false;

    /** @var list<array{line: int, start: int, end: int}> byte offsets */
    private array $matches = [];
    private int $currentIndex = -1;
    private int $lastDocVersion = -1;

    private ?Document $document = null;

    /** @var (callable(): void)|null called when matches/current change */
    private $onUpdate = null;

    private readonly Palette $palette;

    public function __construct(
        private readonly Translator $translator,
        ?Palette $palette = null,
        ?CursorStyle $cursorStyle = null,
    ) {
        $this->palette = $palette ?? ColorPalette::dark();
        $this->query = new TextField(style: $cursorStyle);
        $this->replacement = new TextField(style: $cursorStyle);
    }

    public function open(Document $document, bool $withReplace, string $initialQuery = ''): void
    {
        $this->document = $document;
        $this->open = true;
        $this->withReplace = $withReplace;
        $this->activeField = 0;
        if ('' !== $initialQuery) {
            $this->query->setValue($initialQuery);
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

        if ($kb->matches($data, 'toggle_regex')) {
            $this->regex = !$this->regex;
            $this->recompute(jumpToNearest: true);
            $this->invalidate();

            return;
        }

        if ($kb->matches($data, 'toggle_word')) {
            $this->wholeWord = !$this->wholeWord;
            $this->recompute(jumpToNearest: true);
            $this->invalidate();

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
            $this->editActiveField(static fn (TextField $field): bool => $field->backspace());

            return;
        }

        // Plain text input
        $this->editActiveField(static fn (TextField $field): bool => $field->insert($data));
    }

    /**
     * Apply an edit to whichever field is focused (query or replacement); when it
     * changes the query, re-run the search and jump to the nearest match, then
     * repaint. The op returns whether the field actually changed.
     *
     * @param \Closure(TextField): bool $op
     */
    private function editActiveField(\Closure $op): void
    {
        $field = 0 === $this->activeField ? $this->query : $this->replacement;
        if ($op($field)) {
            if ($field === $this->query) {
                $this->recompute(jumpToNearest: true);
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
        $position = match (true) {
            $this->patternInvalid => $this->translator->trans('bad regex'),
            $count > 0 => \sprintf('%d/%d', $this->currentIndex + 1, $count),
            default => '0/0',
        };

        $findField = ' '.$this->translator->trans('Find:').' '.$this->query->render(cursor: $this->focused && 0 === $this->activeField).'  ';
        $replaceField = $this->withReplace ? $this->translator->trans('Replace:').' '.$this->replacement->render(cursor: $this->focused && 1 === $this->activeField).'  ' : '';

        $hints = $this->withReplace
            ? $this->translator->trans('⏎/F3 next  ⇧F3 prev  ⇥ field  ⌥R replace  ⌥A all  ⌥X .*  ⌥W \b  esc')
            : $this->translator->trans('⏎/F3 next  ⇧F3 prev  ⌥X .*  ⌥W \b  esc');

        $tags = $this->optionTag('.*', $this->regex).$this->optionTag('\b', $this->wholeWord);
        $left = $findField.$replaceField.$this->palette->of(Role::Dim)."[{$position}]\x1b[39m".$tags;
        $line = $left.'  '.$this->palette->of(Role::Faint).$hints."\x1b[39m";
        // Scroll the whole bar to the focused field's caret so a long query or
        // replacement never clips its cursor off the right edge.
        $line = AnsiText::scrollLineToCaret($line, $columns);

        return [$this->palette->of(Role::Toolbar).$line."\x1b[0m"];
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

        if (null === $this->document || '' === $this->query->value()) {
            $this->notifyUpdate();

            return;
        }

        $this->lastDocVersion = $this->document->getVersion();
        $this->patternInvalid = false;
        $query = $this->query->value();
        $caseInsensitive = strtolower($query) === $query; // smart-case carries into regex mode via the i flag

        if ($this->regex || $this->wholeWord) {
            $pattern = SearchPattern::build($query, $this->regex, $this->wholeWord, !$caseInsensitive);
            set_error_handler(static fn (): bool => true);
            $valid = preg_match($pattern, subject: '');
            restore_error_handler();
            if (false === $valid) {
                $this->patternInvalid = true;
                $this->notifyUpdate();

                return;
            }
            foreach ($this->document->getLines() as $lineIndex => $line) {
                $found = [];
                if ('' === $line) {
                    continue;
                }
                set_error_handler(static fn (): bool => true);
                $count = preg_match_all($pattern, $line, $found, \PREG_OFFSET_CAPTURE);
                restore_error_handler();
                if (false === $count) {
                    continue;
                }
                foreach ($found[0] as $hit) {
                    // PREG_OFFSET_CAPTURE: every hit is a [text, byte offset] pair.
                    $text = $hit[0];
                    if ('' === $text) {
                        continue; // zero-width matches highlight nothing
                    }
                    $this->matches[] = ['line' => (int) $lineIndex, 'start' => (int) $hit[1], 'end' => (int) $hit[1] + \strlen($text)];
                    if (\count($this->matches) >= self::MAX_MATCHES) {
                        break 2;
                    }
                }
            }
        }
        if (!$this->regex && !$this->wholeWord) {
            $needle = $caseInsensitive ? strtolower($query) : $query;
            $needleLen = \strlen($query);

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
        }

        if ($jumpToNearest && [] !== $this->matches) {
            $cursorLine = $this->document->getCursorLine();
            $cursorCol = $this->document->getCursorCol();
            foreach ($this->matches as $i => $match) {
                if (!($match['line'] > $cursorLine || $match['line'] === $cursorLine && $match['start'] >= $cursorCol)) {
                    continue;
                }

                $this->gotoMatch($i);

                return;
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
        if (!$this->withReplace || $this->currentIndex < 0 || !array_key_exists($this->currentIndex, $this->matches)) {
            return;
        }
        $match = $this->matches[$this->currentIndex];
        /** @var Document $doc */
        $doc = $this->document;
        $doc->setCursor($match['line'], $match['start']);
        $doc->beginSelect();
        $doc->setCursor($match['line'], $match['end'], keepSelection: true);
        $doc->insert($this->insertionFor($match));

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
            $doc->insert($this->insertionFor($match));
        }
        $this->recompute(jumpToNearest: false);
    }

    /**
     * The text to insert over a match: literal in plain mode; in regex mode
     * the replacement is a preg replacement pattern ($1/\1 backreferences,
     * \$ for a literal dollar) applied to the matched text.
     *
     * @param array{line: int, start: int, end: int} $match
     */
    private function insertionFor(array $match): string
    {
        $replacement = $this->replacement->value();
        if (!$this->regex) {
            return $replacement;
        }
        /** @var Document $doc */
        $doc = $this->document;
        $matched = substr($doc->getLines()[$match['line']] ?? '', $match['start'], $match['end'] - $match['start']);
        $pattern = SearchPattern::build($this->query->value(), regex: true, wholeWord: $this->wholeWord, caseSensitive: strtolower($this->query->value()) !== $this->query->value());
        set_error_handler(static fn (): bool => true);
        $expanded = preg_replace($pattern, $replacement, $matched, limit: 1);
        restore_error_handler();

        return \is_string($expanded) ? $expanded : $replacement;
    }

    private function notifyUpdate(): void
    {
        if (null !== $this->onUpdate) {
            ($this->onUpdate)();
        }
    }

    /** The [.*] / [\b] mode tags after the counter, lit when the option is on. */
    private function optionTag(string $label, bool $on): string
    {
        $role = $on ? Role::SelectedRow : Role::Faint;

        return ' '.$this->palette->of($role).'['.$label.']'."\x1b[0m";
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
            'toggle_regex' => ['alt+x'],
            'toggle_word' => ['alt+w'],
            'replace_all' => ['alt+a'],
            'delete_char_backward' => [Key::BACKSPACE],
        ];
    }
}
