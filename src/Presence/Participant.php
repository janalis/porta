<?php

declare(strict_types=1);

namespace Porta\Presence;

/**
 * Someone else at work on this project, as the roster lists them: who, how
 * they reach it, and the file they have open — a path on THIS machine, or
 * null when they have none open or it is not theirs to say.
 *
 * `$via` is a word, not an enum: `local`, `ssh` and `ftp` say how another
 * Porta reached the same files, and a plugin that brings its own (assist)
 * says its name.
 */
final readonly class Participant
{
    public function __construct(
        public string $id,
        public string $name,
        public string $via,
        public ?string $path = null,
        public int $line = -1,
    ) {}
}
