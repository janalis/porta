<?php

declare(strict_types=1);

namespace Porta\Command;

use Porta\Config\CommandRegistry;
use Porta\Config\KeyDispatch;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\OpenAs;
use Porta\Editor\OpenFocus;
use Porta\Editor\ReferenceCopier;
use Porta\Editor\ScratchFiles;
use Porta\Lsp\RefactorController;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** File-level palette commands: saving, LSP reformat, and the viewer escape hatch. */
#[AsTaggedItem(priority: 400)]
final class FileCommands implements CommandProvider
{
    public function __construct(
        private readonly BufferService $bufferService,
        private readonly RefactorController $refactor,
        private readonly BufferManager $buffers,
        private readonly ScratchFiles $scratches,
        private readonly ReferenceCopier $references,
    ) {}

    #[\Override]
    public function register(CommandRegistry $commands): void
    {
        $commands
            ->add('save', fn () => $this->bufferService->saveCurrentFile())
            ->add('save_all', fn () => $this->bufferService->saveAllBuffers())
            ->add('ide_new_scratch', fn () => $this->scratches->open(), keyBound: KeyDispatch::Bound)
            ->add('copy_path', fn () => $this->references->copyPath(), keyBound: KeyDispatch::Bound)
            ->add('copy_reference', fn () => $this->references->copyReference(), keyBound: KeyDispatch::Bound)
            ->add('reformat', fn () => $this->refactor->requestReformat())
            ->add('ide_undo_refactoring', fn () => $this->refactor->undoLastRefactoring(), keyBound: KeyDispatch::Bound)
            ->add('open_as_text', $this->openActiveAsText(...), when: fn (): bool => true === $this->buffers->active()?->isViewer());
    }

    /**
     * Re-open the active viewer tab in the editor — the way back from a picture
     * to the document behind it (an SVG's XML, a graph's DOT, an image's bytes).
     * Gated on the tab BEING a viewer, so it never offers to do nothing.
     */
    private function openActiveAsText(): void
    {
        $active = $this->buffers->active();
        if (null !== $active) {
            $this->bufferService->openFile($active->path, OpenFocus::Editor, OpenAs::Text);
        }
    }
}
