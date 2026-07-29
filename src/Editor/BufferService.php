<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Config\EditorConfig\DocumentWriter;
use Porta\Config\EditorConfig\EditorConfigResolver;
use Porta\Container\AsIdeService;
use Porta\Container\LayoutSlot;
use Porta\Event\BufferOpened;
use Porta\Event\BufferSaved;
use Porta\Event\BufferSwitched;
use Porta\Event\DiagnosticsChanged;
use Porta\Graph\DotFiles;
use Porta\History\LocalHistory;
use Porta\I18n\Translator;
use Porta\Image\ImageDecoder;
use Porta\Lsp\BufferReopener;
use Porta\Lsp\FormatOnSave;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\Uri;
use Porta\Support\ProjectPaths;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\GraphViewerWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\ImageViewerWidget;
use Porta\Workbench\Notifier;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * The open-buffer lifecycle, extracted from the coordinator: opening files
 * into buffers (with .editorconfig and LSP didOpen), switching the editor
 * between buffers, closing tabs (with the unsaved-changes dialog), saving
 * (with .editorconfig save-time transforms and local-history snapshots),
 * reconciling buffers with external on-disk changes, and delivering LSP
 * diagnostics to their buffer.
 *
 * Cross-domain reactions (git marks, debug decorations, LSP save/inlay,
 * plugin hooks) ride the dispatched BufferOpened/BufferSwitched/BufferSaved
 * events; only intra-flow calls (didOpen ordering, external reloads) stay inline.
 */
#[AsIdeService]
final class BufferService implements BufferReopener, DiagnosticsSink, FileOpener
{
    public function __construct(
        private readonly BufferManager $buffers,
        private readonly CodeEditorWidget $editor,
        private readonly FileTreeWidget $tree,
        private readonly Notifier $notifier,
        private readonly Tui $tui,
        private readonly LspSessionPool $lsp,
        private readonly LocalHistory $history,
        private readonly EditorConfigResolver $editorConfigResolver,
        private readonly string $rootDir,
        private readonly Translator $translator,
        private readonly FilesystemReconciler $reconciler,
        private readonly ImageViewerWidget $imageViewer,
        private readonly HelpViewerWidget $helpViewer,
        private readonly GraphViewerWidget $graphViewer,
        private readonly ImageDecoder $imageDecoder,
        #[LayoutSlot('editor_body')]
        private readonly ContainerWidget $editorBody,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly FormatOnSave $formatOnSave,
        private readonly ViewerProviderSource $viewers,
    ) {}

    public function openFile(string $path, OpenFocus $focus = OpenFocus::Keep): void
    {
        $path = ($resolved = realpath($path)) ? $resolved : $path;

        if ($this->buffers->has($path)) {
            /** @var Buffer $active */
            $active = $this->buffers->setActive($path);
            $this->refreshStaleViewerPayload($active);
            $this->showBuffer($active);
            if (OpenFocus::Editor === $focus) {
                $this->focusEditorArea();
            }

            return;
        }

        if (!is_file($path) || !is_readable($path)) {
            $this->notifier->flash($this->translator->trans('Cannot read %file%', ['%file%' => $this->relativePath($path)]));

            return;
        }
        // The registered viewer routes decide which pane a file opens in (the
        // bundled image-viewer/graph-viewer plugins claim their extensions —
        // disabling one routes its files here to the text path instead; an id
        // no built-in pane answers to falls through the same way).
        $viewer = $this->viewers->viewerFor($path);
        if ('image' === $viewer) {
            $this->openImageFile($path, $focus);

            return;
        }
        if ('graph' === $viewer) {
            $this->openGraphFile($path, $focus);

            return;
        }
        if ((int) filesize($path) > (2 * 1024 * 1024)) {
            $this->notifier->flash($this->translator->trans('File too large (> 2 MB)'));

            return;
        }

        $content = (string) file_get_contents($path);
        if ('' !== $content && !mb_check_encoding($content, encoding: 'UTF-8')) {
            $this->notifier->flash($this->translator->trans('Not a UTF-8 text file'));

            return;
        }

        $buffer = $this->buffers->open($path, $content);
        $buffer->stampFromDisk();
        $this->applyEditorConfig($buffer);

        // didOpen before showBuffer so the inlay-hint request it fires is
        // ordered after the server knows about the file; this is also what
        // lazily starts the file's language server.
        $this->lsp->notifyDidOpen(
            $path,
            $buffer->document->getText(),
            ProjectPaths::languageId($path),
            $buffer->document->getVersion(),
        );

        $this->showBuffer($buffer);
        $this->dispatcher->dispatch(new BufferOpened($buffer));

        if (OpenFocus::Editor === $focus) {
            $this->focusEditorArea();
        }
        $this->tui->requestRender();
    }

    /**
     * Re-opening an already-open viewer tab whose file changed on disk shows
     * the CURRENT content, not the stale payload — an explicit open must not
     * wait out the 1s reconciler (the regenerated architecture artifact is the
     * motivating case: the plugin writes it, then opens it). Silent on
     * purpose, unlike the reconciler's "Reloaded" flash — the user asked for
     * this file just now.
     */
    private function refreshStaleViewerPayload(Buffer $buffer): void
    {
        if (!$buffer->isViewer() || $buffer->diskStamp === $buffer->diskStampNow()) {
            return;
        }
        if ($buffer->isGraph()) {
            $dot = (string) file_get_contents($buffer->path);
            if ('' === $dot || mb_check_encoding($dot, encoding: 'UTF-8')) {
                $buffer->graphDot = $dot;
                $buffer->stampFromDisk();
            }

            return;
        }
        if ($buffer->isImage() && (int) filesize($buffer->path) <= ImageDecoder::MAX_BYTES) {
            $image = $this->imageDecoder->decode($buffer->path);
            if (null !== $image) {
                $buffer->image = $image;
                $buffer->stampFromDisk();
            }
        }
    }

    /**
     * Open an image file into a viewer tab: no editorconfig, no LSP didOpen,
     * no git marks — just the decoded pixels behind a normal buffer/tab.
     */
    private function openImageFile(string $path, OpenFocus $focus): void
    {
        if ((int) filesize($path) > ImageDecoder::MAX_BYTES) {
            $this->notifier->flash($this->translator->trans('Image too large (> 20 MB)'));

            return;
        }
        $image = $this->imageDecoder->decode($path);
        if (null === $image) {
            $this->notifier->flash($this->translator->trans('Cannot display image %file%', ['%file%' => $this->relativePath($path)]));

            return;
        }
        $buffer = $this->buffers->openImage($path, $image);
        $buffer->stampFromDisk();
        $this->showBuffer($buffer);
        $this->dispatcher->dispatch(new BufferOpened($buffer));
        if (OpenFocus::Editor === $focus) {
            $this->focusEditorArea();
        }
        $this->tui->requestRender();
    }

    /**
     * Open a Graphviz DOT document into a graph-viewer tab: no editorconfig,
     * no LSP didOpen, no git marks — the DOT source rides the buffer beside
     * an empty document. The generated architecture artifact gets its tab
     * title here, so session restore reproduces it through the plain
     * path-keyed reuse.
     */
    private function openGraphFile(string $path, OpenFocus $focus): void
    {
        if ((int) filesize($path) > DotFiles::MAX_BYTES) {
            $this->notifier->flash($this->translator->trans('Graph file too large (> 10 MB)'));

            return;
        }
        $dot = (string) file_get_contents($path);
        if ('' !== $dot && !mb_check_encoding($dot, encoding: 'UTF-8')) {
            $this->notifier->flash($this->translator->trans('Not a UTF-8 text file'));

            return;
        }
        $buffer = $this->buffers->openGraph($path, $dot);
        if (str_ends_with($path, '/.porta/architecture.dot')) {
            $buffer->title = 'Project Architecture';
        }
        $buffer->stampFromDisk();
        $this->showBuffer($buffer);
        $this->dispatcher->dispatch(new BufferOpened($buffer));
        if (OpenFocus::Editor === $focus) {
            $this->focusEditorArea();
        }
        $this->tui->requestRender();
    }

    /**
     * Open a documentation page into a rendered help-viewer tab: no LSP, no
     * git marks — the raw markdown rides the buffer beside an empty document.
     * A path already open as a normal text buffer just re-shows that source
     * tab (developing Porta itself can have docs open both ways).
     */
    public function openHelp(string $path): void
    {
        $path = ($resolved = realpath($path)) ? $resolved : $path;

        if ($this->buffers->has($path)) {
            /** @var Buffer $active */
            $active = $this->buffers->setActive($path);
            $this->showBuffer($active);
            $this->focusEditorArea();
            $this->tui->requestRender();

            return;
        }

        if (!is_file($path) || !is_readable($path)) {
            $this->notifier->flash($this->translator->trans('Cannot read %file%', ['%file%' => $this->relativePath($path)]));

            return;
        }

        $buffer = $this->buffers->openHelp($path, (string) file_get_contents($path));
        $buffer->stampFromDisk();
        $this->showBuffer($buffer);
        $this->dispatcher->dispatch(new BufferOpened($buffer));
        $this->focusEditorArea();
        $this->tui->requestRender();
    }

    /**
     * Navigate the open help tab to another doc page in place: no new buffer,
     * no tab switch — the viewer widget owns the page and its back/forward
     * history, and the single help tab just keeps showing the widget.
     */
    public function showHelpPage(string $path): void
    {
        $path = ($resolved = realpath($path)) ? $resolved : $path;
        if (!is_file($path) || !is_readable($path)) {
            $this->notifier->flash($this->translator->trans('Cannot read %file%', ['%file%' => $this->relativePath($path)]));

            return;
        }
        $this->helpViewer->show($path, (string) file_get_contents($path));
        $this->tui->requestRender();
    }

    /** Focus the editor-pane content: the active tab's viewer, else the editor. */
    public function focusEditorArea(): void
    {
        $active = $this->buffers->active();
        $this->tui->setFocus(match (true) {
            null !== $active && $active->isImage() => $this->imageViewer,
            null !== $active && $active->isHelp() => $this->helpViewer,
            null !== $active && $active->isGraph() => $this->graphViewer,
            default => $this->editor,
        });
    }

    /**
     * Resolve the file's `.editorconfig` and apply it: indentation to the live
     * document, and the whole config stashed on the buffer for the save-time
     * transforms in {@see saveBuffer()}. Always on; the per-language indent
     * settings supersede it per field when not 'auto'. Flashes once when the
     * file requests a charset the editor cannot honour (UTF-8 only).
     */
    private function applyEditorConfig(Buffer $buffer): void
    {
        $config = $this->editorConfigResolver->resolve($buffer->path);
        $buffer->document->applyEditorConfig($config);
        $buffer->editorConfig = $config;
        if (null !== $config->charset && !\in_array($config->charset, ['utf-8', 'utf-8-bom'], strict: true)) {
            $this->notifier->flash($this->translator->trans('.editorconfig charset "%charset%" ignored (UTF-8 only)', ['%charset%' => $config->charset]), 5.0);
        }
    }

    public function showBuffer(Buffer $buffer): void
    {
        if ($buffer->isImage() && null !== $buffer->image) {
            // Neutralize the editor so any jumpTo() fired after openFile()
            // mutates a throwaway document, not the previous buffer's cursor.
            $this->editor->showDocument('', new Document(''));
            $this->imageViewer->show($buffer->image);
            $this->swapEditorPaneTo($this->imageViewer);
            $this->dispatcher->dispatch(new BufferSwitched($buffer));
            $this->tui->requestRender();

            return;
        }
        if ($buffer->isHelp()) {
            $this->editor->showDocument('', new Document(''));
            if (null === $this->helpViewer->path()) {
                $this->helpViewer->show($buffer->path, (string) $buffer->helpMarkdown);
            }
            $this->imageViewer->clear();
            $this->swapEditorPaneTo($this->helpViewer);
            $this->dispatcher->dispatch(new BufferSwitched($buffer));
            $this->tui->requestRender();

            return;
        }
        if ($buffer->isGraph()) {
            $this->editor->showDocument('', new Document(''));
            $this->graphViewer->show($buffer->path, (string) $buffer->graphDot);
            $this->imageViewer->clear();
            $this->swapEditorPaneTo($this->graphViewer);
            $this->dispatcher->dispatch(new BufferSwitched($buffer));
            $this->tui->requestRender();

            return;
        }
        $this->swapEditorPaneTo($this->editor);
        $this->imageViewer->clear();
        $this->editor->showDocument($buffer->path, $buffer->document, $buffer->diagnostics, $buffer->highlighter, $buffer->editorConfig);
        $this->editor->setGitMarks($buffer->gitMarks);
        $this->dispatcher->dispatch(new BufferSwitched($buffer));
        $this->tui->requestRender();
    }

    /**
     * Make $widget the editor body's content slot (breadcrumbs → content; the
     * tab strip sits around the body, wherever the tabBarPosition setting put it).
     * Detaching the focused widget makes the focus manager fall back to an
     * arbitrary focusable, so focus is handed to the incoming widget instead.
     */
    private function swapEditorPaneTo(AbstractWidget $widget): void
    {
        if (\in_array($widget, $this->editorBody->all(), strict: true)) {
            return;
        }
        $hadFocus = false;
        foreach ([$this->editor, $this->imageViewer, $this->helpViewer, $this->graphViewer] as $occupant) {
            if ($occupant === $widget) {
                continue;
            }
            $hadFocus = $hadFocus || $this->tui->getFocus() === $occupant;
            $this->editorBody->remove($occupant);
        }
        $this->editorBody->add($widget);
        if ($hadFocus) {
            $this->tui->setFocus($widget);
        }
    }

    /** The empty state after the last tab closes: a blank editor, tree focused. */
    private function showEmptyEditor(): void
    {
        $this->swapEditorPaneTo($this->editor);
        $this->imageViewer->clear();
        $this->helpViewer->clear();
        $this->graphViewer->clear();
        $this->editor->showDocument('', new Document(''));
        $this->tui->setFocus($this->tree);
    }

    /** Close a specific tab (activating it first, mirroring a close-button click). */
    public function closeTab(string $path): void
    {
        $active = $this->buffers->setActive($path);
        if (null !== $active) {
            $this->showBuffer($active); // the unsaved-changes dialog needs its pane attached
        }
        $this->focusEditorArea();
        $this->closeActiveBuffer();
        $this->tui->requestRender();
    }

    /**
     * Close every open buffer except $keep (null closes them all), leaving any
     * buffer with unsaved changes open and flashing how many were kept, so a bulk
     * close never silently discards edits.
     */
    public function closeTabsExcept(?string $keep): void
    {
        $dirtyKept = 0;
        foreach ($this->buffers->all() as $buffer) {
            if ($buffer->path === $keep) {
                continue;
            }
            if ($buffer->isDirty()) {
                ++$dirtyKept;
                continue;
            }
            if (!$buffer->isViewer()) {
                $this->lsp->notifyDidClose($buffer->path);
            }
            if ($buffer->isHelp()) {
                $this->helpViewer->clear();
            }
            if ($buffer->isGraph()) {
                $this->graphViewer->forget($buffer->path);
            }
            $this->buffers->close($buffer->path);
        }

        $this->showAfterBulkClose($keep);

        if ($dirtyKept > 0) {
            $this->notifier->flash(
                1 === $dirtyKept
                    ? $this->translator->trans('%count% unsaved tab kept open', ['%count%' => $dirtyKept])
                    : $this->translator->trans('%count% unsaved tabs kept open', ['%count%' => $dirtyKept]),
            );
        }
        $this->tui->requestRender();
    }

    /** Show the buffer that should be visible after a bulk close: $keep, else the most recent, else empty. */
    private function showAfterBulkClose(?string $keep): void
    {
        if (null !== $keep && null !== ($kept = $this->buffers->setActive($keep))) {
            $this->showBuffer($kept);

            return;
        }
        $active = $this->buffers->active();
        if (null !== $active) {
            $this->showBuffer($active);

            return;
        }
        $this->showEmptyEditor();
    }

    public function closeActiveBuffer(CloseMode $mode = CloseMode::Guarded): void
    {
        $active = $this->buffers->active();
        if (null === $active) {
            return;
        }
        if (CloseMode::Guarded === $mode && $active->isDirty()) {
            $name = basename($active->path);
            $this->editor->openListOverlay(
                $this->translator->trans('Unsaved changes in %name%', ['%name%' => $name]),
                [
                    ['label' => $this->translator->trans('Save & Close'), 'hint' => '', 'value' => 'save'],
                    ['label' => $this->translator->trans('Discard & Close'), 'hint' => '', 'value' => 'discard'],
                    ['label' => $this->translator->trans('Cancel'), 'hint' => '', 'value' => 'cancel'],
                ],
                function (string $choice) use ($active): void {
                    if ('save' === $choice && $this->saveBuffer($active)) {
                        $this->closeActiveBuffer();

                        return;
                    }
                    if ('discard' === $choice) {
                        $this->closeActiveBuffer(CloseMode::Force);
                    }
                },
            );
            $this->tui->requestRender();

            return;
        }

        if (!$active->isViewer()) {
            $this->lsp->notifyDidClose($active->path);
        }

        $next = $this->buffers->close($active->path);
        if ($active->isHelp()) {
            $this->helpViewer->clear(); // history dies with the tab, not on tab switches
        }
        if ($active->isGraph()) {
            $this->graphViewer->forget($active->path); // the remembered zoom dies with the tab
        }
        if (null !== $next) {
            $this->showBuffer($next);

            return;
        }
        $this->showEmptyEditor();
        $this->tui->requestRender();
    }

    public function saveCurrentFile(): void
    {
        $active = $this->buffers->active();
        if (null === $active) {
            return;
        }
        // Format-on-save only covers the single-file save: saveAllBuffers()
        // and Save & Close keep the synchronous bool contract of saveBuffer().
        if ($this->formatOnSave->isEnabled() && !$active->isViewer()) {
            $this->formatOnSave->formatThenSave($active, function () use ($active): void {
                if ($this->saveBuffer($active)) {
                    $this->notifier->success($this->translator->trans('Saved %file%', ['%file%' => $this->relativePath($active->path)]));
                }
                $this->tui->requestRender();
            });

            return;
        }
        if ($this->saveBuffer($active)) {
            $this->notifier->success($this->translator->trans('Saved %file%', ['%file%' => $this->relativePath($active->path)]));
        }
        $this->tui->requestRender();
    }

    public function saveAllBuffers(): void
    {
        $saved = 0;
        foreach ($this->buffers->all() as $buffer) {
            if (!($buffer->isDirty() && $this->saveBuffer($buffer))) {
                continue;
            }

            ++$saved;
        }
        $this->notifier->flash(match (true) {
            0 === $saved => $this->translator->trans('Nothing to save'),
            1 === $saved => $this->translator->trans('Saved %count% file', ['%count%' => $saved]),
            default => $this->translator->trans('Saved %count% files', ['%count%' => $saved]),
        });
    }

    public function saveBuffer(Buffer $buffer): bool
    {
        if ($buffer->isViewer()) {
            return false; // never write the empty backing document over the real file
        }
        $bytes = null !== $buffer->editorConfig
            ? DocumentWriter::render($buffer->document, $buffer->editorConfig)
            : $buffer->document->getText();
        try {
            new Filesystem()->dumpFile($buffer->path, $bytes);
        } catch (IOExceptionInterface) {
            $this->notifier->error($this->translator->trans('Save failed: %file%', ['%file%' => $this->relativePath($buffer->path)]));

            return false;
        }
        $buffer->document->markSaved();
        $buffer->stampFromDisk(); // our own save must not look external
        $this->history->snapshot($buffer->path, $bytes);
        $this->dispatcher->dispatch(new BufferSaved($buffer));

        return true;
    }

    /**
     * Reconcile the open buffers and the file tree with the on-disk state.
     * Runs on the coordinator's poll timer; also invoked directly by tests.
     * Delegated to {@see FilesystemReconciler}, which owns this lifecycle.
     */
    public function pollFilesystem(): void
    {
        $this->reconciler->pollFilesystem();
    }

    /**
     * Activate the most recently focused text buffer (for the editor-hosted
     * modals that cannot open over an image tab). Null when only images are open.
     */
    public function activateAdjacentTextBuffer(): ?Buffer
    {
        foreach ($this->buffers->recent() as $buffer) {
            if ($buffer->isViewer()) {
                continue;
            }

            $this->buffers->setActive($buffer->path);
            $this->showBuffer($buffer);

            return $buffer;
        }

        return null;
    }

    /**
     * The Search Everywhere / go-to-file / Find-in-Files pickers live inside the
     * editor widget, which is detached while an image or help tab is active. Hop
     * to the most recent text tab first; with none open, flash and refuse.
     */
    public function ensureTextEditorActive(): bool
    {
        $active = $this->buffers->active();
        if (null === $active || !$active->isViewer()) {
            return true;
        }
        if (null !== $this->activateAdjacentTextBuffer()) {
            return true;
        }
        $this->notifier->flash($this->translator->trans('Not available in this view'));

        return false;
    }

    /** Re-send didOpen for every open file, so a live LSP reconfigure relaunches its servers. */
    public function reopenAllInLsp(): void
    {
        foreach ($this->buffers->all() as $buffer) {
            $this->lsp->notifyDidOpen($buffer->path, $buffer->document->getText(), ProjectPaths::languageId($buffer->path), $buffer->document->getVersion());
        }
    }

    /**
     * Deliver published LSP diagnostics to the buffer whose file they are
     * about (matched by URI), updating the editor when it is the active one.
     *
     * @param array<int, list<array{severity: int, message: string, col: int, endCol: int}>> $byLine
     */
    public function applyDiagnostics(string $uri, array $byLine): void
    {
        $target = null;
        foreach ($this->buffers->all() as $buffer) {
            if ($uri !== Uri::fromPath($buffer->path)) {
                continue;
            }

            $target = $buffer;
            break;
        }
        if (null === $target) {
            return;
        }

        $target->diagnostics = $byLine;
        if ($target === $this->buffers->active()) {
            $this->editor->setDiagnostics($byLine);
        }
        // Announce the change so an open Problems tab refreshes live.
        $this->dispatcher->dispatch(new DiagnosticsChanged());
        $this->tui->requestRender();
    }

    private function relativePath(string $path): string
    {
        return ProjectPaths::relative($this->rootDir, $path);
    }
}
