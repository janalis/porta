<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\I18n\Translator;
use Porta\Support\ProjectPaths;
use Porta\Ui\Notifier;
use Porta\Ui\PanelManager;
use Porta\Ui\ResultsPanelMode;
use Porta\Widget\CodeEditorWidget;

/**
 * The search entry points behind the commands and tree context menus:
 * project text search, symbol search, folder-scoped find/replace, the
 * Problems list and the class↔test jump.
 */
final class SearchActions
{
    public function __construct(
        private readonly PanelManager $panels,
        private readonly ProjectSearchController $projectSearch,
        private readonly TestNavigator $testNavigator,
        private readonly BufferManager $buffers,
        private readonly BufferService $bufferService,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly CodeEditorWidget $editor,
        private readonly string $rootDir,
    ) {}

    public function openProjectSearch(): void
    {
        if (!$this->bufferService->ensureTextEditorActive()) {
            return;
        }
        $this->panels->setMode(ResultsPanelMode::Search);
        $this->projectSearch->open();
    }

    public function openSymbolSearch(): void
    {
        $this->panels->openQuery(ResultsPanelMode::Symbol, 'Go to Symbol in Project');
    }

    /** Open the results panel as a text search scoped to a folder (tree "Find in Files…"). */
    public function openFindInFolder(string $absDir): void
    {
        $this->panels->setMode(ResultsPanelMode::Search);
        $this->projectSearch->openInFolder($absDir);
    }

    /** Open the same scoped panel titled for replacing (tree "Replace in Files…"). */
    public function openReplaceInFolder(string $absDir): void
    {
        $this->panels->setMode(ResultsPanelMode::Search);
        $this->projectSearch->replaceInFolder($absDir);
    }

    /** Every open buffer's diagnostics as one sorted, scannable list. */
    public function showProblems(): void
    {
        $results = [];
        foreach ($this->buffers->all() as $buffer) {
            $rel = ProjectPaths::relative($this->rootDir, $buffer->path);
            foreach ($buffer->diagnostics as $line => $list) {
                foreach ($list as $diag) {
                    $marker = 1 === $diag['severity'] ? '✗' : '▲';
                    $results[] = [
                        'path' => $rel,
                        'line' => $line,
                        'text' => $marker.' '.$diag['message'],
                    ];
                }
            }
        }

        // Sort by file then line for a stable, scannable list.
        usort($results, static fn (array $a, array $b) => [$a['path'], $a['line']] <=> [$b['path'], $b['line']]);

        $this->panels->showList(ResultsPanelMode::Problems, 'Problems', $results);
    }

    /** PhpStorm-style Ctrl+Shift+T: jump from a class to its test file, or back. */
    public function goToTest(): void
    {
        $path = $this->editor->getFilePath();
        $candidates = null === $path ? [] : $this->testNavigator->locate($path);
        if ([] === $candidates) {
            $missing = null !== $path && $this->testNavigator->isTest($path) ? 'No test subject found' : 'No test found';
            $this->notifier->flash($this->translator->trans($missing));

            return;
        }
        if (1 === \count($candidates)) {
            $this->bufferService->openFile($this->rootDir.'/'.$candidates[0], focus: OpenFocus::Editor);

            return;
        }
        $rows = array_map(static fn (string $rel): array => ['path' => $rel, 'line' => 0, 'text' => $rel], $candidates);
        $this->panels->showList(ResultsPanelMode::Search, $this->translator->trans('Choose target'), $rows);
    }
}
