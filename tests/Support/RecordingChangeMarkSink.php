<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Editor\ChangeMarkKind;
use Porta\Editor\ChangeMarkSink;

/** Records every published change-mark and comment-mark batch. */
final class RecordingChangeMarkSink implements ChangeMarkSink
{
    /** @var list<array{path: string, marks: array<int, ChangeMarkKind>}> */
    public array $published = [];

    /** @var list<array{path: string, marks: array<int, string>, owner: string}> */
    public array $comments = [];

    public function applyChangeMarks(string $path, array $marks): void
    {
        $this->published[] = ['path' => $path, 'marks' => $marks];
    }

    public function applyCommentMarks(string $path, array $marks, string $owner): void
    {
        $this->comments[] = ['path' => $path, 'marks' => $marks, 'owner' => $owner];
    }
}
