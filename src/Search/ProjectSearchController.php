<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\I18n\Translator;
use Porta\Editor\BufferManager;
use Porta\Editor\Document;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ResultsPanelWidget;
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
    /** @var array{regex: bool, case: bool, word: bool} */
    private array $searchOptions = ['regex' => false, 'case' => false, 'word' => false];
    private string $lastSearchQuery = '';
    /** @var list<array{path: string, line: int, text: string}> */
    private array $lastSearchResults = [];

    /**
     * @param \Closure(string, Document): void $notifyFullChange re-sync a buffer's full text to the LSP
     */
    public function __construct(
        private readonly ProjectSearch $projectSearch,
        private readonly ResultsPanelWidget $resultsPanel,
        private readonly CodeEditorWidget $editor,
        private readonly StatusBarWidget $statusBar,
        private readonly Tui $tui,
        private readonly Translator $translator,
        private readonly BufferManager $buffers,
        private readonly string $rootDir,
        private readonly \Closure $notifyFullChange,
    ) {}

    /** Open the panel in find-in-project mode with a fresh query and options. */
    public function open(): void
    {
        $this->searchOptions = ['regex' => false, 'case' => false, 'word' => false];
        $this->lastSearchQuery = '';
        $this->lastSearchResults = [];
        $this->resultsPanel->open($this->translator->trans('Find in Project'), withQuery: true, withOptions: true, withReplace: true);
        $this->resultsPanel->setOptions($this->searchOptions);
        $this->tui->setFocus($this->resultsPanel);
        $this->tui->requestRender();
    }

    /** Run a new query (from the debounced panel input). */
    public function search(string $query): void
    {
        $this->lastSearchQuery = $query;
        $this->run($query);
    }

    /** Toggle a search option and re-run the last query with it applied. */
    public function toggleOption(string $name): void
    {
        /** @var 'case'|'regex'|'word' $name */
        $this->searchOptions[$name] = !$this->searchOptions[$name];
        $this->resultsPanel->setOptions($this->searchOptions);
        $this->run($this->lastSearchQuery);
        $this->tui->requestRender();
    }

    /** Cancel any in-flight search (panel closed). */
    public function cancel(): void
    {
        $this->projectSearch->cancel();
    }

    /**
     * Replace every match of the last project search with $replacement across
     * matched files — open buffers as one undoable edit, others on disk.
     */
    public function replaceAll(string $replacement): void
    {
        if ('' === trim($this->lastSearchQuery) || [] === $this->lastSearchResults) {
            $this->statusBar->flash($this->translator->trans('Nothing to replace'));
            $this->tui->requestRender();

            return;
        }

        $touched = 0;
        foreach (array_unique(array_column($this->lastSearchResults, 'path')) as $relPath) {
            $abs = $this->rootDir.'/'.$relPath;
            $buffer = $this->buffers->get($abs) ?? $this->buffers->get(realpath($abs) ?: $abs);
            if (null !== $buffer) {
                $old = $buffer->document->getText();
                $new = $this->replaceInText($old, $replacement);
                if ($new !== $old) {
                    $buffer->document->replaceAll($new);
                    if ($buffer === $this->buffers->active()) {
                        $this->editor->invalidate();
                    }
                    ($this->notifyFullChange)($buffer->path, $buffer->document);
                    ++$touched;
                }
            } elseif (is_file($abs) && is_writable($abs)) {
                $old = (string) file_get_contents($abs);
                $new = $this->replaceInText($old, $replacement);
                if ($new !== $old) {
                    new Filesystem()->dumpFile($abs, $new);
                    ++$touched;
                }
            }
        }

        $this->statusBar->flash(1 === $touched
            ? $this->translator->trans('Replaced in %count% file', ['%count%' => $touched])
            : $this->translator->trans('Replaced in %count% files', ['%count%' => $touched]));
        $this->resultsPanel->close();
        $this->tui->setFocus($this->editor);
        $this->tui->requestRender();
    }

    private function replaceInText(string $content, string $replacement): string
    {
        return $this->projectSearch->applyReplacement(
            $content,
            $this->lastSearchQuery,
            $replacement,
            $this->searchOptions['regex'],
            $this->searchOptions['case'],
            $this->searchOptions['word'],
        );
    }

    private function run(string $query): void
    {
        $this->projectSearch->search(
            $query,
            function (array $results): void {
                $this->lastSearchResults = $results;
                if ($this->resultsPanel->isOpen()) {
                    $this->resultsPanel->setResults($results);
                    $this->tui->requestRender();
                }
            },
            $this->searchOptions['regex'],
            $this->searchOptions['case'],
            $this->searchOptions['word'],
        );
    }
}
