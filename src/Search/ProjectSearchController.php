<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Editor\BufferManager;
use Porta\Editor\DocumentReplacer;
use Porta\Filesystem\LocalFilesystem;
use Porta\Filesystem\ProjectFilesystem;
use Porta\History\SearchHistoryStore;
use Porta\History\SearchPrefsStore;
use Porta\History\SearchScopeStore;
use Porta\I18n\Translator;
use Porta\Support\Files;
use Porta\Support\ProjectPaths;
use Porta\Ui\ScopeKind;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\Support\ResultsPanelFields;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\DockToolTab;
use Porta\Workbench\Dock\ResultsPanelMode;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Tui;

/**
 * Project-wide text search and replace: the live query, its options, the last
 * result set, and the actions that drive the shared results panel. Owns that
 * state and orchestrates the async {@see ProjectSearch} engine so the IDE
 * coordinator only forwards panel callbacks here.
 *
 * @phpstan-import-type ResultRow from \Porta\Support\ResultRowShape
 */
final class ProjectSearchController
{
    /** The built-in scope kinds, in Alt+S cycle order (saved scopes follow them). */
    private const array BUILT_INS = [ScopeKind::Project, ScopeKind::Directory, ScopeKind::CurrentFile, ScopeKind::OpenFiles, ScopeKind::Module];

    private SearchOptions $searchOptions;

    /** Whether an empty-replacement replace-all has been asked for once and awaits its confirming second ask. */
    private bool $armedEmptyReplace = false;
    private string $lastSearchQuery = '';
    private ScopeKind $scopeKind = ScopeKind::Project;
    /** @var list<ScopeChoice> the Alt+S choices (built-in kinds + saved scopes), rebuilt on open */
    private array $scopeChoices = [];
    private int $scopeIndex = 0;
    /** @var list<string> grep path(s) relative to the project root; `['.']` is the whole project. */
    private array $scope = ['.'];
    /** file mask (e.g. `*.php`, `*.{php,js}`); empty searches all files. */
    private string $mask = '';
    /** @var list<ResultRow> */
    private array $lastSearchResults = [];

    /** Which try at a replace is the current one — {@see fetchedForReplace()}. */
    private int $replaceGeneration = 0;

    /** True while showResultsPane binds its own tab — suppresses the adopt-on-bind hook. */
    private bool $promoting = false;

    public function __construct(
        private readonly ProjectSearch $projectSearch,
        private readonly BottomDock $dock,
        private readonly CodeEditorWidget $editor,
        private readonly Notifier $notifier,
        private readonly Tui $tui,
        private readonly Translator $translator,
        private readonly BufferManager $buffers,
        private readonly ProjectPaths $paths,
        private readonly DocumentReplacer $replacer,
        private readonly SearchHistoryStore $history,
        private readonly SearchPrefsStore $prefs,
        private readonly SearchScopeResolver $scopes,
        private readonly LanguageFilter $languageFilter,
        private readonly SearchScopeStore $scopeStore,
        private readonly ProjectFilesystem $fs = new LocalFilesystem(),
    ) {
        $this->searchOptions = new SearchOptions();
    }

    /** Open the Find-in-Files modal with a fresh query, restoring the last scope + mask. */
    public function open(): void
    {
        $this->rebuildScopeChoices();
        $this->scopeKind = $this->prefs->lastScope();
        $this->scopeIndex = (int) \array_search($this->scopeKind, self::BUILT_INS, strict: true);
        // A saved scope wins when it is still there. Gone — renamed, deleted —
        // and the built-in kind above stands, which is why it is still recorded.
        $saved = $this->prefs->lastSavedScope();
        if ('' !== $saved) {
            foreach ($this->scopeChoices as $index => $choice) {
                if (null !== $choice->kind || $choice->label !== $saved) {
                    continue;
                }

                $this->scopeIndex = $index;
                break;
            }
        }
        $this->scope = $this->resolveChoice($this->scopeChoices[$this->scopeIndex]);
        $this->openModal();
    }

    /** Reset the query/options/mask/results and open the modal seeded from the current config. */
    private function openModal(): void
    {
        $this->searchOptions = new SearchOptions();
        $this->lastSearchQuery = '';
        $this->mask = $this->prefs->lastMask();
        $this->lastSearchResults = [];
        $modal = $this->editor->findModal;
        $modal->open($this->translator->trans('Find in Files'));
        $modal->setScopes($this->scopeLabels(), $this->scopeIndex);
        $modal->setOptions($this->searchOptions->panelState());
        $modal->setMaskValue($this->mask);
        $modal->setHistory($this->history->queries);
        $this->tui->requestRender();
    }

    /**
     * "Open in Find Window": hand the current results off to the persistent
     * results tool window (the shared pane, in full find-in-project mode), seeded
     * from the modal's query/scope/mask/options, and focus it.
     */
    public function promoteToPane(): void
    {
        $this->editor->findModal->close();
        $this->showResultsPane();
    }

    /** Open (or reuse, keyed by the query title) this search's dock tab with the current results. */
    private function showResultsPane(): void
    {
        $title = '' === \trim($this->lastSearchQuery)
            ? $this->translator->trans('Find in Project')
            : $this->translator->trans('Find in Project: %query%', ['%query%' => $this->lastSearchQuery]);
        // The bind hook re-adopts a tab's frozen state — suppressed while WE
        // are promoting, or a same-title re-promote with a changed mask or
        // options would rehydrate the stale definition over the new one.
        $this->promoting = true;
        $tab = $this->dock->showList(ResultsPanelMode::Search, $title, $this->lastSearchResults, ResultsPanelFields::searchResults());
        $this->promoting = false;
        $tab->searchState = new SearchTabState($this->lastSearchQuery, $this->searchOptions, $this->scope, $this->mask, $this->scopeKind);
    }

    /**
     * A Search tab took the display: rehydrate its frozen definition so
     * F5/replace/export act on the tab's own query. Fresh tabs (no state
     * yet) and foreign tools are ignored.
     */
    public function adopt(DockToolTab $tab): void
    {
        $state = $tab->searchState;
        if ($this->promoting || null === $state) {
            return;
        }
        $this->lastSearchQuery = $state->query;
        $this->searchOptions = $state->options;
        $this->scope = $state->scope;
        $this->mask = $state->mask;
        $this->scopeKind = $state->scopeKind;
        $this->lastSearchResults = $tab->rows;
    }

    /** A Search tab was ✖'d: record its query/mask like a panel close used to, and drop any in-flight run. */
    public function closeSearch(SearchTabState $state): void
    {
        $this->history->add($state->query);
        $this->prefs->addRecentMask($state->mask);
        $this->projectSearch->cancel();
    }

    /** Rebuild the Alt+S choices: the built-in kinds followed by the saved scopes. */
    private function rebuildScopeChoices(): void
    {
        $choices = [];
        foreach (self::BUILT_INS as $kind) {
            $choices[] = ScopeChoice::builtin($kind);
        }
        foreach ($this->scopeStore->scopes as $saved) {
            $choices[] = ScopeChoice::saved($saved['label'], $saved['paths']);
        }
        $this->scopeChoices = $choices;
    }

    /** @return list<string> */
    private function resolveChoice(ScopeChoice $choice): array
    {
        return null !== $choice->kind ? $this->scopes->resolve($choice->kind) : $choice->paths ?? ['.'];
    }

    /** @return list<string> the choice labels shown in the panel's Alt+S selector */
    private function scopeLabels(): array
    {
        return \array_map(fn (ScopeChoice $choice): string => null !== $choice->kind ? $this->qualified($choice->kind) : $choice->label, $this->scopeChoices);
    }

    /**
     * A built-in kind's label, saying where it actually lands right now.
     *
     * `Module` and `Directory` are relative to the file you happen to be in,
     * so the same word means a different search from one buffer to the next —
     * and `Module` has a fallback that means the OPPOSITE of what it promises:
     * with no composer.json above the file it is the whole project. In a repo
     * whose packages live under `plugins/`, editing anything in `src/` gets
     * that fallback, and nothing on screen said so.
     *
     * `Open Files` gets its count for the same reason in reverse: with nothing
     * open it matches nothing at all, and a search that returns no results
     * because its scope was empty looks exactly like one that found none.
     */
    private function qualified(ScopeKind $kind): string
    {
        $label = $this->translator->trans($kind->label());
        // Project and Current File name themselves exactly; anything more
        // would be repeating the label back at the user.
        $named = ScopeKind::Project === $kind || ScopeKind::CurrentFile === $kind;
        $paths = $named ? [] : $this->scopes->resolve($kind);

        return match (true) {
            $named => $label,
            ScopeKind::OpenFiles === $kind => $label.' ('.\count($paths).')',
            default => $label.' ('.(['.'] === $paths ? $this->translator->trans('Whole Project') : \implode(', ', $paths)).')',
        };
    }

    /**
     * Open the modal scoped to a single folder for searching (tree "Find in
     * Files…"). Results stay project-root-relative — only grep's search path
     * narrows — so jump and replace-all keep working unchanged.
     */
    public function openInFolder(string $absDir): void
    {
        $this->scopedOpen($absDir);
    }

    /** Open the same scoped modal (tree "Replace in Files…"); replace runs from the promoted pane. */
    public function replaceInFolder(string $absDir): void
    {
        $this->scopedOpen($absDir);
    }

    /**
     * Open the modal scoped to a folder: remember it as a recent scope and
     * preselect it in the Alt+S cycle. A folder resolving to the root itself
     * (or outside it) falls back to a whole-project search.
     */
    private function scopedOpen(string $absDir): void
    {
        $relative = $this->paths->relative($absDir);
        if ('' === $relative || \str_starts_with($relative, '/')) {
            $this->open();

            return;
        }
        $this->scopeStore->addRecent($relative, [$relative]);
        $this->rebuildScopeChoices();
        $this->scopeIndex = $this->indexOfSaved($relative);
        $this->scope = [$relative];
        $this->openModal();
    }

    /** The choice index of the saved scope labelled $label (falls back to the whole project). */
    private function indexOfSaved(string $label): int
    {
        $labels = \array_map(static fn (ScopeChoice $choice): ?string => null === $choice->kind ? $choice->label : null, $this->scopeChoices);
        $index = \array_search($label, $labels, strict: true);

        return false === $index ? 0 : $index;
    }

    /**
     * Save a folder as a **pinned** search scope (tree "Add to Search Scopes"),
     * so it stays available in the Alt+S cycle. A folder resolving to the root
     * (or outside it) is ignored.
     */
    public function addSearchScope(string $absDir): void
    {
        $relative = $this->paths->relative($absDir);
        if ('' === $relative || \str_starts_with($relative, '/')) {
            return;
        }
        $this->scopeStore->addPinned($relative, [$relative]);
        $this->notifier->flash($this->translator->trans('Added %dir% to search scopes', ['%dir%' => $relative]));
        $this->tui->requestRender();
    }

    /** Run a new query (from the debounced modal/panel input). */
    public function search(string $query): void
    {
        $this->lastSearchQuery = $query;
        $this->run($query);
    }

    /** Apply a file mask (from the panel's Mask field), persist it, and re-run the last query. */
    public function setMask(string $mask): void
    {
        $this->mask = $mask;
        $this->prefs->setMask($mask);
        $this->run($this->lastSearchQuery);
    }

    /** Select a scope choice (from the panel's Alt+S selector), persist built-ins, resolve, and re-run. */
    public function setScopeIndex(int $index): void
    {
        $this->scopeIndex = $index;
        $choice = $this->scopeChoices[$index] ?? $this->scopeChoices[0];
        $this->scope = $this->resolveChoice($choice);
        if (null !== $choice->kind) {
            $this->scopeKind = $choice->kind;
            $this->prefs->setScope($choice->kind);
        }
        if (null === $choice->kind) {
            // A SAVED scope carries paths instead of a kind, so the kind field
            // cannot hold it — and while only kinds were persisted, the one
            // scope a user had gone to the trouble of saving and pinning was
            // the only one a reopened panel could not restore. The previous
            // KIND is deliberately left untouched as the fallback for when this
            // saved scope is later deleted.
            $this->prefs->setSavedScope($choice->label);
        }
        $this->run($this->lastSearchQuery);
    }

    /** Re-run the current query (F5) with the current scope/mask/options — picks up on-disk changes. */
    public function rerun(): void
    {
        $this->run($this->lastSearchQuery);
    }

    /** Toggle a search option (from the modal), reflect it there, and re-run. */
    public function toggleOption(SearchOption $option): void
    {
        $this->searchOptions = $this->searchOptions->toggled($option);
        $this->editor->findModal->setOptions($this->searchOptions->panelState());
        $this->run($this->lastSearchQuery);
        $this->tui->requestRender();
    }

    /** Cancel any in-flight search and record the committed query + mask (panel closed). */
    public function cancel(): void
    {
        $this->history->add($this->lastSearchQuery);
        $this->prefs->addRecentMask($this->mask);
        $this->projectSearch->cancel();
    }

    /**
     * Preview a single match line's replacement (for the panel's inline
     * `old → new` display). Uses the same matching semantics as replace-all.
     */
    public function previewLine(string $text, string $replacement): string
    {
        return $this->projectSearch->applyReplacement($text, $this->lastSearchQuery, $replacement, $this->searchOptions);
    }

    /**
     * Replace the kept matches of the last project search with $replacement
     * across matched files — open buffers as one undoable edit, others on disk.
     * Excluded matches (by index into the last result set) are left untouched;
     * to keep those lines intact, only the kept matches' own lines are rewritten
     * rather than running the pattern over the whole file.
     *
     * @param list<int> $excluded match indices to skip
     */
    public function replaceAll(string $replacement, array $excluded = []): void
    {
        if (!$this->fetchedForReplace($replacement, $excluded)) {
            return;
        }
        // A multiline query's matches span lines, so the per-kept-line rewrite
        // can't apply — replace over each matched file's whole text instead
        // (no per-occurrence exclude for multiline queries).
        \str_contains($this->lastSearchQuery, "\n") ? $this->replaceAllMultiline($replacement) : $this->replaceKeptLines($replacement, $excluded);
    }

    /**
     * {@see replaceAll()} for a single-line query: only the kept matches' own
     * lines are rewritten.
     *
     * @param list<int> $excluded match indices to skip
     */
    private function replaceKeptLines(string $replacement, array $excluded): void
    {
        $excludedSet = \array_fill_keys($excluded, value: true);
        /** @var array<string, list<int>> $keptLinesByPath */
        $keptLinesByPath = [];
        foreach ($this->lastSearchResults as $index => $result) {
            if (null !== ($excludedSet[$index] ?? null)) {
                continue;
            }

            $keptLinesByPath[$result['path']][] = $result['line'];
        }

        if ([] === $keptLinesByPath || '' === \trim($this->lastSearchQuery)) {
            $this->notifier->flash($this->translator->trans('Nothing to replace'));
            $this->tui->requestRender();

            return;
        }
        // AFTER the check above, not before it: with no query and no results
        // there is nothing to destroy, and offering to confirm the deletion of
        // nothing is worse than the silence it replaced. "Nothing to replace"
        // wins, and this only speaks when there is something to lose.
        //
        // An EMPTY replacement DELETES every match, across open buffers and on
        // disk, and the modal closes afterwards. It stays available — refusing
        // would make a real verb a dead key — but it is the one replace-all
        // nothing on that screen can protect you from: the per-match exclude
        // checkboxes render only once a replacement is non-empty, and the
        // footer advertises Alt+A before anything has been typed. So it
        // confirms, the way quitting with unsaved buffers does, and the confirm
        // lives beside the code that deletes rather than in the modal that asks.
        if ('' === $replacement && !$this->armedEmptyReplace) {
            $this->armedEmptyReplace = true;
            $this->notifier->warn($this->translator->trans('Alt+A again to DELETE every match — the replacement is empty'));
            $this->tui->requestRender();

            return;
        }
        $this->armedEmptyReplace = false;

        $touched = 0;
        foreach ($keptLinesByPath as $relPath => $lineIndices) {
            // Narrowed once per FILE, not per line: it classifies the whole
            // content, and a replace over a file touches many lines of it.
            $approved = $this->languageFilter->approvedSpans($relPath, $this->lastSearchQuery, $this->searchOptions);
            $abs = $this->paths->absolute($relPath);
            $buffer = $this->buffers->get($abs) ?? $this->buffers->get(($real = \realpath($abs)) ? $real : $abs);
            if (null !== $buffer) {
                $old = $buffer->document->getText();
                $new = $this->replaceLines($old, $lineIndices, $replacement, $approved);
                if ($this->replacer->replace($buffer, $new)) {
                    ++$touched;
                }

                continue;
            }
            $old = Files::readWritable($abs);
            if (null !== $old) {
                $new = $this->replaceLines($old, $lineIndices, $replacement, $approved);
                if ($new !== $old) {
                    $this->fs->dumpFile($abs, $new);
                    ++$touched;
                }
            }
        }

        $this->finishReplace($touched);
    }

    /**
     * Whether every file the replace rewrites on disk is here to read. A
     * remote project's search runs on the server, so it finds files whose
     * content was never fetched — their placeholder is empty, and a replace
     * over it would skip them without a word. Those are fetched first, and
     * the replace runs again once the last one lands.
     *
     * Each try is a generation, and only the newest may go on. A fetch that
     * answers at once runs its retry inside this very loop, which replaces:
     * the outer call must then neither replace again nor say it is still
     * fetching, over the toast that says what was replaced.
     *
     * @param list<int> $excluded
     */
    private function fetchedForReplace(string $replacement, array $excluded): bool
    {
        $generation = ++$this->replaceGeneration;
        $paths = [];
        foreach (\array_unique(\array_column($this->lastSearchResults, 'path')) as $relPath) {
            $abs = $this->paths->absolute($relPath);
            if (null === ($this->buffers->get($abs) ?? $this->buffers->get(($real = \realpath($abs)) ? $real : $abs))) {
                $paths[] = $abs;
            }
        }
        // Each landing asks again for all of them — with itself, since the
        // latest ask for a path is the one its fetch answers — and the last
        // to land finds them all here.
        $again = function () use ($replacement, $excluded, $generation, $paths, &$again): void {
            if ($generation === $this->replaceGeneration && \array_all($paths, fn (string $path): bool => $this->fs->available($path, $again))) {
                $this->replaceAll($replacement, $excluded);
            }
        };
        $missing = \count(\array_filter($paths, fn (string $path): bool => !$this->fs->available($path, $again)));
        // Superseded: a fetch that answered at once has run the replace, and said so.
        $current = $generation === $this->replaceGeneration;
        if ($current && 0 !== $missing) {
            $this->notifier->flash(
                $this->translator->trans('Fetching %count% file from the server — the replace runs once it is here|Fetching %count% files from the server — the replace runs once they are here', [
                    '%count%' => $missing,
                ]),
                4.0,
            );
        }

        return $current && 0 === $missing;
    }

    /** Replace every match of a multiline query across matched files' whole text. */
    private function replaceAllMultiline(string $replacement): void
    {
        if ([] === $this->lastSearchResults) {
            $this->notifier->flash($this->translator->trans('Nothing to replace'));
            $this->tui->requestRender();

            return;
        }

        $touched = 0;
        foreach (\array_unique(\array_column($this->lastSearchResults, 'path')) as $relPath) {
            $abs = $this->paths->absolute($relPath);
            $buffer = $this->buffers->get($abs) ?? $this->buffers->get(($real = \realpath($abs)) ? $real : $abs);
            if (null !== $buffer) {
                $old = $buffer->document->getText();
                $new = $this->projectSearch->applyReplacement($old, $this->lastSearchQuery, $replacement, $this->searchOptions);
                if ($this->replacer->replace($buffer, $new)) {
                    ++$touched;
                }

                continue;
            }
            $old = Files::readWritable($abs);
            if (null !== $old) {
                $new = $this->projectSearch->applyReplacement($old, $this->lastSearchQuery, $replacement, $this->searchOptions);
                if ($new !== $old) {
                    $this->fs->dumpFile($abs, $new);
                    ++$touched;
                }
            }
        }

        $this->finishReplace($touched);
    }

    /** Flash the touched-file count, close whichever surface drove the replace, and refocus the editor. */
    private function finishReplace(int $touched): void
    {
        $this->notifier->flash($this->translator->trans('Replaced in %count% file|Replaced in %count% files', ['%count%' => $touched]));
        $this->editor->findModal->close(); // replace runs from the modal
        $this->tui->setFocus($this->editor);
        $this->tui->requestRender();
    }

    /**
     * Apply the replacement to only the given (0-based) line indices of
     * $content, leaving every other line — including excluded matches and
     * non-matching lines — byte-for-byte unchanged.
     *
     * And, when the token scope narrows them, to only the OCCURRENCES it
     * approves within those lines. A line is kept when at least one occurrence
     * falls in the requested token class, so rewriting the whole line rewrote
     * the rest too: `$foo = 'foo';` under "in strings" had its variable renamed
     * by a replace that existed to protect it.
     *
     * @param list<int>                                             $lineIndices
     * @param array<int, list<array{start: int, length: int}>>|null $approved    the spans the token
     *                                                                           scope allows, or null
     *                                                                           when it narrows nothing
     */
    private function replaceLines(string $content, array $lineIndices, string $replacement, ?array $approved = null): string
    {
        $lines = \explode("\n", $content);
        foreach ($lineIndices as $index) {
            if (null === ($lines[$index] ?? null)) {
                continue;
            }
            if (null !== $approved) {
                $lines[$index] = self::spliceSpans($lines[$index], $approved[$index] ?? [], $replacement);

                continue;
            }

            $lines[$index] = $this->projectSearch->applyReplacement($lines[$index], $this->lastSearchQuery, $replacement, $this->searchOptions);
        }

        return \implode("\n", $lines);
    }

    /**
     * Rewrite only $spans of $line, right-to-left so the earlier offsets stay
     * valid as the string changes length under them.
     *
     * @param list<array{start: int, length: int}> $spans
     */
    private static function spliceSpans(string $line, array $spans, string $replacement): string
    {
        \usort($spans, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($spans as $span) {
            // max(0,…) on both: the analyzer cannot see that a match offset and
            // a match length are non-negative by construction, and a negative
            // either way would splice from the END of the line.
            $line = \substr_replace($line, $replacement, \max(0, $span['start']), \max(0, $span['length']));
        }

        return $line;
    }

    /**
     * Write the current result set (grouped by file, `line: text`) to
     * `.porta/search-results.txt` and return its absolute path so the caller
     * can open it; returns '' (and flashes) when there is nothing to export.
     */
    public function exportResults(): string
    {
        if ([] === $this->lastSearchResults) {
            $this->notifier->flash($this->translator->trans('Nothing to export'));
            $this->tui->requestRender();

            return '';
        }

        $lines = [\sprintf('Search: %s   (%d results)', $this->lastSearchQuery, \count($this->lastSearchResults)), ''];
        $currentPath = null;
        foreach ($this->lastSearchResults as $result) {
            if ($result['path'] !== $currentPath) {
                if (null !== $currentPath) {
                    $lines[] = '';
                }
                $currentPath = $result['path'];
                $lines[] = $currentPath;
            }
            $lines[] = \sprintf('  %d: %s', $result['line'] + 1, $result['text']);
        }

        $abs = $this->paths->absolute('.porta/search-results.txt');
        $this->fs->dumpFile($abs, \implode("\n", $lines)."\n");
        $this->notifier->flash($this->translator->trans('Exported %count% result|Exported %count% results', ['%count%' => \count($this->lastSearchResults)]));

        return $abs;
    }

    private function run(string $query): void
    {
        // A completion landing after the user switched tabs updates the
        // captured tab's model only — never whichever tool is now on display.
        $tab = $this->paneTab();
        $this->projectSearch->search(
            $query,
            function (array $results) use ($query, $tab): void {
                // A language-aware token scope narrows the grep hits to (or away
                // from) comments/strings before they reach whichever surface is live.
                $results = $this->languageFilter->apply($results, $query, $this->searchOptions);
                $this->lastSearchResults = $results;
                // PCRE reports "I gave up" the same way it reports "nothing
                // here". Without this the user reads a short result list as the
                // answer, and a handful of skipped files among thousands is
                // invisible.
                if ($this->projectSearch->gaveUp()) {
                    $this->notifier->warn($this->translator->trans('pattern too complex'));
                }
                $modal = $this->editor->findModal;
                if ($modal->isOpen()) {
                    $modal->setPreview($results);
                    $this->tui->requestRender();

                    return;
                }
                if (null !== $tab && $this->dock->isToolAlive($tab)) {
                    $tab->rows = $results;
                    $this->dock->refreshIfBound($tab);
                }
            },
            $this->searchOptions,
            $this->scope,
            $this->mask,
        );
    }

    /** The find-in-project tab currently on display (the pane a completion should land in), or null. */
    private function paneTab(): ?DockToolTab
    {
        $tool = $this->dock->activeTool();
        if (null === $tool || ResultsPanelMode::Search !== $tool->mode) {
            return null;
        }

        // A fresh promote assigns the state right after showList; during that
        // window the active Search tab without state is still ours.
        return $this->promoting || null !== $tool->searchState ? $tool : null;
    }
}
