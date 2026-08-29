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
     * Warn when $row — freshly rebuilt after the write — reports that the
     * project shadows it, and the write went to the global file.
     *
     * `shadowed` alone is not the trigger: with the scope on the project, a
     * project value covering a global one is the ordinary case and exactly
     * what the user asked for.
     */
    public function afterWrite(?SettingsRow $row): void
    {
        if (ConfigScope::Global !== $this->scope->current() || null === $row || !$row->shadowed) {
            return;
        }

        // warn(), not flash(): this is a consequence, not a confirmation, and
        // it has to outlast the "saved" toast it follows. notify() already
        // requests a render.
        $this->notifier->warn($this->translator->trans('Saved to %global% — %project% overrides it in this project', [
            '%global%' => $this->files->label(ConfigScope::Global),
            '%project%' => $this->files->label(ConfigScope::Project),
        ]));
    }
}
