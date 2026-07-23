<?php

declare(strict_types=1);

namespace Porta\Overlay;

/**
 * Every floating region of the current frame: the modal boxes first (Welcome
 * or Find in Files, then the pickers), then the menu/context dropdowns —
 * later regions win in the RTL segmenter's per-cell owner pass, which is
 * exactly the paint order (dropdowns render above every modal, so they own
 * the overlapping cells).
 */
final class CompositeFloatingRegions implements FloatingRegions
{
    public function __construct(
        private readonly WelcomeModalController $welcomeModal,
        private readonly FindModalPresenter $findModal,
        private readonly PickerModalPresenter $pickers,
        private readonly MenuOverlayController $menus,
    ) {}

    public function regions(): array
    {
        // The floating modals are never open together (the interlock: opening
        // any surface closes the others), so at most one family's regions
        // join the menus'.
        $modal = $this->welcomeModal->region() ?? $this->findModal->region();

        return [...(null !== $modal ? [$modal] : []), ...$this->pickers->regions(), ...$this->menus->regions()];
    }
}
