<?php

declare(strict_types=1);

namespace Porta\Overlay;

/**
 * Every floating region of the current frame: the Welcome modal's box first,
 * then the menu/context dropdowns — later regions win in the RTL segmenter's
 * per-cell owner pass, which is exactly the paint order (dropdowns render
 * above the modal, so they own the overlapping cells).
 */
final class CompositeFloatingRegions implements FloatingRegions
{
    public function __construct(
        private readonly WelcomeModalController $welcomeModal,
        private readonly FindModalPresenter $findModal,
        private readonly MenuOverlayController $menus,
    ) {}

    public function regions(): array
    {
        // The two floating modals are never open together (the WorkspaceViews
        // interlock), so at most one modal region joins the menus'.
        $modal = $this->welcomeModal->region() ?? $this->findModal->region();

        return [...(null !== $modal ? [$modal] : []), ...$this->menus->regions()];
    }
}
