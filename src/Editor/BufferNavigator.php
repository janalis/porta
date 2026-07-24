<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Search\Everywhere\SearchEverywhereController;
use Porta\Support\ProjectPaths;
use Porta\Ui\Glyphs;
use Porta\Widget\BufferNavigationHandler;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Tui\Tui;

/**
 * The tab-management actions shared by every editor-area occupant (the code
 * editor and the image/help viewers): cycling and closing tabs, the
 * recent-files switcher, the go-to-file palette, and save-all.
 */
final class BufferNavigator implements BufferNavigationHandler
{
    public function __construct(
        private readonly BufferManager $buffers,
        private readonly BufferService $bufferService,
        #[Autowire(service: 'porta.go_to_file')]
        private readonly SearchEverywhereController $goToFile,
        private readonly CodeEditorWidget $editor,
        private readonly Tui $tui,
        private readonly string $rootDir,
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

    public function openGoToFile(): void
    {
        // The picker composites over the editor pane, so ensure it is the active
        // view first (an image is swapped for an adjacent text buffer).
        if ($this->bufferService->ensureTextEditorActive()) {
            $this->goToFile->open();
        }
        $this->tui->requestRender();
    }

    public function saveAll(): void
    {
        $this->bufferService->saveAllBuffers();
    }

    public function cycleBuffer(int $offset): void
    {
        $this->cycle($offset);
    }

    public function closeBuffer(): void
    {
        $this->closeActive();
    }

    /** The recent-files switcher in the editor's list overlay (Ctrl+E). */
    public function openRecentFiles(): void
    {
        $recent = $this->buffers->recent();
        if (\count($recent) < 2) {
            return;
        }
        $items = array_map(fn (Buffer $b) => [
            'label' => basename($b->path).($b->document->isDirty() ? ' '.Glyphs::DIRTY : ''),
            'hint' => ProjectPaths::relative($this->rootDir, $b->path),
            'value' => $b->path,
        ], $recent);
        // Pre-select the previous file, like PhpStorm's Ctrl+E
        $items[] = $items[0];
        array_shift($items);

        $this->editor->openListOverlay('Recent Files', $items, function (string $path): void {
            if (null !== ($buffer = $this->buffers->setActive($path))) {
                $this->bufferService->showBuffer($buffer);
            }
        });
        $this->tui->requestRender();
    }
}
