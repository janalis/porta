<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\ResultsPanelMode;

/**
 * PhpStorm's Recent Locations: the jump history as a list you pick from,
 * instead of navigate-back's one pop at a time.
 *
 * Showing it does NOT consume the history — the whole point is to reach the
 * fifth place back without walking through four you did not want.
 */
#[AsIdeService]
final readonly class RecentLocationActions
{
    public function __construct(
        private RecentLocationRows $rows,
        private BottomDock $dock,
        private Translator $translator,
    ) {}

    public function show(): void
    {
        $this->dock->showList(ResultsPanelMode::RecentLocations, $this->translator->trans('Recent Locations'), $this->rows->build());
    }
}
