<?php

declare(strict_types=1);

namespace Porta\Plugin\Runtime;

use Porta\Container\AsIdeService;
use Porta\Editor\BufferManager;
use Porta\Editor\ChangeMarkSink;
use Porta\Editor\DiagnosticsSink;
use Porta\Editor\DocumentReplacer;
use Porta\Editor\EditorTextAccess;
use Porta\Editor\EditorViewOptions;
use Porta\Editor\FileOpener;
use Porta\Editor\LineAnnotationSink;
use Porta\Editor\ModalHost;
use Porta\I18n\Translator;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Lifecycle\PluginLifecycleOps;
use Porta\Support\Clipboard;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Dock\DockToolController;
use Porta\Workbench\Menu\MenuFileContext;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Tui;

/**
 * The live IDE seams a plugin's handlers reach through {@see PluginApi}:
 * wired into the {@see PluginManager} late in the IDE's construction, after
 * every collaborator exists. Handlers only run inside the event loop, so by
 * the time any of these closures fire the IDE is fully up.
 */
#[AsIdeService]
final readonly class PluginRuntime
{
    public function __construct(
        public Notifier $notifier,
        public Translator $translator,
        public EditorTextAccess $editor,
        public FileOpener $files,
        public Tui $tui,
        public DiagnosticsSink $diagnostics,
        public LineAnnotationSink $annotations,
        public ChangeMarkSink $changeMarks,
        public BufferManager $buffers,
        public DockToolController $dockTools,
        public DocumentReplacer $documents,
        public ModalHost $modals,
        public MenuFileContext $menuFile,
        public ViewRegistry $views,
        public Clipboard $clipboard,
        public StatusBarWidget $statusBar,
        public EditorViewOptions $editorView,
        // Nullable-with-default so the many positional test constructions
        // stand; the container always resolves it, and the symfony-lsp E2e
        // pins the wired behavior (changeSetting → live LSP reconfigure).
        public ?PluginLifecycleOps $lifecycle = null,
    ) {}
}
