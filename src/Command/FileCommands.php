<?php

declare(strict_types=1);

namespace Porta\Command;

use Porta\Config\CommandRegistry;
use Porta\Editor\BufferService;
use Porta\Lsp\RefactorController;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** File-level palette commands: saving and LSP reformat. */
#[AsTaggedItem(priority: 400)]
final class FileCommands implements CommandProvider
{
    public function __construct(
        private readonly BufferService $bufferService,
        private readonly RefactorController $refactor,
    ) {}

    public function register(CommandRegistry $commands): void
    {
        $commands
            ->add('save', fn () => $this->bufferService->saveCurrentFile())
            ->add('save_all', fn () => $this->bufferService->saveAllBuffers())
            ->add('reformat', fn () => $this->refactor->requestReformat());
    }
}
