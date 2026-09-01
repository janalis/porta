<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Event\ActiveBufferChanged;
use Porta\Event\BufferSaved;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Keeps a standing Structure tab pointed at the file you are actually looking
 * at. Both hooks are facts, and both re-request through
 * {@see NavigationController::refreshStructurePanel()}, so
 * a refresh can never open the dock or move focus — and costs no language-server
 * round trip at all while the tab is closed.
 *
 * A save rather than every keystroke, deliberately: `documentSymbol` is a real
 * request, and this is the same settle point the git change marks already use.
 */
final readonly class StructurePanelRefresher
{
    public function __construct(
        private NavigationController $navigation,
    ) {}

    #[AsEventListener]
    public function onActiveBufferChanged(ActiveBufferChanged $event): void
    {
        $this->navigation->refreshStructurePanel();
    }

    #[AsEventListener]
    public function onBufferSaved(BufferSaved $event): void
    {
        $this->navigation->refreshStructurePanel();
    }
}
