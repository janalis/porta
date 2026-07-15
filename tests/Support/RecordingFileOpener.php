<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Editor\FileOpener;

/** Records every openFile() call. */
final class RecordingFileOpener implements FileOpener
{
    /** @var list<array{path: string, focus: bool}> */
    public array $opened = [];

    public function openFile(string $path, bool $focusEditor = false): void
    {
        $this->opened[] = ['path' => $path, 'focus' => $focusEditor];
    }
}
