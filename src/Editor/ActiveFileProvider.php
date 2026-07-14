<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The editor's current file path, for services that act on "the active file"
 * without holding the editor widget (which implements this).
 */
interface ActiveFileProvider
{
    public function getFilePath(): ?string;
}
