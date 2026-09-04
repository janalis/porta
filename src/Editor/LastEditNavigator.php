<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Widget\CodeEditorWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Tui;

/**
 * Takes you back to what you were writing — PhpStorm's Ctrl+Shift+Backspace.
 *
 * Distinct from navigate-back, which returns you from a JUMP and so knows only
 * the places a navigation verb pushed. Scrolling away from your work, or
 * clicking into another file, pushes nothing there and is exactly when you want
 * this one.
 *
 * Repeated presses walk further back through distinct edit sites; making an
 * edit starts the walk over, because the next press then means "the place
 * before HERE".
 */
#[AsIdeService]
final readonly class LastEditNavigator
{
    public function __construct(
        private EditHistory $history,
        private CodeEditorWidget $editor,
        private FileOpener $files,
        private Notifier $notifier,
        private Translator $translator,
        private Tui $tui,
    ) {}

    public function goBack(): void
    {
        $site = $this->history->walkBack();
        if (null === $site) {
            $this->notifier->flash($this->translator->trans('No edits to go back to'));

            return;
        }

        if ($site['path'] !== $this->editor->getFilePath()) {
            $this->files->openFile($site['path'], OpenFocus::Editor);
        }
        // Column 0: the line is what a person remembers about where they were
        // typing, and a stale column on a line since re-typed is a worse guess
        // than the start of it.
        $this->editor->getDocument()->setCursor($site['line'], 0);
        $this->editor->invalidate(); // the render pass scrolls it into view
        $this->tui->requestRender();
    }
}
