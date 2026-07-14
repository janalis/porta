<?php

declare(strict_types=1);

namespace Porta\Lsp;

/**
 * Re-sends didOpen for every open buffer so a live LSP reconfigure relaunches
 * its servers. Implemented by BufferService; the settings controller depends
 * on this seam so tests can count reopens without the full buffer stack.
 */
interface BufferReopener
{
    public function reopenAllInLsp(): void;
}
