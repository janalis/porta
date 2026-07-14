<?php

declare(strict_types=1);

namespace Porta\Plugin;

use Porta\I18n\Translator;
use Porta\Widget\CodeEditorWidget;

/**
 * The live IDE seams a plugin's handlers reach through {@see PluginApi}:
 * wired into the {@see PluginManager} late in the IDE's construction, after
 * every collaborator exists. Handlers only run inside the event loop, so by
 * the time any of these closures fire the IDE is fully up.
 */
final readonly class PluginRuntime
{
    /**
     * @param \Closure(string, float): void $flash
     * @param \Closure(string): void        $openFile       absolute path, focuses the editor
     * @param \Closure(): void              $requestRender
     * @param \Closure(string, array<int, list<array{severity: int, message: string, col: int, endCol: int}>>): void $setDiagnostics per-line diagnostics for an open file's buffer
     */
    public function __construct(
        public \Closure $flash,
        public Translator $translator,
        public CodeEditorWidget $editor,
        public \Closure $openFile,
        public \Closure $requestRender,
        public \Closure $setDiagnostics,
    ) {}
}
