<?php

declare(strict_types=1);

namespace Porta\History;

use Porta\Container\AsIdeService;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
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
            if (!\is_file($buffer['path'])) {
                continue;
            }
            $this->bufferService->openFile($buffer['path']);
            $active = $this->buffers->active();
            if (null !== $active) {
                $active->document->setCursor($buffer['line'], $buffer['col']);
                $active->pinned = $buffer['pinned'];
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

    public function save(): void
    {
        $buffers = [];
        foreach ($this->buffers->all() as $buffer) {
            if ('help' === $buffer->paneId) {
                continue; // a restored help path would reopen as editable source
            }
            $buffers[] = [
                'path' => $buffer->path,
                'line' => $buffer->document->cursorLine,
                'col' => $buffer->document->cursorCol,
                'pinned' => $buffer->pinned,
            ];
        }
        $this->store->save($buffers, $this->buffers->active()?->path);
    }
}
