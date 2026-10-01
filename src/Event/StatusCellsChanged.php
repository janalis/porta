<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * What a status-bar cell's source shows changed — the participants it
 * counts, the uploads still on their way to a server, a link lost or found —
 * while nothing else on screen may have. A FACT, for the status bar: it
 * re-reads its cells only when marked dirty, because a frame skips
 * `beforeRender()` under a cached subtree.
 */
final readonly class StatusCellsChanged {}
