<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Workbench\Notifier;

/**
 * Says so when a global save will not take effect here.
 *
 * Writing to the user file while the project pins the same key produces a
 * change that applies for this session and reverts on the next start — the
 * screen shows a new value, the project decides the old one. The row's
 * `overrides global` tag carries the fact but not the consequence, and a tag
 * is not where you look at the moment you press a key.
 *
 * Deliberately a warning rather than a refusal. A user setting a personal
 * default for a key that some open project happens to pin is doing something
 * reasonable, and the write is correct — it is only this project that ignores
 * it. Refusing would make the global default unreachable from inside the
 * project that shadows it.
 */
#[AsIdeService]
final readonly class ShadowedWriteWarning
{
    public function __construct(
        private SettingsScope $scope,
        private ConfigFiles $files,
        private Translator $translator,
        private Notifier $notifier,
    ) {}

    /**
     * A value was written to the user's file while this project pins the same
     * key: the row shows what was just written, the project decides what is in
     * effect, and next start the write disappears from view.
     */
    public function afterSave(?SettingsRow $row): void
    {
        $this->warnIf($row, 'Saved to %global% — %project% overrides it in this project');
    }

    /**
     * A value was REMOVED from the user's file while this project still pins
     * it, so the reset changed nothing anyone can see.
     *
     * Its own message: "saved" after pressing `r` names the wrong action, and
     * the action is half of what makes the sentence explain anything.
     */
    public function afterReset(?SettingsRow $row): void
    {
        $this->warnIf($row, 'Cleared from %global% — %project% still overrides it here');
    }

    /**
     * One question for both paths: after this write, does the PROJECT decide
     * the value on screen?
     *
     * It reads `origin` rather than `shadowed`, and that is not
     * interchangeable even though it looks it. After a save the two agree —
     * the global file necessarily sets the key it was just given, so "both
     * files set it" and "the project wins" are the same fact, and a mutation
     * swapping them is equivalent rather than caught. After a RESET they
     * disagree: clearing the global value is exactly what makes `shadowed`
     * false, so a shadow test can never fire on the path that needs it most.
     */
    private function warnIf(?SettingsRow $row, string $message): void
    {
        if (ConfigScope::Project !== $row?->origin || ConfigScope::Global !== $this->scope->current()) {
            return;
        }

        // warn(), not flash(): this is a consequence, not a confirmation, and
        // it has to outlast the toast it follows. notify() already requests a
        // render.
        $this->notifier->warn($this->translator->trans($message, [
            '%global%' => $this->files->label(ConfigScope::Global),
            '%project%' => $this->files->label(ConfigScope::Project),
        ]));
    }
}
