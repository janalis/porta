<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Editor\EditorDisplayOptions;
use Porta\Event\ActiveBufferChanged;
use Porta\Event\BufferSaved;
use Porta\Event\LspStatusChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Keeps the code lenses pointed at the file you are looking at, on the same two
 * facts {@see StructurePanelRefresher} uses and for the same reason: a lens
 * count ("3 usages") is a real `textDocument/codeLens` round trip, so a save is
 * the settle point rather than every keystroke.
 *
 * Costs nothing while lenses are off, which is their default — the toggle is
 * checked here rather than in the controller so that turning them off stops the
 * REQUESTS, not just the painting.
 */
final readonly class CodeLensRefresher
{
    public function __construct(
        private CodeLensController $lenses,
        private EditorDisplayOptions $display,
    ) {}

    #[AsEventListener]
    public function onActiveBufferChanged(ActiveBufferChanged $event): void
    {
        $this->refresh();
    }

    #[AsEventListener]
    public function onBufferSaved(BufferSaved $event): void
    {
        $this->refresh();
    }

    /**
     * A server coming up, which neither of the other two covers: the file you
     * launch with is opened BEFORE any session exists, so its
     * `ActiveBufferChanged` finds no capability and asks nothing. Found while
     * fixing the same bug in {@see SemanticTokensRefresher} — the toggle path
     * in ViewCommands re-requests on its own, so only the launch file was
     * affected, and only with lenses already on.
     */
    #[AsEventListener]
    public function onLspStatusChanged(LspStatusChanged $event): void
    {
        $this->refresh();
    }

    private function refresh(): void
    {
        if ($this->display->codeLenses) {
            $this->lenses->requestCodeLenses();
        }
    }
}
