<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The editor's display toggles, as the settings form drives them. Implemented
 * by the editor widget; each verb flips one render flag and invalidates.
 *
 * Write-only on purpose: nothing in the IDE reads the current flag back through
 * this seam — the settings rows are rendered from the persisted config, not from
 * the widget — so the matching `has*()` accessors stay off the interface.
 */
interface EditorViewOptions
{
    public function enableInlineDiagnostics(): void;

    public function disableInlineDiagnostics(): void;

    public function setLineNumberMode(LineNumberMode $mode): void;

    public function enableStickyScroll(): void;

    public function disableStickyScroll(): void;

    public function enableIndentGuides(): void;

    public function disableIndentGuides(): void;

    public function enableVcsGutter(): void;

    public function disableVcsGutter(): void;

    public function enableAutoPairs(): void;

    public function disableAutoPairs(): void;
}
