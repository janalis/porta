<?php

declare(strict_types=1);

namespace Porta\Overlay\Region;

use Porta\Overlay\Presenter\FindModalPresenter;
use Porta\Overlay\Presenter\PickerModalPresenter;
use Porta\Overlay\Presenter\ToastPresenter;
use Porta\Overlay\Presenter\WelcomeModalController;
use Porta\Overlay\State\MenuOverlayController;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Every floating region of the current frame: the modal boxes first (Welcome
 * or Find in Files, then the pickers), then the menu/context dropdowns —
 * later regions win in the RTL segmenter's per-cell owner pass, which is
 * exactly the paint order (dropdowns render above every modal, so they own
 * the overlapping cells).
 */
#[AsAlias(FloatingRegions::class)] // the seam has fakes in tests, so the loader derives nothing
final readonly class CompositeFloatingRegions implements FloatingRegions
{
    public function __construct(
        private WelcomeModalController $welcomeModal,
        private FindModalPresenter $findModal,
        private PickerModalPresenter $pickers,
        private ToastPresenter $toasts,
        private MenuOverlayController $menus,
    ) {}

    #[\Override]
    public function regions(): array
    {
        // The floating modals are never open together (the interlock: opening
        // any surface closes the others), so at most one family's regions
        // join the toasts' and the menus'.
        $modal = $this->welcomeModal->region() ?? $this->findModal->region();

        return [...(null !== $modal ? [$modal] : []), ...$this->pickers->regions(), ...$this->toasts->regions(), ...$this->menus->regions()];
    }
}
