<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\ResultsPanelMode;

/**
 * The two bookmark verbs: toggle one on the caret's line, and list them all.
 *
 * Toggling repaints the gutter through the same applier a buffer switch uses,
 * and refreshes a standing Bookmarks tab in place — never opening it, so
 * marking a line does not throw a panel over what you are reading.
 */
#[AsIdeService]
final readonly class BookmarkActions
{
    public function __construct(
        private Bookmarks $bookmarks,
        private BookmarkRows $rows,
        private BufferManager $buffers,
        private BottomDock $dock,
        private Translator $translator,
        private BookmarkDecorations $decorations,
    ) {}

    /** F11 on the caret's line. A viewer tab has no lines to bookmark. */
    public function toggle(): void
    {
        $buffer = $this->buffers->active();
        if (null === $buffer || $buffer->isViewer()) {
            return;
        }
        $line = $buffer->document->getCursorLine();
        $this->bookmarks->toggle($buffer->path, $line);
        // Through the same applier a buffer switch uses, so the gutter can
        // only ever be painted one way.
        $this->decorations->apply($buffer->path);
        $this->dock->refreshList(ResultsPanelMode::Bookmarks, $this->rows->build(...));
    }

    /** The Bookmarks tool — every mark across the project, newest file order. */
    public function show(): void
    {
        $this->dock->showList(ResultsPanelMode::Bookmarks, $this->translator->trans('Bookmarks'), $this->rows->build());
    }
}
