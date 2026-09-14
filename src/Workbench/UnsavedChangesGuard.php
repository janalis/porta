<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Container\AsIdeService;
use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\Support\Clocks;
use Psr\Clock\ClockInterface;

/**
 * The two-press confirm in front of anything that ends a session with unsaved
 * edits in it.
 *
 * Extracted from {@see WorkbenchActions::quit()}, which was the only caller and
 * therefore the only protected path. Quitting warned; switching project did
 * not, and neither did an LSP rename or delete — three ways past one guard, and
 * the IDE has no autosave, so "past the guard" means the edits are gone. The
 * teardown writes the session, the debug state and the bookmarks; it has never
 * written buffer contents.
 *
 * The window is deliberately per-VERB rather than shared. A press that warned
 * about quitting must not arm a project switch: the second press has to confirm
 * the thing the first one was refused for, or the confirm is a trap.
 */
#[AsIdeService]
final class UnsavedChangesGuard
{
    /** Long enough to read the flash, short enough that a later keystroke is a fresh intent. */
    private const float WINDOW_SECONDS = 3.0;

    /** @var array<string, float> verb => when it was last refused */
    private array $lastRefusal = [];

    public function __construct(
        private readonly BufferManager $buffers,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Whether $verb may proceed: true with nothing dirty, or on a second ask
     * within the window. Otherwise it flashes what would be lost and returns
     * false.
     *
     * @param string $verb   an id for the window, so two different verbs cannot
     *                       confirm each other
     * @param string $repeat the already-translated "press X again" clause, which
     *                       only the caller knows
     */
    public function confirmed(string $verb, string $repeat): bool
    {
        $dirty = $this->buffers->dirtyCount();
        if (0 === $dirty) {
            return true;
        }
        $now = Clocks::seconds($this->clock);
        if (($now - ($this->lastRefusal[$verb] ?? 0.0)) <= self::WINDOW_SECONDS) {
            return true;
        }

        $this->lastRefusal[$verb] = $now;
        $noun = $this->translator->trans('1 file has|%count% files have', ['%count%' => $dirty]);
        $this->notifier->flash($this->translator->trans('Unsaved changes — %noun% edits. %repeat%', [
            '%noun%' => $noun,
            '%repeat%' => $repeat,
        ]), 3.0);

        return false;
    }
}
