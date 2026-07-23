<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Editor\BufferManager;
use Porta\History\SearchHistoryStore;
use Porta\History\SearchPrefsStore;
use Porta\History\SearchScopeStore;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Support\ProjectPaths;
use Porta\Ui\Dock\BottomDock;
use Porta\Ui\Dock\DockToolTab;
use Porta\Ui\Dock\ResultsPanelMode;
use Porta\Ui\ScopeKind;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ResultsPanelFields;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Tui\Tui;

/**
 * Project-wide text search and replace: the live query, its options, the last
 * result set, and the actions that drive the shared results panel. Owns that
 * state and orchestrates the async {@see ProjectSearch} engine so the IDE
 * coordinator only forwards panel callbacks here.
 */
final class ProjectSearchController
{
    /** The built-in scope kinds, in Alt+S cycle order (saved scopes follow them). */
    private const array BUILT_INS = [ScopeKind::Project, ScopeKind::Directory, ScopeKind::CurrentFile, ScopeKind::OpenFiles, ScopeKind::Module];

    private SearchOptions $searchOptions;
    private string $lastSearchQuery = '';
    private ScopeKind $scopeKind = ScopeKind::Project;
    /** @var list<ScopeChoice> the Alt+S choices (built-in kinds + saved scopes), rebuilt on open */
    private array $scopeChoices = [];
    private int $scopeIndex = 0;
    /** @var list<string> grep path(s) relative to the project root; `['.']` is the whole project. */
    private array $scope = ['.'];
    /** file mask (e.g. `*.php`, `*.{php,js}`); empty searches all files. */
    private string $mask = '';
    /** @var list<array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>}> */
    private array $lastSearchResults = [];

    /** True while showResultsPane binds its own tab — suppresses the adopt-on-bind hook. */
    private bool $promoting = false;

    public function __construct(
        private readonly ProjectSearch $projectSearch,
        private readonly BottomDock $dock,
        private readonly CodeEditorWidget $editor,
        private readonly StatusBarWidget $statusBar,
        private readonly Tui $tui,
        private readonly Translator $translator,
        private readonly BufferManager $buffers,
        private readonly string $rootDir,
        private readonly LspSessionPool $lsp,
        private readonly SearchHistoryStore $history,
        private readonly SearchPrefsStore $prefs,
        private readonly SearchScopeResolver $scopes,
        private readonly LanguageFilter $languageFilter,
        private readonly SearchScopeStore $scopeStore,
    ) {
        $this->searchOptions = new SearchOptions();
    }

    /** Open the Find-in-Files modal with a fresh query, restoring the last scope + mask. */
    public function open(): void
    {
        $this->rebuildScopeChoices();
        $this->scopeKind = $this->prefs->lastScope();
        $this->scopeIndex = (int) array_search($this->scopeKind, self::BUILT_INS, strict: true);
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
        $modal = $this->editor->findModal();
        $modal->open($this->translator->trans('Find in Files'));
        $modal->setScopes($this->scopeLabels(), $this->scopeIndex);
        $modal->setOptions($this->searchOptions->panelState());
        $modal->setMaskValue($this->mask);
        $modal->setHistory($this->history->all());
        $this->tui->requestRender();
    }

    /**
     * "Open in Find Window": hand the current results off to the persistent
     * results tool window (the shared pane, in full find-in-project mode), seeded
     * from the modal's query/scope/mask/options, and focus it.
     */
    public function promoteToPane(): void
    {
        $this->editor->findModal()->close();
        $this->showResultsPane();
    }

    /** Open (or reuse, keyed by the query title) this search's dock tab with the current results. */
    private function showResultsPane(): void
    {
        $title = '' === trim($this->lastSearchQuery)
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

    /** A Search tab was ×'d: record its query/mask like a panel close used to, and drop any in-flight run. */
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
        foreach ($this->scopeStore->all() as $saved) {
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
        return array_map(fn (ScopeChoice $choice): string => null !== $choice->kind ? $this->translator->trans($choice->label) : $choice->label, $this->scopeChoices);
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
        $relative = ProjectPaths::relative($this->rootDir, $absDir);
        if ('' === $relative || str_starts_with($relative, '/')) {
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
        $labels = array_map(static fn (ScopeChoice $choice): ?string => null === $choice->kind ? $choice->label : null, $this->scopeChoices);
        $index = array_search($label, $labels, strict: true);

        return false === $index ? 0 : $index;
    }

    /**
     * Save a folder as a **pinned** search scope (tree "Add to Search Scopes"),
     * so it stays available in the Alt+S cycle. A folder resolving to the root
     * (or outside it) is ignored.
     */
    public function addSearchScope(string $absDir): void
    {
        $relative = ProjectPaths::relative($this->rootDir, $absDir);
        if ('' === $relative || str_starts_with($relative, '/')) {
            return;
        }
        $this->scopeStore->addPinned($relative, [$relative]);
        $this->statusBar->flash($this->translator->trans('Added %dir% to search scopes', ['%dir%' => $relative]));
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
        $this->run($this->lastSearchQuery);
    }

    /** Re-run the current query (F5) with the current scope/mask/options — picks up on-disk changes. */
    public function rerun(): void
    {
        $this->run($this->lastSearchQuery);
    }

    /** Toggle a search option (from the modal), reflect it there, and re-run. */
    public function toggleOption(string $name): void
    {
        $this->searchOptions = $this->searchOptions->toggled($name);
        $this->editor->findModal()->setOptions($this->searchOptions->panelState());
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
        // A multiline query's matches span lines, so the per-kept-line rewrite
        // can't apply — replace over each matched file's whole text instead
        // (no per-occurrence exclude for multiline queries).
        if (str_contains($this->lastSearchQuery, "\n")) {
            $this->replaceAllMultiline($replacement);

            return;
        }

        $excludedSet = array_fill_keys($excluded, value: true);
        /** @var array<string, list<int>> $keptLinesByPath */
        $keptLinesByPath = [];
        foreach ($this->lastSearchResults as $index => $result) {
            if (($excludedSet[$index] ?? null) !== null) {
                continue;
            }

            $keptLinesByPath[$result['path']][] = $result['line'];
        }

        if ('' === trim($this->lastSearchQuery) || [] === $keptLinesByPath) {
            $this->statusBar->flash($this->translator->trans('Nothing to replace'));
            $this->tui->requestRender();

            return;
        }

        $touched = 0;
        foreach ($keptLinesByPath as $relPath => $lineIndices) {
            $abs = $this->rootDir.'/'.$relPath;
            $buffer = $this->buffers->get($abs) ?? $this->buffers->get(($real = realpath($abs)) ? $real : $abs);
            if (null !== $buffer) {
                $old = $buffer->document->getText();
                $new = $this->replaceLines($old, $lineIndices, $replacement);
                if ($new !== $old) {
                    $buffer->document->replaceAll($new);
                    if ($buffer === $this->buffers->active()) {
                        $this->editor->invalidate();
                    }
                    $this->lsp->notifyFullChange($buffer->path, $buffer->document);
                    ++$touched;
                }

                continue;
            }
            if (is_file($abs) && is_writable($abs)) {
                $old = (string) file_get_contents($abs);
                $new = $this->replaceLines($old, $lineIndices, $replacement);
                if ($new !== $old) {
                    new Filesystem()->dumpFile($abs, $new);
                    ++$touched;
                }
            }
        }

        $this->finishReplace($touched);
    }

    /** Replace every match of a multiline query across matched files' whole text. */
    private function replaceAllMultiline(string $replacement): void
    {
        if ([] === $this->lastSearchResults) {
            $this->statusBar->flash($this->translator->trans('Nothing to replace'));
            $this->tui->requestRender();

            return;
        }

        $touched = 0;
        foreach (array_unique(array_column($this->lastSearchResults, 'path')) as $relPath) {
            $abs = $this->rootDir.'/'.$relPath;
            $buffer = $this->buffers->get($abs) ?? $this->buffers->get(($real = realpath($abs)) ? $real : $abs);
            if (null !== $buffer) {
                $old = $buffer->document->getText();
                $new = $this->projectSearch->applyReplacement($old, $this->lastSearchQuery, $replacement, $this->searchOptions);
                if ($new !== $old) {
                    $buffer->document->replaceAll($new);
                    if ($buffer === $this->buffers->active()) {
                        $this->editor->invalidate();
                    }
                    $this->lsp->notifyFullChange($buffer->path, $buffer->document);
                    ++$touched;
                }

                continue;
            }
            if (is_file($abs) && is_writable($abs)) {
                $old = (string) file_get_contents($abs);
                $new = $this->projectSearch->applyReplacement($old, $this->lastSearchQuery, $replacement, $this->searchOptions);
                if ($new !== $old) {
                    new Filesystem()->dumpFile($abs, $new);
                    ++$touched;
                }
            }
        }

        $this->finishReplace($touched);
    }

    /** Flash the touched-file count, close whichever surface drove the replace, and refocus the editor. */
    private function finishReplace(int $touched): void
    {
        $this->statusBar->flash(
            1 === $touched
                ? $this->translator->trans('Replaced in %count% file', ['%count%' => $touched])
                : $this->translator->trans('Replaced in %count% files', ['%count%' => $touched]),
        );
        $this->editor->findModal()->close(); // replace runs from the modal
        $this->tui->setFocus($this->editor);
        $this->tui->requestRender();
    }

    /**
     * Apply the replacement to only the given (0-based) line indices of
     * $content, leaving every other line — including excluded matches and
     * non-matching lines — byte-for-byte unchanged.
     *
     * @param list<int> $lineIndices
     */
    private function replaceLines(string $content, array $lineIndices, string $replacement): string
    {
        $lines = explode("\n", $content);
        foreach ($lineIndices as $index) {
            if (($lines[$index] ?? null) === null) {
                continue;
            }

            $lines[$index] = $this->projectSearch->applyReplacement($lines[$index], $this->lastSearchQuery, $replacement, $this->searchOptions);
        }

        return implode("\n", $lines);
    }

    /**
     * Write the current result set (grouped by file, `line: text`) to
     * `.porta/search-results.txt` and return its absolute path so the caller
     * can open it; returns '' (and flashes) when there is nothing to export.
     */
    public function exportResults(): string
    {
        if ([] === $this->lastSearchResults) {
            $this->statusBar->flash($this->translator->trans('Nothing to export'));
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

        $abs = $this->rootDir.'/.porta/search-results.txt';
        new Filesystem()->dumpFile($abs, implode("\n", $lines)."\n");
        $this->statusBar->flash($this->translator->trans('Exported %count% results', ['%count%' => \count($this->lastSearchResults)]));

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
                $modal = $this->editor->findModal();
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
