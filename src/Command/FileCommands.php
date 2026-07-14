<?php

declare(strict_types=1);

namespace Porta\Command;

use Porta\Config\CommandRegistry;
use Porta\Editor\BufferService;
use Porta\Lsp\RefactorController;

/** File-level palette commands: saving and LSP reformat. */
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
