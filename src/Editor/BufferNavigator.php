<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\History\RecentFilesStore;
use Porta\I18n\Translator;
use Porta\Search\Everywhere\SearchEverywhereController;
use Porta\Support\ProjectPaths;
use Porta\Ui\Glyphs;
use Porta\Widget\BufferNavigationHandler;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\ViewerTabHandler;
use Porta\Workbench\Notifier;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Tui\Tui;

/**
 * The tab-management actions shared by every editor-area occupant (the code
 * editor and the image/help viewers): cycling and closing tabs, the
 * recent-files switcher, the go-to-file palette, and save-all. It is also the
 * image viewer's whole handler: four of its five hooks are these very tab
 * actions, and the fifth ({@see viewChanged}) is the status-bar ping the
 * viewer's zoom label rides.
 */
// ViewerTabHandler gained a second implementation when HelpViewerHandler
// started extending it (the help pane needs the same four tab verbs), so the
// loader derives no alias and one has to be named. BufferNavigator is the
// general answer: HelpViewerActions is the help pane's own superset, reached
// by that widget alone.
#[AsAlias(ViewerTabHandler::class)]
final readonly class BufferNavigator implements BufferNavigationHandler, ViewerTabHandler
{
    public function __construct(
        private BufferManager $buffers,
        private BufferService $bufferService,
        #[Autowire(service: 'porta.go_to_file')]
        private SearchEverywhereController $goToFile,
        private ModalHost $editor,
        private StatusBarWidget $statusBar,
        private Tui $tui,
        private ProjectPaths $paths,
        private RecentFilesStore $recentFiles,
        private Translator $translator,
        private Notifier $notifier,
    ) {}

    /** Switch to the previous/next tab (+1 / -1) and focus its content. */
    public function cycle(int $offset): void
    {
        if (null !== ($buffer = $this->buffers->cycle($offset))) {
            $this->bufferService->showBuffer($buffer);
            $this->bufferService->focusEditorArea();
        }
    }

    public function closeActive(CloseMode $mode = CloseMode::Guarded): void
    {
        $this->bufferService->closeActiveBuffer($mode);
    }

    #[\Override]
    public function openGotoFile(): void
    {
        // The picker composites over the editor pane, so ensure it is the active
        // view first (an image is swapped for an adjacent text buffer).
        if ($this->bufferService->ensureTextEditorActive()) {
            $this->goToFile->open();
        }
        $this->tui->requestRender();
    }

    #[\Override]
    public function saveAll(): void
    {
        $this->bufferService->saveAllBuffers();
    }

    #[\Override]
    public function viewChanged(): void
    {
        $this->statusBar->invalidate();
        $this->tui->requestRender();
    }

    #[\Override]
    public function cycleBuffer(int $offset): void
    {
        $this->cycle($offset);
    }

    #[\Override]
    public function closeBuffer(): void
    {
        $this->closeActive();
    }

    /** The recent-files switcher in the editor's list overlay (Ctrl+E). */
    #[\Override]
    public function openRecentFiles(): void
    {
        $open = $this->buffers->recent();
        $items = \array_map(fn (Buffer $b): array => [
            'label' => \basename($b->path).($b->document->isDirty() ? ' '.Glyphs::DIRTY : ''),
            'hint' => $this->paths->relative($b->path),
            'value' => $b->path,
        ], $open);

        // Then the files that are recent but no longer open, which is the half
        // that makes the switcher worth a keystroke: the file you want back is
        // usually one you just closed. Gone from disk means gone from the list
        // rather than an entry that opens an empty buffer.
        $openPaths = \array_fill_keys(\array_map(static fn (Buffer $b): string => $b->path, $open), value: true);
        foreach ($this->recentFiles->all() as $path) {
            if (($openPaths[$path] ?? null) !== null || !\is_file($path)) {
                continue;
            }

            $items[] = ['label' => \basename($path), 'hint' => $this->paths->relative($path), 'value' => $path];
        }

        if (\count($items) < 2) {
            // Nowhere to switch to — said, not swallowed. A keystroke that
            // does nothing at all is indistinguishable from a broken binding,
            // and this one is most likely to be pressed on the first file of a
            // session, which is exactly when a new user is deciding whether
            // the shortcut works.
            $this->notifier->flash($this->translator->trans('No other files to switch to'));

            return;
        }

        // Pre-select the previous file, like PhpStorm's Ctrl+E
        $items[] = $items[0];
        \array_shift($items);

        $this->editor->openListOverlay($this->translator->trans('Recent Files'), $items, function (string $path): void {
            if (null !== ($buffer = $this->buffers->setActive($path))) {
                $this->bufferService->showBuffer($buffer);

                return;
            }

            $this->bufferService->openFile($path);
        });
        $this->tui->requestRender();
    }
}
