<?php

declare(strict_types=1);

namespace Porta\History;

use Porta\Config\IdeConfig;
use Porta\Container\AsIdeService;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\PreviewMode;
use Porta\Workbench\EditorArea;
use Symfony\Component\Tui\Tui;

/**
 * Persists and restores the open-buffer set across launches: which files were
 * open, each cursor position, and which tab was active. Help buffers are
 * skipped on save — a restored help path would reopen as editable source.
 */
#[AsIdeService]
final readonly class SessionRestorer
{
    public function __construct(
        private SessionStore $store,
        private BufferManager $buffers,
        private BufferService $bufferService,
        private EditorArea $editorArea,
        private Tui $tui,
        private IdeConfig $config,
        private string $rootDir,
    ) {}

    /**
     * Reopen the previous session's buffers. Returns true if any were opened.
     */
    public function restore(): bool
    {
        $data = $this->store->load();
        if (null === $data || [] === $data['buffers']) {
            return false;
        }

        $opened = false;
        foreach ($data['buffers'] as $buffer) {
            if (!\is_file($buffer['path']) || !$this->mayReopen($buffer['path'])) {
                continue;
            }
            $this->bufferService->openFile($buffer['path']);
            $active = $this->buffers->active();
            if (null !== $active) {
                $active->document->setCursor($buffer['line'], $buffer['col']);
                $active->pinned = $buffer['pinned'];
                $this->rearrange($active, $buffer['preview']);
            }
            $opened = true;
        }

        $activePath = $data['active'];
        if ($opened && null !== $activePath) {
            $resolved = \realpath($activePath);
            $key = $resolved ? $resolved : $activePath;
            if ($this->buffers->has($key)) {
                /** @var Buffer $active */
                $active = $this->buffers->setActive($key);
                $this->bufferService->showBuffer($active);
            }
        }
        if ($opened) {
            $this->tui->setFocus($this->editorArea->widget());
        }

        return $opened;
    }

    /**
     * A restricted project's `.porta/session.json` may have come with the
     * clone as easily as from the user: it reopens the project's own files,
     * never one elsewhere on the machine — a `../../.ssh/id_rsa` it named
     * would sit open in a tab, where an agent reading the open buffers
     * reaches it. A link inside the project is followed to where it lands
     * before that is asked.
     */
    private function mayReopen(string $path): bool
    {
        $root = \realpath($this->rootDir);
        $real = \realpath($path);

        return $this->config->trusted || (false !== $root && false !== $real && \str_starts_with($real, \rtrim($root, characters: '/').'/'));
    }

    /**
     * Put a previewed tab back in the arrangement it was left in — text,
     * both, or the preview alone. A tab that no longer has a preview (its
     * plugin is off now) and an arrangement this version does not know keep
     * what opening gave them.
     */
    private function rearrange(Buffer $buffer, ?string $saved): void
    {
        // arrangePreview() itself refuses a tab with no preview.
        $mode = PreviewMode::tryFrom((string) $saved);
        if (null !== $mode) {
            $this->bufferService->arrangePreview($buffer, $mode);
        }
    }

    public function save(): void
    {
        $buffers = [];
        foreach ($this->buffers->all() as $buffer) {
            $buffers[] =
                [
                    'path' => $buffer->path,
                    'line' => $buffer->document->cursorLine,
                    'col' => $buffer->document->cursorCol,
                    'pinned' => $buffer->pinned,
                ] + (null === ($mode = $buffer->previewArrangement()) ? [] : ['preview' => $mode->value]);
        }
        $this->store->save($buffers, $this->buffers->active()?->path);
    }
}
