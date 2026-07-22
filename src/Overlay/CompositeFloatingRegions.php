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
        private readonly MenuOverlayController $menus,
    ) {}

    public function regions(): array
    {
        $modal = $this->welcomeModal->region();

        return [...(null !== $modal ? [$modal] : []), ...$this->menus->regions()];
    }
}
