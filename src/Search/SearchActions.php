<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Container\AsIdeService;
use Porta\Editor\ActiveFileProvider;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\I18n\Translator;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\ResultsPanelMode;
use Porta\Workbench\Notifier;

/**
 * The search entry points behind the commands and tree context menus:
 * project text search, symbol search, folder-scoped find/replace, the
 * Problems list and the class↔test jump.
 */
#[AsIdeService]
final class SearchActions
{
    public function __construct(
        private readonly BottomDock $panels,
        private readonly ProjectSearchController $projectSearch,
        private readonly TestNavigator $testNavigator,
        private readonly ProblemRows $problemRows,
        private readonly BufferService $bufferService,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly ActiveFileProvider $editor,
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

    /** Every open buffer's diagnostics as one sorted, scannable list (kept live by {@see \Porta\Workbench\Dock\DockLiveRefreshListener}). */
    public function showProblems(): void
    {
        $this->panels->showList(ResultsPanelMode::Problems, $this->translator->trans('Problems'), $this->problemRows->build());
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
