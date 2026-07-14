<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The buffer choke point for opening a file by absolute path. Implemented by
 * {@see BufferService}; consumers (debugger, navigation) depend on this seam
 * so unit tests can record opens without the full buffer stack.
 */
interface FileOpener
{
    public function openFile(string $path, bool $focusEditor = false): void;
}
