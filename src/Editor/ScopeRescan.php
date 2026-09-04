<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * A pending debounced structure rescan: the timer, and the document version it
 * was armed for.
 *
 * One value rather than two fields on {@see \Porta\Widget\CodeEditorWidget},
 * which pins `too-many-properties`. They were always one fact — a timer id with
 * no idea what it is waiting to rescan is nothing — and carrying them apart
 * only invited them to disagree.
 */
final readonly class ScopeRescan
{
    public function __construct(
        public string $timerId,
        public string $key,
    ) {}
}
