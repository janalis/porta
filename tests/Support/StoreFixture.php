<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\I18n\Translator;
use Porta\Plugin\Lifecycle\InstallScope;
use Porta\Plugin\Lifecycle\PluginInstaller;
use Porta\Plugin\Store\PackagistMetadata;
use Porta\Plugin\Store\PluginUpdates;
use Porta\Plugin\Store\StoreCatalog;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;

/**
 * The plugin store's world, scripted: what Packagist publishes
 * ({@see publish()}), what is installed and how ({@see installed()},
 * {@see bundled()}), a real {@see PluginInstaller} driving a
 * {@see FakeComposer}, and a catalog filled from the fake network.
 */
final class StoreFixture
{
    public readonly TempProject $project;

    public readonly TempProject $home;

    public readonly FakeHttp $http;

    public readonly FakePluginLifecycle $lifecycle;

    public readonly StatusBarWidget $status;

    public readonly Notifier $notifier;

    public readonly StoreCatalog $catalog;

    public readonly PluginInstaller $installer;

    public readonly Translator $translator;

    /** Whether the project is trusted: an untrusted one's composer project is not run. */
    public bool $trusted = true;

    /** @var array<string, list<array{name: string, version: string}>> scope base => what composer recorded installing there */
    private array $installedJson = [];

    /** @var array<string, int> package => downloads, as search.json lists them */
    private array $published = [];

    /** @var array<string, array{plugin?: string, composerJson?: string}> what the fake composer lands for a package */
    private array $recipes = [];

    public function __construct(int $composerExit = 0)
    {
        $this->project = new TempProject();
        $this->home = new TempProject();
        $this->http = new FakeHttp();
        $this->lifecycle = new FakePluginLifecycle();
        $this->status = new StatusBarWidget();
        $this->notifier = new Notifier($this->status, TestTui::create(200, 24));
        $this->translator = new Translator('en');
        $this->catalog = new StoreCatalog($this->http->fetch(), $this->home->path('cache'), $this->home->path('none.json'));
        $composer = FakeComposer::write($this->home->path('bin'), $composerExit, $this->recipes);
        $this->installer = new PluginInstaller(
            $this->project->dir,
            $this->home->dir,
            $this->translator,
            null,
            $this->home->path('embedded'),
            static fn (): string => $composer,
            trust: fn (): bool => $this->trusted,
        );
    }

    /**
     * Packagist publishes $package at $version.
     *
     * @param array<string, mixed> $store the release's `extra.porta.store`
     */
    public function publish(string $package, string $version = 'v1.0.0', array $store = [], int $downloads = 1, int $api = 1): void
    {
        $this->published[$package] = $downloads;
        $this->http->bodies[\sprintf(PackagistMetadata::RELEASES, $package)] = \json_encode(
            [
                'packages' => [
                    $package => [[
                        'name' => $package,
                        'version' => $version,
                        'version_normalized' => \ltrim($version, characters: 'v').'.0',
                        'type' => 'porta-plugin',
                        'source' => ['url' => 'https://github.com/'.$package.'.git', 'reference' => 'ref'],
                        'extra' => ['porta' => ['api' => $api, 'store' => $store]],
                    ]],
                ],
            ],
            \JSON_THROW_ON_ERROR,
        );
        $results = [];
        foreach ($this->published as $name => $count) {
            $results[] = ['name' => $name, 'downloads' => $count];
        }
        $this->http->bodies[PackagistMetadata::SEARCH] = \json_encode(['results' => $results], \JSON_THROW_ON_ERROR);
    }

    /** Porta's curation says $json. */
    public function curate(string $json): void
    {
        $this->http->bodies[StoreCatalog::CURATION] = $json;
    }

    /** Fill the catalog from what is published. */
    public function fill(): void
    {
        $this->catalog->refresh(static function (bool $filled): void {});
    }

    /**
     * $package is installed in $scope at $version, the way Porta's composer
     * project installs one: under `.porta/vendor`, linked from `.porta/plugins`.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- where it came from is the one fact an install carries besides its scope.
    public function installed(string $package, string $version, InstallScope $scope = InstallScope::Global, bool $fromPackagist = true): string
    {
        $base = InstallScope::Global === $scope ? $this->home : $this->project;
        $name = (string) PackagistMetadata::nameOf($package);
        $base->write('.porta/vendor/'.$package.'/porta.php', "<?php\n");
        $base->write('.porta/vendor/'.$package.'/composer.json', \json_encode(['name' => $package, 'type' => 'porta-plugin'], \JSON_THROW_ON_ERROR));
        if (!\is_dir($base->path('.porta/plugins'))) {
            \mkdir($base->path('.porta/plugins'), recursive: true);
        }
        \symlink($base->path('.porta/vendor/'.$package), $base->path('.porta/plugins/'.$name));
        $this->installedJson[$base->dir][] = ['name' => $package, 'version' => $version];
        $base->write('.porta/vendor/composer/installed.json', \json_encode(['packages' => $this->installedJson[$base->dir]], \JSON_THROW_ON_ERROR));
        $repositories = $fromPackagist ? new \stdClass() : [$package => ['type' => 'path', 'url' => '/somewhere']];
        $base->write('.porta/composer.json', \json_encode(['require' => [$package => '^1'], 'repositories' => $repositories], \JSON_THROW_ON_ERROR));
        $this->lifecycle->names[] = $name;

        return $name;
    }

    /** $name is bundled with Porta. */
    public function bundled(string $name): void
    {
        $this->home->write('embedded/'.$name.'/porta.php', "<?php\n");
        $this->lifecycle->names[] = $name;
    }

    /**
     * @param array<string, bool|int|string> $settings
     * @param (\Closure(): int)|null        $clock
     */
    public function updates(array $settings = [], ?\Closure $clock = null): PluginUpdates
    {
        return new PluginUpdates($settings, $this->catalog, $this->installer, $this->lifecycle, $this->notifier, $this->translator, clock: $clock);
    }

    /** The status bar's toast, as text. */
    public function toast(): string
    {
        /** @var array{text: string}|null $flash */
        $flash = Probe::get($this->status, 'flash');

        return \is_array($flash) ? $flash['text'] : '';
    }
}
