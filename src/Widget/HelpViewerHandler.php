<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * The help viewer's outbound hooks: link routing (another doc page, a plain
 * file, an external URL, a dangling target), back-stack exhaustion, and the
 * tab actions re-bound while the code editor is detached. Implemented by
 * {@see \Porta\Help\HelpViewerActions}; tests inject recording fakes.
 */
interface HelpViewerHandler
{
    /** Render another markdown page (absolute path). */
    public function openHelp(string $path): void;

    /** Open a non-markdown link target in the editor. */
    public function openFile(string $path): void;

    /** Open an http(s) link in the browser. */
    public function openUrl(string $url): void;

    /** A link target that does not resolve to a file. */
    public function missingTarget(string $target): void;

    /** Open the in-file find bar over the docs page (Ctrl+F). */
    public function openFind(): void;

    /** A breadcrumb label for a page path. */
    public function labelFor(string $path): string;

    /** Backspace with an empty history. */
    public function backEmpty(): void;

    public function cycleBuffer(int $offset): void;

    public function closeBuffer(): void;

    public function openGotoFile(): void;

    public function saveAll(): void;
}
