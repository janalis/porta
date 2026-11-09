<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Event\SettingsSectionRequested;
use Porta\I18n\Translator;
use Porta\Plugin\Lifecycle\InstallScope;
use Porta\Plugin\Lifecycle\PluginInstaller;
use Porta\Plugin\Lifecycle\PluginLifecycleOps;
use Porta\Plugin\Runtime\PluginLoader;
use Porta\Plugin\Store\Art;
use Porta\Plugin\Store\PluginUpdate;
use Porta\Plugin\Store\PluginUpdates;
use Porta\Plugin\Store\StoreAction;
use Porta\Plugin\Store\StoreArt;
use Porta\Plugin\Store\StoreAssets;
use Porta\Plugin\Store\StoreCatalog;
use Porta\Plugin\Store\StoreEntry;
use Porta\Plugin\Store\StoreRow;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The plugin store's page in Settings: which button each plugin gets, and what
 * pressing it does.
 *
 * The button is the App Store's: **Get** a plugin that is not here, **Open**
 * one that is (its Settings page), **Update** one the store has a newer
 * release of — and, for the plugins bundled with Porta, **Built-in**, or
 * **Enable** while one is off. A package that takes a bundled plugin over
 * ({@see \Porta\Plugin\Store\Curated::$replaces}) offers Get beside it:
 * installing it shadows the bundled copy, as any install of that name does.
 *
 * A package Porta has not verified asks twice: its first Get only says what
 * it is — PHP that runs with your permissions — and a second one installs.
 */
final class SettingsStoreController
{
    /** The vendor whose packages are the bundled plugins' own splits. */
    private const string BUNDLED_VENDOR = 'janalis/porta-';

    /** The unverified package whose Get awaits its confirming second press. */
    private ?string $armed = null;

    /** @var array<string, true> the packages an install is running for — the one the installer runs, never one it refused */
    private array $installing = [];

    public function __construct(
        private readonly StoreCatalog $catalog,
        private readonly StoreAssets $assets,
        private readonly StoreArt $art,
        private readonly PluginUpdates $updates,
        private readonly PluginInstaller $installer,
        private readonly PluginLifecycleOps $lifecycle,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly SettingsScope $scope,
        // Trailing, as everywhere in Settings: Open asks for a plugin's page through it.
        private readonly ?EventDispatcherInterface $events = null,
        private readonly ?PluginLoader $loader = null,
    ) {}

    /**
     * Every plugin on the shelves, with its button.
     *
     * @param list<PluginUpdate>|null $outdated what {@see outdated()} said, when the caller already asked — it reads every install from disk
     *
     * @return list<StoreRow>
     */
    public function rows(?array $outdated = null): array
    {
        $copies = $this->loading();
        $packages = [];
        foreach ($this->installer->packages() as $copy) {
            $packages[$copy['scope']->value.':'.$copy['name']] = $copy;
        }
        $newer = [];
        foreach ($outdated ?? $this->outdated() as $update) {
            $newer[$update->scope->value.':'.$update->package] = $update;
        }
        $rows = [];
        foreach ($this->catalog->entries() as $entry) {
            $copy = $copies[$entry->name] ?? null;
            $package = null === $copy ? null : $packages[$copy['scope']->value.':'.$entry->name] ?? null;
            $update = null !== $package && $package['package'] === $entry->package ? $newer[$package['scope']->value.':'.$entry->package] ?? null : null;
            $rows[] = new StoreRow($entry, $this->action($entry, $copy, $package['package'] ?? null, null !== $update), $package['version'] ?? '', $update);
        }

        return $rows;
    }

    /**
     * The installed plugins the store has a newer release of.
     *
     * @return list<PluginUpdate>
     */
    public function outdated(): array
    {
        return $this->updates->outdated();
    }

    public function refreshing(): bool
    {
        return $this->catalog->refreshing();
    }

    /** The store came on screen: what it shows is refetched when it is old, and the page re-read once that lands. */
    public function shown(): void
    {
        if ($this->catalog->stale() && !$this->catalog->refreshing()) {
            // @mago-expect lint:no-boolean-flag-parameter -- the refresh's one answer: whether the shelves were refilled.
            $this->catalog->refresh(function (bool $filled): void {
                if (!$filled) {
                    $this->notifier->warn($this->translator->trans('The plugin store could not reach Packagist: showing what it saw last'));
                }
                $this->lifecycle->refreshView();
            });
        }
    }

    /**
     * A page's text or picture: fetched once, then handed to $onReady.
     *
     * @param \Closure(string, ?string): void $onReady the address, and what it answered
     */
    // @mago-expect lint:no-boolean-flag-parameter -- a picture and a text are fetched and checked differently, and the widget knows which it asked for.
    public function asset(string $url, bool $image, \Closure $onReady): void
    {
        $answer = static function (?string $body) use ($url, $onReady): void {
            $onReady($url, $body);
        };
        $image ? $this->assets->image($url, $answer) : $this->assets->text($url, $answer);
    }

    /**
     * Every plugin's icon and pictures, each handed to $onArt as it is found.
     *
     * @param \Closure(string, Art): void $onArt the package and its art
     */
    public function art(\Closure $onArt): void
    {
        $this->art->resolve($this->catalog->entries(), $onArt);
    }

    /** Press $entry's button. */
    public function press(StoreEntry $entry): void
    {
        $row = \array_find($this->rows(), static fn (StoreRow $row): bool => $entry->package === $row->entry->package);
        if (null === $row) {
            return;
        }
        $armed = $this->armed;
        $this->armed = null;
        match ($row->action) {
            StoreAction::Get => $this->get($entry, $armed),
            StoreAction::Update => null === $row->update ? null : $this->updates->update($row->update),
            StoreAction::Open, StoreAction::BuiltIn => $this->events?->dispatch(new SettingsSectionRequested('plugin:'.$entry->name, 'pluginEnabled')),
            StoreAction::Enable => $this->lifecycle->enable($entry->name),
            default => null,
        };
    }

    /** Update every installed plugin the store has a newer release of. */
    public function updateAll(): void
    {
        $this->updates->updateAll();
    }

    /**
     * @param array{name: string, scope: InstallScope, dir: string}|null $copy    the copy of its name that loads here
     * @param string|null                                                 $package the package that copy was installed as
     */
    private function action(StoreEntry $entry, ?array $copy, ?string $package, bool $newer): StoreAction
    {
        $bundledSplit = self::BUNDLED_VENDOR.$entry->name === $entry->package;

        return match (true) {
            \array_key_exists($entry->package, $this->installing) || (null !== $copy && $this->updates->isUpdating($entry->name, $copy['scope'])) => StoreAction::Working,
            // Another package of the same name — a takeover offered beside the
            // bundled copy it replaces — is not installed until it is.
            null === $copy || (InstallScope::Embedded === $copy['scope'] && !$bundledSplit) || (null !== $package && $package !== $entry->package) => $entry->compatible
                ? StoreAction::Get
                : StoreAction::Incompatible,
            $this->lifecycle->isDisabled($entry->name) => StoreAction::Enable,
            $newer => StoreAction::Update,
            InstallScope::Embedded === $copy['scope'] => StoreAction::BuiltIn,
            default => StoreAction::Open,
        };
    }

    private function get(StoreEntry $entry, ?string $armed): void
    {
        if (!$entry->verified && $armed !== $entry->package) {
            $this->armed = $entry->package;
            $this->notifier->warn($this->translator->trans('"%package%" is not verified by Porta, and a plugin runs as PHP with your permissions. Press again to install it', [
                '%package%' => $entry->package,
            ]), 8.0);

            return;
        }
        $this->installing[$entry->package] = true;
        $this->notifier->flash($this->translator->trans('Installing %plugin%…', ['%plugin%' => $entry->title]), 60.0, 'plugin-store');
        $this->lifecycle->refreshView();
        // Exactly the release the store checked runs here: composer left to
        // itself would take the newest, which may target another plugin API.
        $this->installer->install(
            $entry->package,
            InstallScope::of($this->scope->current()),
            function (?string $error, ?string $file) use ($entry): void {
                unset($this->installing[$entry->package]);
                $error ??= $this->lifecycle->activateInstalled((string) $file);
                null === $error
                    ? $this->notifier->success($this->translator->trans('%plugin% installed', ['%plugin%' => $entry->title]), 5.0, 'plugin-store')
                    : $this->notifier->error($error, 8.0, 'plugin-store');
                $this->lifecycle->refreshView();
            },
            $entry->version,
        );
    }

    /**
     * The copy of each plugin name that loads here — never an untrusted
     * project's own, which does not.
     *
     * @return array<string, array{name: string, scope: InstallScope, dir: string}>
     */
    private function loading(): array
    {
        $copies = [];
        foreach ($this->installer->installed() as $copy) {
            if (!($this->loader?->mayLoad($copy['dir']) ?? true)) {
                continue;
            }
            $copies[$copy['name']] ??= $copy;
        }

        return $copies;
    }
}
