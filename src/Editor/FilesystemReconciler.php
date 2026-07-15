<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Git\GitRefresher;
use Porta\I18n\Translator;
use Porta\Image\ImageDecoder;
use Porta\Lsp\LspSessionPool;
use Porta\Support\ProjectPaths;
use Porta\Ui\Notifier;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\ImageViewerWidget;
use Symfony\Component\Tui\Tui;

/**
 * Reconciles the open buffers and the file tree with on-disk state, driven by
 * the coordinator's 1s poll timer (via {@see BufferService::pollFilesystem()}).
 * Split out of BufferService because it is a self-contained lifecycle — it only
 * reacts to external file changes (reload clean buffers, flash dirty/deleted
 * ones, re-decode images) and shares none of the open/close/save flow's state.
 */
final class FilesystemReconciler
{
    public function __construct(
        private readonly BufferManager $buffers,
        private readonly FileTreeWidget $tree,
        private readonly Tui $tui,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly HelpViewerWidget $helpViewer,
        private readonly ImageViewerWidget $imageViewer,
        private readonly ImageDecoder $imageDecoder,
        private readonly LspSessionPool $lsp,
        private readonly GitRefresher $gitRefresher,
        private readonly string $rootDir,
    ) {}

    /** Reconcile every open buffer and the file tree with the on-disk state. */
    public function pollFilesystem(): void
    {
        foreach ($this->buffers->all() as $buffer) {
            $this->pollBuffer($buffer);
        }
        if ($this->tree->refreshIfChanged()) {
            $this->tui->requestRender();
        }
    }

    /** Detect and act on an external change to a single buffer's file. */
    private function pollBuffer(Buffer $buffer): void
    {
        $stamp = $buffer->diskStampNow();
        if (null === $stamp) {
            if (null !== $buffer->diskStamp) {
                $buffer->diskStamp = null; // flash once; keep the buffer so it can be re-saved
                $this->notifier->flash($this->translator->trans('%file% deleted on disk', ['%file%' => $this->relativePath($buffer->path)]));
                $this->tui->requestRender();
            }

            return;
        }
        if ($stamp === $buffer->diskStamp) {
            return;
        }

        // The file changed on disk. Record the new stamp either way so we react once.
        $buffer->diskStamp = $stamp;
        if ($buffer->isImage()) {
            $this->reloadImageBuffer($buffer);

            return;
        }
        if ($buffer->isHelp()) {
            $content = $this->readTextFile($buffer->path);
            if (null !== $content) {
                $buffer->helpMarkdown = $content;
                if ($this->buffers->active() === $buffer && $buffer->path === $this->helpViewer->path()) {
                    $this->helpViewer->show($buffer->path, $content);
                }
                $this->notifier->flash($this->translator->trans('Reloaded %file%', ['%file%' => $this->relativePath($buffer->path)]));
                $this->tui->requestRender();
            }

            return;
        }
        $content = $this->readTextFile($buffer->path);
        if (null === $content) {
            return; // unreadable / too large / binary now — leave the buffer untouched
        }
        if ($buffer->isDirty()) {
            $this->notifier->flash($this->translator->trans('%file% changed on disk', ['%file%' => $this->relativePath($buffer->path)]));
            $this->tui->requestRender();

            return;
        }
        $this->reloadBuffer($buffer, $content);
    }

    /** Re-decode an externally changed image; undecodable new bytes keep the old pixels. */
    private function reloadImageBuffer(Buffer $buffer): void
    {
        $image = (int) filesize($buffer->path) > ImageDecoder::MAX_BYTES ? null : $this->imageDecoder->decode($buffer->path);
        if (null === $image) {
            return;
        }
        $buffer->image = $image;
        if ($buffer === $this->buffers->active()) {
            $this->imageViewer->show($image);
        }
        $this->notifier->flash($this->translator->trans('Reloaded %file%', ['%file%' => $this->relativePath($buffer->path)]));
        $this->tui->requestRender();
    }

    /** Replace a clean buffer's content with the new on-disk text, keeping it clean. */
    private function reloadBuffer(Buffer $buffer, string $content): void
    {
        $buffer->document->replaceAll($content);
        $buffer->document->markSaved();
        $this->lsp->notifyFullChange($buffer->path, $buffer->document); // no-op when no server is running
        $this->gitRefresher->refreshMarks($buffer);
        $this->notifier->flash($this->translator->trans('Reloaded %file%', ['%file%' => $this->relativePath($buffer->path)]));
        $this->tui->requestRender();
    }

    /** Read a small UTF-8 text file, or null if missing, unreadable, too large or binary. */
    private function readTextFile(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path) || (int) filesize($path) > (2 * 1024 * 1024)) {
            return null;
        }
        $content = (string) file_get_contents($path);

        return '' === $content || mb_check_encoding($content, encoding: 'UTF-8') ? $content : null;
    }

    private function relativePath(string $path): string
    {
        return ProjectPaths::relative($this->rootDir, $path);
    }
}
