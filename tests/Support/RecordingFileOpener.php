<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Editor\FileOpener;
use Porta\Editor\OpenFocus;

/** Records every openFile() call. */
final class RecordingFileOpener implements FileOpener
{
    /** @var list<array{path: string, focus: bool}> */
    public array $opened = [];

    public function openFile(string $path, OpenFocus $focus = OpenFocus::Keep): void
    {
        $this->opened[] = ['path' => $path, 'focus' => OpenFocus::Editor === $focus];
    }
}
