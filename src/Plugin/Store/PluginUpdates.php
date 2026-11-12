<?php

declare(strict_types=1);

namespace Porta\Plugin\Store;

use Composer\Semver\Semver;
use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Plugin\Lifecycle\InstallScope;
use Porta\Plugin\Lifecycle\PluginInstaller;
use Porta\Plugin\Lifecycle\PluginLifecycleOps;
use Porta\Plugin\Lifecycle\PluginUpdateMode;
use Porta\Plugin\Runtime\PluginLoader;
use Porta\Plugin\Tool\LatestVersions;
use Porta\Plugin\Tool\ToolUpdates;
use Porta\Support\HourlyLook;
use Porta\Support\ProcessEnvironment;
use Porta\Workbench\Notifier;
use Porta\Workbench\UpdateRestart;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Which installed plugins have a newer release in the store, and bringing
 * them up to it — on request (the store's Update and Update all), and in the
 * background at the `pluginUpdateCheck` cadence: a check refreshes the
 * catalog, then either says once what could be updated, or — with
 * `pluginAutoUpdate` on — updates it.
 *
 * Only what Porta installed from Packagist is offered: a plugin required from
 * a path or a git URL follows its own source, a hand-placed directory is
 * nobody's package, and a bundled plugin updates with Porta. An untrusted
 * project's own copies are not even looked at — they do not load.
 *
 * A running plugin is stopped for the update and swapped live for its new
 * version ({@see runs()}).
 * Only a class it had already loaded keeps its old code until the next
 * launch — PHP cannot unload one — and the update names that plugin.
 */
#[AsIdeService]
final class PluginUpdates
{
    /** The `pluginUpdateCheck` choices, and the seconds between checks each means; 0 is off — the tools' own. */
    public const array CADENCES = ToolUpdates::CADENCES;

    public const string DEFAULT_CADENCE = 'daily';

    /** The one toast every update outcome rewrites. */
    private const string SLOT = 'plugin-updates';

    private string $cadence;

    private bool $auto;

    private readonly HourlyLook $hourly;

    /** @var list<string> the plugins of this batch that reloaded with classes of the old version still loaded */
    private array $restartFor = [];

    /** @var array<string, true> `<scope>:<name>` of each update running or queued */
    private array $updating = [];

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param array<string, int|bool|string> $settings resolved settings (defaults merged)
     * @param (\Closure(): int)|null $clock seconds since the epoch; inject to test
     */
    public function __construct(
        #[Autowire('%env(json:PORTA_SETTINGS)%')]
        array $settings,
        private readonly StoreCatalog $catalog,
        private readonly PluginInstaller $installer,
        private readonly PluginLifecycleOps $lifecycle,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly ?PluginLoader $loader = null,
        ?\Closure $clock = null,
        private readonly ?UpdateRestart $restart = null,
        #[Autowire('%env(default::PORTA_RESTART_CHECK_AFTER)%')]
        private readonly ?string $restartCheckAfter = null,
    ) {
        $cadence = $settings['pluginUpdateCheck'] ?? self::DEFAULT_CADENCE;
        $this->cadence = \is_string($cadence) && \array_key_exists($cadence, self::CADENCES) ? $cadence : self::DEFAULT_CADENCE;
        $this->auto = true === ($settings['pluginAutoUpdate'] ?? false);
        $this->clock = $clock ?? \time(...);
        $this->hourly = new HourlyLook($this->checkIfDue(...));
    }

    /** Once the IDE is up: check if a check is due, and look again every hour. */
    public function start(): void
    {
        $this->hourly->start();
    }

    public function stop(): void
    {
        $this->hourly->stop();
    }

    /** The `pluginUpdateCheck` setting moved; switched on, a due check runs now. */
    public function setCadence(string $cadence): void
    {
        $this->cadence = \array_key_exists($cadence, self::CADENCES) ? $cadence : self::DEFAULT_CADENCE;
        $this->checkIfDue();
    }

    /** The `pluginAutoUpdate` setting moved. */
    public function setAuto(bool $auto): void
    {
        $this->auto = $auto;
    }

    /**
     * A check is owed when the catalog is older than the cadence: the
     * catalog's own fetch time is the note, so opening the store counts as
     * having looked.
     */
    public function checkIfDue(): void
    {
        $every = self::CADENCES[$this->cadence];
        $resumeAfter = ProcessEnvironment::restartCheckAfter($this->restartCheckAfter);
        if (($this->clock)() < $resumeAfter || 0 === $every || $this->catalog->refreshing() || (($this->clock)() - $this->catalog->fetchedAt()) < $every) {
            return;
        }
        // @mago-expect lint:no-boolean-flag-parameter -- the refresh's one answer: whether the shelves were refilled.
        $this->catalog->refresh(function (bool $filled): void {
            $outdated = $filled ? $this->outdated() : [];
            if ([] === $outdated) {
                return;
            }
            if ($this->auto) {
                $this->run($this->automaticUpdates($outdated), [], [], PluginUpdateMode::Automatic);

                return;
            }
            $names = \array_map(static fn (PluginUpdate $update): string => $update->name.' '.$update->to, $outdated);
            $this->notifier->flash($this->translator->trans('Plugin updates available: %plugins% — open Settings › Plugins › Store', ['%plugins%' => \implode(', ', $names)]), 8.0, self::SLOT);
            $this->lifecycle->refreshView();
        });
    }

    /**
     * Every installed plugin the store has a newer release of that runs here.
     *
     * @return list<PluginUpdate>
     */
    public function outdated(): array
    {
        $outdated = [];
        foreach ($this->installer->packages() as $copy) {
            $entry = $copy['packagist'] && ($this->loader?->mayLoad($copy['dir']) ?? true) ? $this->catalog->find($copy['package']) : null;
            if (null === $entry || !$entry->compatible || !LatestVersions::newer($copy['version'], $entry->version)) {
                continue;
            }
            $outdated[] = new PluginUpdate($copy['name'], $copy['scope'], $copy['package'], $copy['version'], $entry->version, $copy['dir'], $copy['constraint']);
        }

        return $outdated;
    }

    /**
     * @param list<PluginUpdate> $outdated
     * @return list<PluginUpdate>
     */
    private function automaticUpdates(array $outdated): array
    {
        $updates = [];
        foreach ($outdated as $update) {
            $entry = $this->catalog->find($update->package);
            $compatible = $entry->compatibleVersions ?? [];
            $versions = [] === $compatible ? [$update->to] : $compatible;
            try {
                $target = \array_find(
                    $versions,
                    static fn (string $version): bool => (
                        1 === \preg_match('/^v?\d+\.\d+\.\d+$/D', $version)
                        && LatestVersions::newer($update->from, $version)
                        && Semver::satisfies($version, '^'.$update->from)
                        && Semver::satisfies($version, $update->constraint)
                    ),
                );
            } catch (\UnexpectedValueException) {
                $target = null;
            }
            if (null !== $target) {
                $updates[] = new PluginUpdate($update->name, $update->scope, $update->package, $update->from, $target, $update->dir, $update->constraint);
            }
        }

        return $updates;
    }

    /** Whether the update of $name in $scope is running or queued. */
    public function isUpdating(string $name, InstallScope $scope): bool
    {
        return \array_key_exists($scope->value.':'.$name, $this->updating);
    }

    /** Bring $update's plugin to its newer release, saying how it went. */
    public function update(PluginUpdate $update): void
    {
        $this->run([$update], [], []);
    }

    /** Bring every outdated plugin up to date, one at a time, and say once how it all went. */
    public function updateAll(): void
    {
        $this->run($this->outdated(), [], []);
    }

    /**
     * @param list<PluginUpdate> $left
     * @param list<string>       $done   `name version` of each that updated
     * @param list<string>       $failed each failure's message
     */
    private function run(array $left, array $done, array $failed, PluginUpdateMode $mode = PluginUpdateMode::Manual): void
    {
        $next = \array_shift($left);
        if (null === $next) {
            $this->report($done, $failed);

            return;
        }
        $key = $next->scope->value.':'.$next->name;
        if (\array_key_exists($key, $this->updating)) {
            $this->run($left, $done, $failed, $mode);

            return;
        }
        $this->updating[$key] = true;
        $this->notifier->flash($this->translator->trans('Updating %plugin%…', ['%plugin%' => $next->name]), 60.0, self::SLOT);
        // A copy that runs stops for the update: composer removes and
        // rewrites its files, and one it autoloaded mid-swap would be half
        // the old release and half the new — or nothing at all.
        $runs = $this->runs($next);
        $needsRestart = self::loadedFrom($next->dir) || self::loadedFrom(\dirname($next->dir, levels: 2).'/vendor');
        if ($runs) {
            $this->lifecycle->unload($next->name);
        }
        $this->lifecycle->refreshView();
        $this->installer->update(
            $next->name,
            $next->scope,
            $next->to,
            function (?string $error) use ($next, $key, $runs, $left, $done, $failed, $mode, $needsRestart): void {
                unset($this->updating[$key]);
                // Back on, from whatever is on disk now: the new release, or —
                // when composer failed — the old one it left in place.
                $reload = $runs && !$needsRestart ? $this->lifecycle->activateInstalled($next->dir.'/porta.php') : null;
                $error ??= $reload;
                if ($needsRestart) {
                    $this->restartFor[] = $next->name;
                }
                if (null === $error) {
                    $done[] = $next->name.' '.$next->to;
                }
                if (null !== $error) {
                    $failed[] = $error;
                }
                $this->run($left, $done, $failed, $mode);
            },
            $mode,
        );
    }

    /**
     * Whether $update's copy is the one running: the copy of its name that
     * loads first, and switched on. One that does not run (off, or shadowed
     * by another of its name) has nothing to stop or swap — it loads the new
     * code whenever it next does. Its `porta.php` is required anew on the
     * swap, so its wiring and every class it has not loaded yet are the new
     * release's; a class it HAD loaded stays as it was — PHP cannot unload
     * one — and the plugin is named for a restart.
     */
    private function runs(PluginUpdate $update): bool
    {
        $first = \array_find($this->installer->installed(), fn (array $copy): bool => $update->name === $copy['name'] && ($this->loader?->mayLoad($copy['dir']) ?? true));

        return null !== $first && $first['dir'] === $update->dir && !$this->lifecycle->isDisabled($update->name);
    }

    /**
     * Whether a named symbol was loaded from under $dir — other
     * than its `porta.php`, whose anonymous class is new on every require.
     */
    // @noinspection MultipleReturnStatementsInspection -- Stop at the first loaded class or function; otherwise the plugin can reload live.
    private static function loadedFrom(string $dir): bool
    {
        $roots = \array_unique([\rtrim($dir, characters: '/').'/', \rtrim((string) \realpath($dir), characters: '/').'/']);
        foreach ([...\get_declared_classes(), ...\get_declared_interfaces(), ...\get_declared_traits()] as $type) {
            $reflection = new \ReflectionClass($type);
            $file = $reflection->getFileName();
            if (
                \is_string($file)
                && (!$reflection->isAnonymous() || !\str_ends_with($file, '/porta.php'))
                && [] !== \array_filter($roots, static fn (string $root): bool => '/' !== $root && \str_starts_with($file, $root))
            ) {
                return true;
            }
        }

        // @noinspection PotentialMalwareInspection -- Inspect user function origins to prevent fatal redeclaration when a plugin entrypoint is required again.
        foreach (\get_defined_functions()['user'] as $function) {
            $file = new \ReflectionFunction($function)->getFileName();
            if (\is_string($file) && [] !== \array_filter($roots, static fn (string $root): bool => '/' !== $root && \str_starts_with($file, $root))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $done
     * @param list<string> $failed
     */
    private function report(array $done, array $failed): void
    {
        $restartFor = $this->restartFor;
        $this->restartFor = [];
        $this->lifecycle->refreshView();
        if ([] !== $restartFor) {
            $this->restart?->plugins();
        }
        if ([] !== $failed) {
            $this->notifier->error(\implode(' · ', $failed), 8.0, self::SLOT);

            return;
        }
        if ([] !== $done) {
            $this->notifier->success(
                [] === $restartFor
                    ? $this->translator->trans('Updated %plugins% — reloaded live', ['%plugins%' => \implode(', ', $done)])
                    : $this->translator->trans('Updated %plugins% — restart scheduled for %names%', [
                        '%plugins%' => \implode(', ', $done),
                        '%names%' => \implode(', ', $restartFor),
                    ]),
                8.0,
                self::SLOT,
            );
        }
    }
}
