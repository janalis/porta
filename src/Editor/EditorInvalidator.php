<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Drops the editor's memoised render lines so the next frame repaints from
 * current state. Implemented by the editor widget; depended on by the services
 * that mutate what the editor shows without owning it — a formatter rewriting
 * the buffer, a workspace edit landing, a highlight backend delivering colors.
 *
 * Deliberately one method: these callers repaint and nothing else, so they have
 * no business holding the widget itself.
 */
interface EditorInvalidator
{
    public function invalidate(): void;
}
