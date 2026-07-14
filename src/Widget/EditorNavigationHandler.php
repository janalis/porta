<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * The editor's LSP navigation hooks, all at a caret position (line, utf16
 * col). Implemented by {@see \Porta\Lsp\NavigationController} — the method
 * names are the controller's own; tests inject fakes.
 */
interface EditorNavigationHandler
{
    public function requestDefinition(int $line, int $utf16Col): void;

    public function requestImplementation(int $line, int $utf16Col): void;

    public function requestTypeDefinition(int $line, int $utf16Col): void;

    /** Navigate back after a definition jump. */
    public function navigateBack(): void;

    /** Quick documentation (hover). */
    public function requestHover(int $line, int $col): void;

    /** Find usages (references). */
    public function requestReferences(int $line, int $col): void;

    public function requestFileStructure(): void;

    public function requestPeek(int $line, int $col): void;
}
