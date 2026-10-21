<?php

declare(strict_types=1);

namespace Porta\Plugin\Tool;

use Porta\Config\CommandRegistry;
use Porta\I18n\Translator;
use Porta\Plugin\Lifecycle\PluginLifecycleOps;
use Porta\Workbench\Notifier;

/**
 * Every install and update a person asks for — from a plugin's Settings
 * page, the palette, a Welcome card — run one at a time, said in one place.
 *
 * One at a time because two managers writing the same prefix, or two
 * downloads renaming over one path, is a race nobody asked for; a request
 * made while one runs waits its turn instead of being refused, which is what
 * makes "install every missing tool" one command. The toast is ONE slot that
 * each job's progress replaces in place, and its outcome takes over the same
 * card.
 *
 * A finished install is a binary appearing on disk: the plugin's language
 * server relaunches and the open Settings page re-reads ({@see PluginLifecycleOps::onServerBinaryReplaced()}).
 */
final class ToolJobs
{
    private const string SLOT = 'tool.install';

    /** @var list<array{tool: ToolRequirement, source: ToolSource, path: string|null}> waiting, oldest first; path set for an update */
    private array $queue = [];

    private ?ToolRequirement $running = null;

    public function __construct(
        private readonly ToolInstaller $installer,
        private readonly ToolCatalog $catalog,
        private readonly ToolVersions $versions,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly PluginLifecycleOps $lifecycle,
        // Trailing and nullable so a direct construction needs none: only a
        // plugin's OWN installer reaches for it.
        private readonly ?CommandRegistry $commands = null,
    ) {}

    /** Whether $tool is installing or updating now, or waiting to. */
    public function pending(ToolRequirement $tool): bool
    {
        return self::same($this->running, $tool) || null !== \array_find($this->queue, static fn (array $job): bool => self::same($job['tool'], $tool));
    }

    /** Whether an install of $tool has a source this machine can use. */
    public function installable(ToolRequirement $tool): bool
    {
        return null !== $this->installer->installSource($tool);
    }

    /** Queue an install of $tool through the first source usable here, or say why there is none. */
    public function install(ToolRequirement $tool): void
    {
        $source = $this->installer->installSource($tool);
        match (true) {
            $this->pending($tool) => $this->notifier->flash($this->translator->trans('%tool% is already queued', ['%tool%' => $tool->name])),
            null === $source => $this->notifier->error($this->noSource($tool), 6.0),
            default => $this->queue($tool, $source, null),
        };
    }

    /** Queue an update of $tool through whatever manages the installed copy, or say why it cannot be. */
    public function update(ToolRequirement $tool): void
    {
        $status = $this->catalog->status($tool);
        match (true) {
            $this->pending($tool) => $this->notifier->flash($this->translator->trans('%tool% is already queued', ['%tool%' => $tool->name])),
            !$status->available => $this->install($tool),
            null === $status->manager => $this->notifier->warn($this->translator->trans('%tool% is managed outside Porta — update it the way it was installed', ['%tool%' => $tool->name])),
            default => $this->queue($tool, $status->manager, $status->path),
        };
    }

    /**
     * Queue every missing REQUIRED tool of $plugins that can be installed
     * here, except through the $except managers; the count queued. A
     * suggested tool is left alone: it is a choice, and "install what is
     * missing" is about what does not work.
     *
     * @param list<string> $plugins
     */
    public function installMissing(array $plugins, ToolManager ...$except): int
    {
        $queued = 0;
        foreach ($this->statusesOf($plugins) as $status) {
            $source = $this->installer->installSource($status->tool);
            if (!$status->blocks() || $this->pending($status->tool) || null === $source || \in_array($source->manager, $except, strict: true)) {
                continue;
            }
            $this->install($status->tool);
            ++$queued;
        }

        return $queued;
    }

    /**
     * Queue an update of every installed tool of $plugins that Porta manages;
     * the count queued.
     *
     * @param list<string> $plugins
     */
    public function updateAll(array $plugins): int
    {
        $queued = 0;
        foreach ($this->statusesOf($plugins) as $status) {
            if (!$status->available || null === $status->manager || $this->pending($status->tool)) {
                continue;
            }
            $this->update($status->tool);
            ++$queued;
        }

        return $queued;
    }

    /**
     * @param list<string> $plugins
     *
     * @return list<ToolStatus>
     */
    private function statusesOf(array $plugins): array
    {
        return \array_merge(...\array_map($this->catalog->statuses(...), $plugins));
    }

    private function queue(ToolRequirement $tool, ToolSource $source, ?string $path): void
    {
        // A plugin's own installer reports, relaunches and sweeps on its own
        // terms; there is nothing to wait for here, so it never queues.
        if (ToolManager::Plugin === $source->manager) {
            $this->commands?->run($tool->plugin.':'.$source->package);

            return;
        }
        $this->queue[] = ['tool' => $tool, 'source' => $source, 'path' => $path];
        if (null !== $this->running) {
            $this->notifier->flash($this->translator->trans('%tool% will install next', ['%tool%' => $tool->name]));

            return;
        }
        $this->next();
    }

    private function next(): void
    {
        $job = \array_shift($this->queue);
        if (null === $job) {
            return;
        }
        $tool = $job['tool'];
        $this->running = $tool;
        $path = $job['path'];
        $this->notifier->flash($this->translator->trans(null === $path ? 'Installing %tool%…' : 'Updating %tool%…', ['%tool%' => $tool->name]), 600.0, self::SLOT);
        $onProgress = function (InstallStep $step, int $percent) use ($tool): void {
            $this->notifier->flash($this->progress($tool, $step, $percent), 600.0, self::SLOT);
        };
        $onDone = function (ToolResult $result) use ($tool, $path): void {
            $this->finish($tool, $path, $result);
        };
        null === $path
            ? $this->installer->install($tool, $job['source'], $onProgress, $onDone)
            : $this->installer->update($tool, $job['source'], $path, $onProgress, $onDone);
    }

    private function finish(ToolRequirement $tool, ?string $path, ToolResult $result): void
    {
        // What the copy named before, then ask it again: a package manager
        // reports no version of its own, and only the binary says whether an
        // "update" moved anything.
        $before = null === $path ? '' : $this->versions->known($path);
        if (null !== $path) {
            $this->versions->forget($path);
        }
        $this->catalog->probe([$tool->plugin], function () use ($tool, $path, $result, $before): void {
            $this->report($tool, $path, $this->asProbed($tool, $result, $before));
        });
    }

    /**
     * $result with the version the binary names now. An update that left it
     * where it was is said as such — `composer update` within a constraint
     * that allows nothing newer still exits 0.
     */
    private function asProbed(ToolRequirement $tool, ToolResult $result, string $before): ToolResult
    {
        $now = $this->catalog->status($tool)->version;
        if (!$result->succeeded() || '' !== $result->version || '' === $now) {
            return $result;
        }

        return new ToolResult(ToolOutcome::Updated === $result->outcome && $now === $before ? ToolOutcome::UpToDate : $result->outcome, $now);
    }

    private function report(ToolRequirement $tool, ?string $path, ToolResult $result): void
    {
        $this->running = null;
        $name = ['%tool%' => $tool->name, '%version%' => $result->version, '%why%' => $result->why];
        match ($result->outcome) {
            ToolOutcome::Installed => $this->notifier->success($this->translator->trans('' === $result->version ? 'Installed %tool%' : 'Installed %tool% %version%', $name), 5.0, self::SLOT),
            ToolOutcome::Updated => $this->notifier->success($this->translator->trans('' === $result->version ? 'Updated %tool%' : 'Updated %tool% to %version%', $name), 5.0, self::SLOT),
            ToolOutcome::UpToDate => $this->notifier->flash($this->translator->trans('%tool% is already up to date (%version%)', $name), 4.0, self::SLOT),
            ToolOutcome::Failed => $this->notifier->error($this->translator->trans(null === $path ? 'Could not install %tool%: %why%' : 'Could not update %tool%: %why%', $name), 6.0, self::SLOT),
        };
        // Whatever the outcome, the open Settings page re-reads itself: its
        // button read "Working…" until now.
        $this->lifecycle->refreshView();
        if (ToolOutcome::Installed === $result->outcome || ToolOutcome::Updated === $result->outcome) {
            // A binary appeared or was replaced: relaunch the plugin's server.
            $this->lifecycle->onServerBinaryReplaced($tool->plugin);
        }
        $this->next();
    }

    private function progress(ToolRequirement $tool, InstallStep $step, int $percent): string
    {
        $params = ['%tool%' => $tool->name, '%percent%' => $percent];

        return $this->translator->trans(match ($step) {
            InstallStep::Resolving => '%tool%: resolving…',
            InstallStep::Downloading => 0 === $percent ? '%tool%: downloading…' : '%tool%: downloading %percent%%',
            InstallStep::Verifying => '%tool%: verifying…',
            InstallStep::Extracting => '%tool%: extracting…',
            InstallStep::Checking => '%tool%: checking the binary…',
        }, $params);
    }

    /** Why $tool cannot be installed here, naming what would make it possible. */
    private function noSource(ToolRequirement $tool): string
    {
        $managers = \array_values(\array_unique(\array_map(static fn (ToolSource $source): string => match ($source->manager) {
            ToolManager::Github => 'GitHub',
            ToolManager::BrewCask => 'brew',
            default => $source->manager->value,
        }, $tool->sources)));

        return (
            [] === $managers
                ? $this->translator->trans('Porta cannot install %tool% — put it on your PATH, or set its path in the plugin\'s settings', ['%tool%' => $tool->name])
                : $this->translator->trans('No way to install %tool% here (needs %managers%) — put it on your PATH, or set its path in the plugin\'s settings', [
                    '%tool%' => $tool->name,
                    '%managers%' => \implode(', ', $managers),
                ])
        );
    }

    private static function same(?ToolRequirement $a, ToolRequirement $b): bool
    {
        return null !== $a && $a->plugin === $b->plugin && $a->name === $b->name;
    }
}
