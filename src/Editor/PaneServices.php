<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\I18n\Translator;
use Porta\Support\Scheduler;
use Porta\Ui\Theme\Styles;
use Porta\Widget\ViewerTabHandler;

/**
 * What a plugin's pane is handed at construction, because a plugin class cannot
 * be a container service: container discovery globs `src/` only, the container
 * is compiled before any plugin PHP runs, and the compiled result is dumped and
 * reused across boots on a key that knows nothing about which plugins exist.
 * A pane is therefore hand-constructed, exactly as
 * {@see \Porta\Layout\WorkspaceLayout} hand-builds its containers.
 *
 * The four members are deliberately the whole list:
 *
 * - `$styles` is a HOLDER whose contents a theme switch replaces in place, so a
 *   pane that reads it per render follows the theme with nothing to subscribe to;
 * - `$translator` because a pane's status text and banners are user-facing;
 * - `$scheduler` because a pane that re-renders at a new resolution must debounce
 *   (the seam the fake in tests replaces);
 * - `$tabs` is the five-method {@see ViewerTabHandler} — tab cycling, close,
 *   go-to-file, save-all and the view-changed ping — re-bound because the code
 *   editor is detached while a pane holds the slot.
 *
 * Growing this DTO is how "my pane needs one more thing from core" becomes
 * visible in review. It should stay hard to add to.
 */
final readonly class PaneServices
{
    public function __construct(
        public Styles $styles,
        public Translator $translator,
        public Scheduler $scheduler,
        public ViewerTabHandler $tabs,
    ) {}
}
