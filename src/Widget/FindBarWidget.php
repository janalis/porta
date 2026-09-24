<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsChromeRoot;
use Porta\Container\AsIdeService;
use Porta\Container\AsWidget;
use Porta\Editor\Document;
use Porta\Editor\FindMode;
use Porta\Editor\SelectionMode;
use Porta\Event\FindQueryUpdated;
use Porta\History\SearchHistoryStore;
use Porta\I18n\Translator;
use Porta\Search\CaseMode;
use Porta\Search\FindTarget;
use Porta\Search\MatchMode;
use Porta\Search\SearchPattern;
use Porta\Search\WordMode;
use Porta\Support\AnsiText;
use Porta\Ui\CaretVisibility;
use Porta\Ui\ClickZones;
use Porta\Ui\CursorStyle;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\FieldBox;
use Porta\Widget\Render\FieldSpec;
use Porta\Widget\Render\FieldState;
use Porta\Widget\Render\FindModalRows;
use Porta\Widget\Support\InputHook;
use Porta\Widget\Support\MatchJump;
use Porta\Widget\Support\OptionTagState;
use Porta\Widget\Support\StylesDefault;
use Psr\EventDispatcher\EventDispatcherInterface;
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
#[AsChromeRoot]
#[AsIdeService]
#[AsWidget('find-bar')]
final class FindBarWidget extends OverlayWidget implements FocusableInterface
{
    use FocusableTrait;
    use InputHook;
    use KeybindingsTrait;
    use StylesDefault;

    private const int MAX_MATCHES = 5000;

    private bool $withReplace = false;
    private readonly TextField $query;
    private readonly TextField $replacement;
    private int $activeField = 0; // 0 = find, 1 = replace
    /** cursor into the shared query history while browsing it, or -1 when not */
    private int $historyCursor = -1;

    /** Session-lifetime search options (survive close/reopen, like the modal's). */
    private bool $regex = false;
    private bool $wholeWord = false;
    /** Match case exactly; off, the query matches case-insensitively while it is all lower case (smart case). */
    private bool $matchCase = false;
    private bool $patternInvalid = false;

    /**
     * Whether the engine GAVE UP on a line rather than finding nothing there.
     *
     * A pattern is checked for validity by running it against an empty
     * subject, which only asks whether it compiles. `^(a|a?)+$` compiles and
     * then exhausts PCRE's backtrack limit on twenty characters — the textbook
     * catastrophic shape, and one people write by accident. `preg_match_all()`
     * answers false, and skipping that line quietly reported an empty result as
     * though it were an answer.
     */
    private bool $patternTooComplex = false;

    /** @var list<array{line: int, start: int, end: int}> byte offsets */
    private array $matches = [];
    private int $currentIndex = -1;
    private int $lastDocVersion = -1;

    private ?FindTarget $target = null;

    /**
     * The history is the PROJECT search's, shared rather than a second store.
     *
     * One list of "things you looked for in this project", recallable from
     * either surface: what you just found in a file is very often what you
     * next want across the project, and a second `.json` would make that one
     * keystroke into retyping. It also means the in-file bar inherits the
     * cap, the dedup and the persistence without owning any of them.
     */
    public function __construct(
        private readonly Translator $translator,
        ?Styles $styles = null,
        ?CursorStyle $cursorStyle = null,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly ?SearchHistoryStore $history = null,
    ) {
        $this->initStyles($styles);
        $this->query = new TextField(style: $cursorStyle);
        $this->replacement = new TextField(style: $cursorStyle);
        $this->optionZones = new ClickZones();
        $this->fieldZones = new ClickZones();
    }

    public function open(FindTarget $target, FindMode $mode, string $initialQuery = ''): void
    {
        $this->target = $target;
        $this->markOpen();
        $this->withReplace = FindMode::Replace === $mode;
        $this->activeField = 0;
        if ('' !== $initialQuery) {
            $this->query->setValue($initialQuery);
        }
        $this->lastDocVersion = -1;
        $this->recompute(jumpToNearest: MatchJump::ToNearest);
        $this->invalidate();
    }

    #[\Override]
    protected function resetState(): void
    {
        $this->matches = [];
        $this->currentIndex = -1;
        $this->notifyUpdate();
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

    #[\Override]
    public function handleInput(string $data): void
    {
        if ($this->inputHookHandled($data)) {
            return;
        }
        if (!$this->open || null === $this->target) {
            return;
        }

        $kb = $this->getKeybindings();

        if ($kb->matches($data, 'close')) {
            $this->close();

            return;
        }

        if ($kb->matches($data, 'find_next')) {
            // Recorded on Enter/F3 rather than as you type: every prefix of
            // every query would otherwise land in the list, and the one you
            // meant would be twenty entries down.
            $this->history?->add($this->query->value());
            $this->refreshIfStale();
            $this->gotoMatch($this->currentIndex + 1);

            return;
        }

        if ($kb->matches($data, 'history_prev')) {
            $this->recallHistory(1);

            return;
        }

        if ($kb->matches($data, 'history_next')) {
            $this->recallHistory(-1);

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
            $this->toggleRegex();

            return;
        }

        if ($kb->matches($data, 'toggle_word')) {
            $this->toggleWholeWord();

            return;
        }

        if ($kb->matches($data, 'toggle_case')) {
            $this->toggleMatchCase();

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

        // Plain text input, or the field's caret keys. Typing leaves
        // history-browsing, so the next Alt+Up starts from the top again
        // rather than from wherever the cursor was left three searches ago.
        $this->historyCursor = -1;
        $this->editActiveField(static fn (TextField $field): bool => $field->editKey($data) || $field->insert($data));
    }

    /**
     * Walk the recent-query history into the query field: $step +1 is older.
     *
     * Cursor `-1` is "not browsing", and stepping back to it restores an empty
     * field rather than sticking on the newest entry — the same contract
     * {@see \Porta\Overlay\State\FindInFilesModal} implements, because it is
     * the same history and two surfaces that recalled it differently would be
     * the more confusing outcome.
     */
    private function recallHistory(int $step): void
    {
        // The QUERY field only, whichever is focused. Recalling a past search
        // into the REPLACEMENT is never what the chord means, and the modal
        // gates it the same way.
        $queries = $this->history->queries ?? [];
        $next = $this->historyCursor + $step;
        if (0 !== $this->activeField || $next < -1 || $next >= \count($queries)) {
            return;
        }
        $this->historyCursor = $next;
        $this->editActiveField(static function (TextField $field) use ($queries, $next): bool {
            $field->setValue(-1 === $next ? '' : $queries[$next]);

            return true;
        });
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
                $this->recompute(jumpToNearest: MatchJump::ToNearest);
            }
            $this->invalidate();
        }
    }

    /**
     * The option tags' click zones (widget-relative columns, middle row) from
     * the last render. Seated right after the fixed-width field boxes, so they
     * do not move as the query grows — see {@see render()}.
     */
    private readonly ClickZones $optionZones;

    /** The field boxes' click zones ('find'|'replace'), spanning all three rows. */
    private readonly ClickZones $fieldZones;
    /** The option tag under the pointer ('regex'|'word'), or null. Visual only. */
    private ?string $hoveredTag = null;

    /**
     * Tint the option tag under the pointer (widget-relative cell); returns
     * whether it changed.
     */
    public function hoverAt(int $row, int $col): bool
    {
        $hovered = $this->zoneAt($row, $col);
        if ($hovered === $this->hoveredTag) {
            return false;
        }
        $this->hoveredTag = $hovered;
        $this->invalidate();

        return true;
    }

    /** The pointer left the bar: drop the tag tint. Returns whether it changed. */
    public function clearHover(): bool
    {
        return $this->hoverAt(-1, -1);
    }

    /**
     * A click on an option tag toggles it, exactly as its Alt+X / Alt+W /
     * Alt+C key does; ↑ and ↓ step to the previous and next match as
     * Shift+F3 and F3 do, × closes as Esc does; a click in a field box makes
     * it the field typing edits.
     */
    public function handleClick(int $row, int $col): void
    {
        match ($this->zoneAt($row, $col) ?? $this->fieldZones->at($col)) {
            'regex' => $this->toggleRegex(),
            'word' => $this->toggleWholeWord(),
            'case' => $this->toggleMatchCase(),
            'prev' => $this->stepMatch(-1),
            'next' => $this->stepMatch(1),
            'close' => $this->close(),
            'find' => $this->activeField = 0,
            'replace' => $this->activeField = 1,
            default => null,
        };
        $this->invalidate();
    }

    /** The match $step away from the current one, wrapping — the ↑/↓ buttons. */
    private function stepMatch(int $step): void
    {
        $this->refreshIfStale();
        $this->gotoMatch($this->currentIndex + $step);
    }

    /** The option-tag zone covering the cell in the last render, or null — the tags sit on the middle row. */
    private function zoneAt(int $row, int $col): ?string
    {
        return self::TAG_ROW === $row ? $this->optionZones->at($col) : null;
    }

    #[\Override]
    public function render(RenderContext $context): array
    {
        if (!$this->open) {
            $this->optionZones->clear();

            return [];
        }

        $columns = $context->getColumns();
        $count = \count($this->matches);
        $position = match (true) {
            $this->patternInvalid => $this->translator->trans('bad regex'),
            $this->patternTooComplex => $this->translator->trans('pattern too complex'),
            $count > 0 => \sprintf('%d/%d', $this->currentIndex + 1, $count),
            default => '0/0',
        };

        $hints =
            (
                $this->withReplace
                    ? $this->translator->trans('⏎/F3 next  Shift+F3 prev  Tab field  Alt+R replace  Alt+A all  Alt+X .*  Alt+W \b  Esc')
                    : $this->translator->trans('⏎/F3 next  Shift+F3 prev  Alt+X .*  Alt+W \b  Esc')
            ).'  Alt+C Aa';

        // The match position rides the Find box's bottom border, where it
        // lasts as long as the box does: out in the strip it was the first
        // thing a narrow pane cut, while the tags beside it survived.
        $fields = [['find', new FieldSpec($this->translator->trans('Find'), trailing: "[{$position}]"), $this->query, 0]];
        if ($this->withReplace) {
            $fields[] = ['replace', new FieldSpec($this->translator->trans('Replace')), $this->replacement, 1];
        }
        $this->optionZones->clear();
        $this->fieldZones->clear();

        // Browser-form layout: fixed-width boxes, then a strip beside them —
        // the option tags on the value row, the key hints packed into the
        // rows above and below it. Every click target
        // sits at a column the typing cannot move.
        // Each box is followed by a one-column gutter, as a form spaces its inputs.
        $boxWidth = \min(self::BOX_MAX, \intdiv($columns - self::tagsWidth(), \count($fields)) - 1);
        if ($boxWidth < FieldBox::MIN_BOXED) {
            // Too narrow to seat a box per field and the tags: the field being
            // typed in, one compact row, and no zones recorded — a tag drawn
            // past the edge is neither visible nor clickable.
            [, $spec, $field] = $fields[$this->withReplace ? $this->activeField : 0];
            $caret = $this->focused ? CaretVisibility::Shown : CaretVisibility::Hidden;

            return [$this->styles->of(Role::Toolbar)->apply(FieldBox::compact($spec, $field, FieldState::of($this->focused), $caret, $columns, $this->styles))];
        }

        $rows = ['', '', ''];
        $col = 0;
        // A query that cannot match — a bad pattern, one the engine gave up
        // on, or no hit at all — draws the Find box double, the danger
        // border: the state reads by shape, not only by its words.
        $failing = $this->patternInvalid || $this->patternTooComplex || 0 === $count && '' !== $this->query->value();
        foreach ($fields as [$name, $spec, $field, $index]) {
            $active = $this->focused && $index === $this->activeField;
            $state = 0 === $index && $failing ? FieldState::Danger : FieldState::of($active);
            foreach (FieldBox::render($spec, $field, $state, $active ? CaretVisibility::Shown : CaretVisibility::Hidden, $boxWidth, $this->styles) as $i => $line) {
                $rows[$i] .= $line.' ';
            }
            $this->fieldZones->add($name, $col, $col + $boxWidth);
            $col += $boxWidth + 1;
        }
        $strip = $col;
        foreach ([['regex', '.*', $this->regex], ['word', '\b', $this->wholeWord], ['case', 'Aa', $this->matchCase]] as [$name, $glyph, $on]) {
            $rows[self::TAG_ROW] .= FindModalRows::optionTag($glyph, OptionTagState::of($on, $name === $this->hoveredTag), $this->styles);
            $width = \mb_strwidth($glyph) + 2;
            $this->optionZones->add($name, $col, $col + $width);
            $col += $width;
        }
        // The buttons a browser's find bar has: previous, next, close. Each
        // underlines under the pointer.
        foreach ([
            ['prev',  Glyphs::FIND_PREV],
            ['next',  Glyphs::FIND_NEXT],
            ['close', Glyphs::CLOSE],
        ] as [$name, $glyph]) {
            $style = $name === $this->hoveredTag ? $this->styles->of(Role::Bright)->withUnderline() : $this->styles->of(Role::Faint);
            $rows[self::TAG_ROW] .= ' '.$style->apply($glyph).' ';
            $this->optionZones->add($name, $col, $col + 3);
            $col += 3;
        }
        [$above, $below] = self::packHints($hints, $columns - $strip);
        $rows[0] .= $this->styles->of(Role::Faint)->apply($above);
        $rows[2] .= $this->styles->of(Role::Faint)->apply($below);

        return \array_map(fn (string $row): string => $this->styles->of(Role::Toolbar)->apply(AnsiText::padRight($row, $columns)), $rows);
    }

    // --- Search internals ---------------------------------------------------

    private function refreshIfStale(): void
    {
        if (null !== $this->target && $this->target->findVersion !== $this->lastDocVersion) {
            $this->recompute(jumpToNearest: MatchJump::None);
        }
    }

    private function recompute(MatchJump $jumpToNearest): void
    {
        $this->matches = [];
        $this->currentIndex = -1;

        if (null === $this->target || '' === $this->query->value()) {
            $this->notifyUpdate();

            return;
        }

        $this->lastDocVersion = $this->target->findVersion;
        $this->patternInvalid = false;
        $this->patternTooComplex = false;
        $query = $this->query->value();
        // Smart case unless Aa is on; it carries into regex mode via the i flag.
        $caseInsensitive = !$this->matchCase && \strtolower($query) === $query;

        if ($this->regex || $this->wholeWord) {
            $pattern = SearchPattern::build(
                $query,
                $this->regex ? MatchMode::Regex : MatchMode::Literal,
                $this->wholeWord ? WordMode::WholeWord : WordMode::Anywhere,
                $caseInsensitive ? CaseMode::Insensitive : CaseMode::Sensitive,
            );
            \set_error_handler(static fn (): bool => true);
            $valid = \preg_match($pattern, subject: '');
            \restore_error_handler();
            if (false === $valid) {
                $this->patternInvalid = true;
                $this->notifyUpdate();

                return;
            }
            foreach ($this->target->findLines as $lineIndex => $line) {
                $found = [];
                if ('' === $line) {
                    continue;
                }
                \set_error_handler(static fn (): bool => true);
                $count = \preg_match_all($pattern, $line, $found, \PREG_OFFSET_CAPTURE);
                \restore_error_handler();
                if (false === $count) {
                    // The engine gave up on this line: skip it, but remember,
                    // so the count below is not offered as an answer.
                    $this->patternTooComplex = true;

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
            $needle = $caseInsensitive ? \strtolower($query) : $query;
            $needleLen = \strlen($query);

            foreach ($this->target->findLines as $lineIndex => $line) {
                $haystack = $caseInsensitive ? \strtolower($line) : $line;
                $offset = 0;
                while (false !== ($pos = \strpos($haystack, $needle, $offset))) {
                    $this->matches[] = ['line' => (int) $lineIndex, 'start' => $pos, 'end' => $pos + $needleLen];
                    $offset = $pos + \max(1, $needleLen);
                    if (\count($this->matches) >= self::MAX_MATCHES) {
                        break 2;
                    }
                }
            }
        }

        if (MatchJump::ToNearest === $jumpToNearest && [] !== $this->matches) {
            $cursorLine = $this->target->findCaretLine;
            $cursorCol = $this->target->findCaretCol;
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
        $this->target?->revealMatch($match['line'], $match['start'], $match['end']);
        $this->notifyUpdate();
        $this->invalidate();
    }

    private function replaceCurrent(): void
    {
        // Replace is editor-only; a read-only target (docs) returns no document.
        $doc = $this->target?->replaceDocument;
        if (!$this->withReplace || null === $doc || $this->currentIndex < 0 || !\array_key_exists($this->currentIndex, $this->matches)) {
            return;
        }
        $match = $this->matches[$this->currentIndex];
        $doc->setCursor($match['line'], $match['start']);
        $doc->beginSelect();
        $doc->setCursor($match['line'], $match['end'], selection: SelectionMode::Keep);
        $doc->insert($this->insertionFor($doc, $match));

        $keepIndex = $this->currentIndex;
        $this->recompute(jumpToNearest: MatchJump::None);
        $this->gotoMatch(\min($keepIndex, \count($this->matches) - 1));
    }

    private function replaceAll(): void
    {
        $doc = $this->target?->replaceDocument;
        if (!$this->withReplace || null === $doc || [] === $this->matches) {
            return;
        }
        // ONE undo step for the whole operation. `Document::insert()` coalesces
        // only with no selection and four characters or fewer, and every insert
        // here replaces a selection — so a forty-match replace pushed forty
        // entries, and undoing it meant forty Ctrl+Z. The project-wide replace
        // has been a single undoable edit all along (DocumentReplacer), so the
        // two Replace Alls behaved differently for the same verb.
        //
        // A plain begin/end pair, like the four sites that were already here
        // (AutoPairService, EditorCompletion and Document's own three): the
        // try/finally this first carried guarded a loop with nothing in it that
        // throws, and bought an arm no test can reach in exchange.
        $doc->beginCompound();
        // Bottom-up so earlier offsets stay valid
        foreach (\array_reverse($this->matches) as $match) {
            $doc->setCursor($match['line'], $match['start']);
            $doc->beginSelect();
            $doc->setCursor($match['line'], $match['end'], selection: SelectionMode::Keep);
            $doc->insert($this->insertionFor($doc, $match));
        }
        $doc->endCompound();
        $this->recompute(jumpToNearest: MatchJump::None);
    }

    /**
     * The text to insert over a match: literal in plain mode; in regex mode
     * the replacement is a preg replacement pattern ($1/\1 backreferences,
     * \$ for a literal dollar) applied to the matched text.
     *
     * @param array{line: int, start: int, end: int} $match
     */
    private function insertionFor(Document $doc, array $match): string
    {
        $replacement = $this->replacement->value();
        if (!$this->regex) {
            return $replacement;
        }
        $matched = \substr($doc->lines[$match['line']] ?? '', $match['start'], $match['end'] - $match['start']);
        $pattern = SearchPattern::build(
            $this->query->value(),
            MatchMode::Regex,
            $this->wholeWord ? WordMode::WholeWord : WordMode::Anywhere,
            \strtolower($this->query->value()) !== $this->query->value() ? CaseMode::Sensitive : CaseMode::Insensitive,
        );
        \set_error_handler(static fn (): bool => true);
        $expanded = \preg_replace($pattern, $replacement, $matched, limit: 1);
        \restore_error_handler();

        return \is_string($expanded) ? $expanded : $replacement;
    }

    private function notifyUpdate(): void
    {
        // Mirror the matches to whichever pane the bar is searching (editor
        // highlight or docs-viewer highlight); an empty map — on close/reset —
        // clears them. The callback only repaints.
        $this->target?->showSearchMatches($this->getMatchesByLine());
        $this->events?->dispatch(new FindQueryUpdated());
    }

    /** Display columns of the right-aligned option-tag strip (both tags are glyph + 2). */
    /** The row of the three-row bar that seats the option tags: the boxes' value row. */
    private const int TAG_ROW = 1;

    /** A field box's width cap — a browser input's fixed size, not the pane's. */
    private const int BOX_MAX = 32;

    /**
     * The hints (two-space separated) packed whole into two lines of $width:
     * a hint is a key and what it does, and cutting one names half a key.
     * What does not fit is left to the keybindings page.
     *
     * @return array{string, string}
     */
    private static function packHints(string $hints, int $width): array
    {
        $above = '';
        $below = null; // null until the first line is full
        foreach (\explode('  ', $hints) as $hint) {
            $line = $below ?? $above;
            $joined = '' === $line ? $hint : $line.'  '.$hint;
            if (\mb_strwidth($joined) <= $width) {
                null === $below ? ($above = $joined) : ($below = $joined);

                continue;
            }
            if (null !== $below || '' === $above || \mb_strwidth($hint) > $width) {
                break;
            }
            $below = $hint;
        }

        return [$above, $below ?? ''];
    }

    private static function tagsWidth(): int
    {
        return \mb_strwidth('.*') + 2 + \mb_strwidth('\b') + 2 + \mb_strwidth('Aa') + 2 + (3 * 3);
    }

    /** Flip regex matching and re-search (Alt+X, or a click on the [.*] tag). */
    private function toggleRegex(): void
    {
        $this->regex = !$this->regex;
        $this->recompute(jumpToNearest: MatchJump::ToNearest);
        $this->invalidate();
    }

    /** Flip exact-case matching and re-search (Alt+C, or a click on the [Aa] tag). */
    private function toggleMatchCase(): void
    {
        $this->matchCase = !$this->matchCase;
        $this->recompute(jumpToNearest: MatchJump::ToNearest);
        $this->invalidate();
    }

    /** Flip whole-word matching and re-search (Alt+W, or a click on the [\b] tag). */
    private function toggleWholeWord(): void
    {
        $this->wholeWord = !$this->wholeWord;
        $this->recompute(jumpToNearest: MatchJump::ToNearest);
        $this->invalidate();
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
            'toggle_case' => ['alt+c'],
            'replace_all' => ['alt+a'],
            'delete_char_backward' => [Key::BACKSPACE],
            // The same chords the find-in-files modal recalls with, because it
            // is the same history.
            'history_prev' => ['alt+up'],
            'history_next' => ['alt+down'],
        ];
    }
}
