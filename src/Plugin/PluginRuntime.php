<?php

declare(strict_types=1);

namespace Porta\Plugin;

use Porta\Editor\DiagnosticsSink;
use Porta\Editor\FileOpener;
use Porta\I18n\Translator;
use Porta\Ui\Notifier;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Tui;

/**
 * The live IDE seams a plugin's handlers reach through {@see PluginApi}:
 * wired into the {@see PluginManager} late in the IDE's construction, after
 * every collaborator exists. Handlers only run inside the event loop, so by
 * the time any of these closures fire the IDE is fully up.
 */
final readonly class PluginRuntime
{
    public function __construct(
        public Notifier $notifier,
        public Translator $translator,
        public CodeEditorWidget $editor,
        public FileOpener $files,
        public Tui $tui,
        public DiagnosticsSink $diagnostics,
    ) {}
}
