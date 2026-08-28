<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Container\AsIdeService;
use Porta\Event\SettingsSearchRequested;
use Porta\Search\Everywhere\SearchEverywhereController;
use Porta\Search\Everywhere\SearchEverywhereFactory;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * "Find Setting": the centered picker, scoped to settings.
 *
 * The surface is the existing overlay rather than a filter row inside the
 * view. `PickerModalPresenter` reads `OverlayHost` directly *because* pickers
 * went dark under a full-screen view, and `InputDispatcher` consults
 * `isSearchOpen()` ahead of any view path — so Esc keeps its one meaning and
 * the settings view needs no third focus stop, no region surgery and no caret
 * negotiation with a widget that already owns the keyboard.
 *
 * The controller is built once and reused. Each `open()` bumps its own
 * generation and clears its hits, so a second open is a fresh picker; building
 * a new one per keypress would only add garbage.
 */
#[AsIdeService]
final class SettingsSearchLauncher
{
    private ?SearchEverywhereController $picker = null;

    public function __construct(
        private readonly SearchEverywhereFactory $pickers,
        private readonly SettingsSearchSource $source,
    ) {}

    #[AsEventListener]
    public function onSearchRequested(SettingsSearchRequested $event): void
    {
        $this->open();
    }

    public function open(): void
    {
        $this->picker ??= $this->pickers->createScoped($this->source, 'Find Setting');
        $this->picker->open();
    }
}
