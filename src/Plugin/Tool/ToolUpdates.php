<?php

declare(strict_types=1);

namespace Porta\Plugin\Tool;

use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Plugin\Lifecycle\PluginEnablement;
use Porta\Workbench\Notifier;
use Revolt\EventLoop;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The background "a newer version is out" check, for every tool Porta
 * manages of every enabled plugin — at most once per the `toolUpdateCheck`
 * cadence (daily by default, weekly, or off), off every render path, silent
 * when the network is not there.
 *
 * What it finds is said ONCE, in one toast naming every outdated tool, and
 * kept: each tool's Settings row reads "1.2.0 → 1.3.0 available" from the
 * note until the next check, without asking anyone again.
 *
 * Only tools Porta could update are asked about: a binary someone built by
 * hand, or one a system package owns, is not Porta's to offer to replace.
 */
#[AsIdeService]
final class ToolUpdates
{
    /** The `toolUpdateCheck` choices, and the seconds between checks each means; 0 is off. */
    public const array CADENCES = ['off' => 0, 'daily' => ToolUpdateCheck::DAY, 'weekly' => 604_800];

    /** How often a long-running IDE looks whether a check has come due. */
    private const float LOOK_EVERY = 3600.0;

    private string $cadence;

    private bool $checking = false;

    private ?string $timer = null;

    /** @var array<string, string>|null `<plugin>/<tool>` => its newest version, read from the note once */
    private ?array $latest = null;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param array<string, int|bool|string> $settings resolved settings (defaults merged)
     * @param (\Closure(): int)|null $clock seconds since the epoch; inject to test
     */
    public function __construct(
        #[Autowire('%env(json:PORTA_SETTINGS)%')]
        array $settings,
        private readonly ToolCatalog $catalog,
        private readonly LatestVersions $versions,
        private readonly PluginEnablement $plugins,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly ToolUpdateCheck $check,
        ?\Closure $clock = null,
    ) {
        $cadence = $settings['toolUpdateCheck'] ?? 'daily';
        $this->cadence = \is_string($cadence) && \array_key_exists($cadence, self::CADENCES) ? $cadence : 'daily';
        $this->clock = $clock ?? \time(...);
    }

    /**
     * Once the IDE is up: check if a check is due, and look again every hour,
     * for an IDE left open for days. Nothing is spawned unless one is due —
     * a launch on a day already checked costs no process at all.
     */
    public function start(): void
    {
        $this->checkIfDue();
        if (null === $this->timer) {
            $this->timer = EventLoop::repeat(self::LOOK_EVERY, $this->checkIfDue(...));
            EventLoop::unreference($this->timer);
        }
    }

    /** The hourly look ends with the IDE, or it would outlive the loop that runs it. */
    public function stop(): void
    {
        if (null !== $this->timer) {
            EventLoop::cancel($this->timer);
            $this->timer = null;
        }
    }

    /** The `toolUpdateCheck` setting moved; switched on, a due check runs now. */
    public function setCadence(string $cadence): void
    {
        $this->cadence = \array_key_exists($cadence, self::CADENCES) ? $cadence : 'daily';
        $this->checkIfDue();
    }

    /** The newer version the last check saw for $status's tool, or null when it is current or unknown. */
    public function newer(ToolStatus $status): ?string
    {
        $this->latest ??= $this->check->latest();
        $latest = $this->latest[self::key($status->tool)] ?? null;

        return LatestVersions::newer($status->version, $latest) ? $latest : null;
    }

    public function checkIfDue(): void
    {
        $every = self::CADENCES[$this->cadence];
        $now = ($this->clock)();
        if (0 === $every || $this->checking || !$this->check->due($now, $every)) {
            return;
        }
        $this->checking = true;
        // Their versions first: "newer" means newer than what is installed.
        $this->catalog->probe($this->enabledPlugins(), function () use ($now): void {
            $statuses = \array_values(\array_filter($this->enabledStatuses(), static fn (ToolStatus $status): bool => null !== $status->manager && ToolManager::Plugin !== $status->manager->manager));
            $this->ask($statuses, $now, []);
        });
    }

    /**
     * One at a time, so a check of ten tools is not ten requests at once.
     *
     * @param list<ToolStatus>      $left
     * @param array<string, string> $found
     */
    private function ask(array $left, int $now, array $found): void
    {
        $status = \array_shift($left);
        if (null === $status) {
            $this->finish($now, $found);

            return;
        }
        $this->versions->of($status->tool, $status->manager ?? new ToolSource(ToolManager::Plugin, ''), function (?string $version) use ($status, $left, $now, $found): void {
            if (null !== $version) {
                $found[self::key($status->tool)] = $version;
            }
            $this->ask($left, $now, $found);
        });
    }

    /** @param array<string, string> $found */
    private function finish(int $now, array $found): void
    {
        $this->checking = false;
        // Recorded whatever was found: the point of the note is that this
        // machine LOOKED, not that it found something.
        $this->check->record($now, $found);
        $this->latest = null;
        $outdated = [];
        foreach ($this->enabledStatuses() as $status) {
            $newer = $this->newer($status);
            if (null !== $newer) {
                $outdated[] = $status->tool->name.' '.$newer;
            }
        }
        if ([] !== $outdated) {
            $this->notifier->flash($this->translator->trans('Updates available: %tools% — run "Plugins: Update Tools"', ['%tools%' => \implode(', ', $outdated)]), 8.0);
        }
    }

    /** @return list<string> */
    private function enabledPlugins(): array
    {
        return \array_values(\array_filter($this->plugins->listedNames(), fn (string $plugin): bool => !$this->plugins->isDisabled($plugin)));
    }

    /** @return list<ToolStatus> every installed tool of the enabled plugins */
    private function enabledStatuses(): array
    {
        $statuses = [];
        foreach ($this->enabledPlugins() as $plugin) {
            foreach ($this->catalog->statuses($plugin) as $status) {
                if (!$status->available) {
                    continue;
                }
                $statuses[] = $status;
            }
        }

        return $statuses;
    }

    private static function key(ToolRequirement $tool): string
    {
        return $tool->plugin.'/'.$tool->name;
    }
}
