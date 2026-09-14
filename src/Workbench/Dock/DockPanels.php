<?php

declare(strict_types=1);

namespace Porta\Workbench\Dock;

use Porta\Container\AsIdeService;
use Porta\Widget\DockPanelWidget;

/**
 * The dock's SINGLE-TAB panels, keyed by identity rather than by field.
 *
 * The terminal and results panels are not here: each owns a whole tab FAMILY
 * (one strip entry per session, one per tool) with its own reorder and close
 * semantics. What is left over is the shape a contributor can actually have —
 * one panel, one strip entry, a title — which is exactly what the debug panel
 * has always been, and why it is the first member.
 *
 * A core panel registers with its {@see DockTab}; a contributed one registers
 * with **null** and an id. That is {@see DockToolTab}'s decision verbatim, for
 * its reason: lookups match the enum case first, so a shared case would let a
 * core caller reach a contributed panel, while a null can never equal an enum
 * case. The id also fixes identity, since two plugins may choose one title.
 */
#[AsIdeService]
final class DockPanels
{
    /** @var list<array{panel: DockPanelWidget, tab: ?DockTab, id: string, label: \Closure(): string}> */
    public private(set) array $panels = [];

    /**
     * @param \Closure(): string $label the strip entry's text, read per frame
     *                                  so a panel may retitle itself
     */
    public function register(DockPanelWidget $panel, string $id, \Closure $label, ?DockTab $tab = null): void
    {
        if ('' === $id) {
            // An invariant rather than a guard at lookup: a panel nothing can
            // name is a panel nothing can reveal or close, so the empty id is
            // refused where it is given rather than tolerated where it is used.
            throw new \InvalidArgumentException('a dock panel needs a non-empty id');
        }
        $this->panels[] = ['panel' => $panel, 'tab' => $tab, 'id' => $id, 'label' => $label];
    }

    /**
     * The panel a strip entry names.
     *
     * A null $tab matches by id ONLY — it can never equal an enum case, so a
     * core caller asking for {@see DockTab::Debug} can never land on a
     * contributed panel however it was named.
     */
    public function find(?DockTab $tab, string $id): ?DockPanelWidget
    {
        foreach ($this->panels as $entry) {
            if (null !== $tab ? $entry['tab'] === $tab : $entry['id'] === $id) {
                return $entry['panel'];
            }
        }

        return null;
    }
}
