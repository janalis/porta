<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Container\AsIdeService;
use Porta\Support\JsonFile;

/**
 * Which file the settings screen writes to, for this session.
 *
 * ONE control, not one per page. The Plugins page already had a session-only
 * "Install into" chooser with exactly these two values; a second scope row
 * beside it, meaning almost the same thing and applying to different writes,
 * is a bug report on day one. That row is this holder now, and the plugin
 * installer reads the same answer everything else does.
 *
 * Session-only on purpose, as its predecessor was. Persisting the choice would
 * need a file to persist it in, and the choice is which file — a question that
 * answers itself wrongly in at least one direction.
 *
 * The default is the one decision here that could surprise someone:
 *
 * - A NEW user gets Global. A theme is a preference, and writing it into a
 *   tracked file is what this whole scope exists to stop.
 *
 * - Someone whose project `.porta.json` ALREADY has a `settings` block gets
 *   Project, because their next edit landing somewhere new — while the old
 *   values sit visibly in the file, still overriding it — is the surprising
 *   outcome, not the safe one. One predicate buys away almost all of the
 *   upgrade surprise.
 *
 * Presence is the right question here, and it is the one case where it is:
 * this asks "has this project been configured", not "what is this value", and
 * an absent key and a key set to its default answer that differently.
 */
#[AsIdeService]
final class SettingsScope
{
    private ConfigScope $current;

    public function __construct(
        private readonly ConfigFiles $files,
    ) {
        $this->current = $this->initial();
    }

    public function current(): ConfigScope
    {
        return $this->current;
    }

    public function set(ConfigScope $scope): void
    {
        $this->current = $scope;
    }

    /**
     * The scope's own value as the installer spells it — `project` / `global`,
     * which is the vocabulary `PluginInstaller` already takes.
     */
    public function installerScope(): string
    {
        return $this->current->value;
    }

    private function initial(): ConfigScope
    {
        // A project path is never null in practice — only the global one can
        // be — and '' is not a file, so read() answers null either way rather
        // than needing a branch nothing can take.
        $config = JsonFile::read($this->files->path(ConfigScope::Project) ?? '') ?? [];
        if (\is_array($config['settings'] ?? null) && [] !== $config['settings']) {
            return ConfigScope::Project;
        }

        // No global file to write into is the one case where Global is not
        // offerable at all; the row hides itself, and this must not hand back
        // a scope whose writes would fail.
        return null === $this->files->path(ConfigScope::Global) ? ConfigScope::Project : ConfigScope::Global;
    }
}
