<?php

declare(strict_types=1);

namespace Porta\Workbench\View;

use Porta\I18n\Translator;
use Porta\Layout\ViewRegion;
use Porta\Support\Scheduler;
use Porta\Ui\CursorStyle;
use Porta\Ui\Theme\Styles;

/**
 * What core hands a contributed full-screen view, and nothing else.
 *
 * The {@see \Porta\Editor\PaneServices} argument, applied to a view: a plugin
 * class can never be a container service — the container is compiled and cached
 * before any plugin PHP runs — so everything a plugin's object needs is
 * hand-passed, and growing this DTO is how "my view needs one more thing from
 * core" becomes visible in review.
 *
 * Six members, against the twelve a previous attempt measured and stopped at.
 * The difference is not restraint: a view that owns its REGION no longer needs
 * three layout slots, and a view whose main-area swap is core's job
 * ({@see ViewHost}) no longer needs the menu bar, the editor area or the pane
 * order. What is left is the four things its WIDGETS need plus the two handles.
 *
 * {@see $cursorStyle} is the one member with no `PaneServices` precedent,
 * because no editor pane has a text field: the git view's commit box builds a
 * `TextField` and a `MultilineInput` from it, and it is a live holder the
 * cursor-shape setting swaps — omit it and that setting silently stops applying
 * there.
 */
final readonly class ViewServices
{
    public function __construct(
        public Styles $styles,
        public Translator $translator,
        public Scheduler $scheduler,
        public CursorStyle $cursorStyle,
        public ViewRegion $region,
        public ViewHost $host,
    ) {}
}
