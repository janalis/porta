<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * A file an open buffer is showing has been renamed underneath it — re-point
 * the buffer at its new path.
 *
 * A command, so exactly one listener performs it ({@see \Porta\Editor\BufferService}).
 * It exists to break a real container cycle: {@see \Porta\Lsp\WorkspaceEditor}
 * performs a server's `RenameFile` operation, and re-opening the buffer needs
 * `BufferService`, which reaches `WorkspaceEditor` back through
 * `FormatOnSave`. Scalars only, per the bus's rule.
 */
final readonly class BufferRepointRequested
{
    public function __construct(
        public string $from,
        public string $to,
    ) {}
}
