<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * The editor's file-level hooks: saving, clipboard mirroring and the find /
 * go-to-file palettes. Implemented by {@see \Porta\Editor\EditorFileActions};
 * tests inject fakes.
 */
interface EditorFileActionHandler
{
    /** Save the active buffer. */
    public function save(): void;

    /** Save every dirty buffer. */
    public function saveAll(): void;

    /** Text copied in the editor (mirror to the system clipboard). */
    public function copy(string $text): void;

    /** Open the find bar. */
    public function openFind(): void;

    /** Open the find bar with the replace field. */
    public function openFindReplace(): void;
}
