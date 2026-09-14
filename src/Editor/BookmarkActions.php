<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Ui\ShortcutFormatter;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\ResultsPanelMode;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Input\Keybindings;

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
        private Notifier $notifier,
        private Keybindings $keys,
    ) {}

    /** F11 on the caret's line. A viewer tab has no lines to bookmark. */
    public function toggle(): void
    {
        $buffer = $this->buffers->active();
        if (null === $buffer || $buffer->isViewer()) {
            return;
        }
        $line = $buffer->document->cursorLine;
        $this->bookmarks->toggle($buffer->path, $line);
        // Through the same applier a buffer switch uses, so the gutter can
        // only ever be painted one way.
        $this->decorations->apply($buffer->path);
        $this->dock->refreshList(ResultsPanelMode::Bookmarks, $this->rows->build(...));
    }

    /** The Bookmarks tool — every mark across the project, newest file order. */
    public function show(): void
    {
        $rows = $this->rows->build();
        $this->dock->showList(ResultsPanelMode::Bookmarks, $this->translator->trans('Bookmarks'), $rows);
        if ([] !== $rows) {
            return;
        }

        // An empty panel reading `0 results` teaches nothing, and this panel is
        // the one people reach first: the verb that fills it lives on a key
        // they have not pressed yet. The key is resolved, not spelled — on a
        // Mac F11 is a media key until the keyboard setting says otherwise, so
        // this is exactly the binding somebody rebinds.
        $binding = ShortcutFormatter::format($this->keys->getBindings('toggle_bookmark')[0] ?? '');
        $this->notifier->flash(
            '' === $binding
                ? $this->translator->trans('No bookmarks yet — Toggle Bookmark adds one on the caret’s line')
                : $this->translator->trans('No bookmarks yet — press %key% on a line to add one', ['%key%' => $binding]),
        );
    }
}
