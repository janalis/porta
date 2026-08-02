<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Editor\ChangeMarkSink;

/** Records every published change-mark batch. */
final class RecordingChangeMarkSink implements ChangeMarkSink
{
    /** @var list<array{path: string, marks: array<int, string>}> */
    public array $published = [];

    public function applyChangeMarks(string $path, array $marks): void
    {
        $this->published[] = ['path' => $path, 'marks' => $marks];
    }
}
