<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Event\HelpPageRequested;
use Porta\Event\SettingsSectionRequested;
use Porta\I18n\Translator;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Lifecycle\PluginInstaller;
use Porta\Plugin\Runtime\PluginLoader;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Plugin\Tool\ProcessRunner;
use Porta\Settings\SettingsPluginsController;
use Porta\Settings\SettingsProvenance;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;
use Porta\Settings\SettingsScope;
use Porta\Settings\SettingsScopeRow;
use Porta\Tests\Support\FakeComposer;
use Porta\Tests\Support\FakePluginLifecycle;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTools;
use Porta\Tests\Support\TestTui;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class SettingsPluginsControllerTest extends TestCase
{
    use PumpsLoop;
    use StatusReads;

    private TempProject $project;
    private TempProject $global;
    private StatusBarWidget $status;
    private FakePluginLifecycle $lifecycle;
    private ?string $embeddedDir = null;
    /** Held so its destructor does not remove the bundled fixture mid-test. */
    private TempProject $embedded;
    private ?string $composerBinary = null;
    /** @var list<TempProject> where fake tools are written: each goes with the test, not left in the temp dir */
    private array $bins = [];
    /** @var array<string, string|null> manager name => fake binary path */
    private array $managerBinaries = [];
    private EventDispatcher $events;
    private bool $trusted = true;
    /** @var array<string, string> binary name => where the fake PATH has it */
    private array $toolPath = [];
    /** @var list<string> the binaries asked their version */
    private array $probed = [];
    private PluginSettings $pluginSettings;
    private PluginManager $plugins;
    private TestTools $tools;
    private CommandRegistry $commands;
    /** @var list<string> plugin command ids the self-install button ran */
    private array $commandsRun = [];
    /** @var list<string> the pages a Documentation row asked the help window for */
    private array $helpPages = [];

    private function controller(): SettingsPluginsController
    {
        $this->project = new TempProject();
        $this->global = new TempProject();
        $this->status = new StatusBarWidget();
        $this->lifecycle = new FakePluginLifecycle();
        $tui = TestTui::create(80, 24);

        $files = ConfigFiles::for($this->project->dir);
        // Pinned to Project — the scope defaults to Global for a project with
        // no settings block, and these tests read the install routing back
        // from a deliberate switch AWAY from Project. PluginSettings gets the
        // same holder, so its persists land in the temp project too.
        $scope = new SettingsScope($files);
        $scope->set(ConfigScope::Project);

        $this->pluginSettings = new PluginSettings($this->project->dir, [], null, $scope);
        $this->plugins = new PluginManager(new EventDispatcher(), $this->pluginSettings);
        $this->commands = new CommandRegistry();
        $this->commandsRun = [];
        $this->helpPages = [];
        $events = new EventDispatcher();
        $this->events = $events;
        $events->addListener(HelpPageRequested::class, function (HelpPageRequested $event): void {
            $this->helpPages[] = $event->path;
        });

        $installer = new PluginInstaller($this->project->dir, $this->global->dir, new Translator('en'), null, $this->embeddedDir, fn (): ?string => $this->composerBinary);
        $notifier = new Notifier($this->status, $tui);
        $real = ProcessRunner::default();
        $this->tools = new TestTools(
            $this->project->dir,
            $installer,
            $this->plugins,
            $notifier,
            $this->lifecycle,
            $this->managerBinaries,
            path: $this->toolPath,
            // A version probe answers at once; every other command (the fake
            // managers) runs for real.
            run: function (array $argv, string $cwd, \Closure $onExit, ?\Closure $onChunk = null) use ($real): void {
                if (['--version'] === \array_slice($argv, offset: 1)) {
                    $this->probed[] = $argv[0];
                    $answer = 'dot - graphviz version 2.3.4';
                    $onExit(0, $answer);

                    return;
                }
                $real($argv, $cwd, $onExit, $onChunk);
            },
            commands: $this->commands,
        );

        return new SettingsPluginsController(
            $installer,
            $this->pluginSettings,
            $notifier,
            new Translator('en'),
            $this->lifecycle,
            $this->tools->catalog,
            $this->tools->jobs,
            $this->tools->updates,
            new SettingsScopeRow($scope, $files, new Translator('en')),
            $scope,
            new SettingsProvenance($files),
            $events,
            new PluginLoader($this->project->dir, $this->global->dir, $this->pluginSettings, trusted: $this->trusted),
        );
    }

    /**
     * Commit a plugin declaring an install offer with the given availability.
     *
     * @param list<array{0: string, 1: string}> $via
     */
    private function declareInstaller(string $name, string $tool, bool $available, array $via = [['composer', 'phpstan/phpstan']], string $describe = ''): void
    {
        $api = $this->plugins->api($name, $this->project->dir, $this->pluginSettings);
        // A self-install candidate names one of the plugin's OWN commands, and
        // registerInstaller() checks that it exists — so the command has to be
        // registered on the same api first.
        if (null !== \array_find($via, static fn (array $candidate): bool => PluginApi::INSTALL_SELF === $candidate[0])) {
            $api->registerCommand('install', 'Install it', function () use ($name): void {
                $this->commandsRun[] = $name.':install';
            });
        }
        $api->registerInstaller($tool, static fn (): bool => $available, $via, '' === $describe ? null : static fn (): string => $describe);
        $this->plugins->commit($api);
        $this->plugins->registerCommands($this->commands);
    }

    private function seedPlugin(TempProject $base, string $name): void
    {
        \mkdir($base->dir.'/.porta/plugins/'.$name, permissions: 0o777, recursive: true);
        \file_put_contents($base->dir.'/.porta/plugins/'.$name.'/porta.php', data: "<?php\n");
    }

    /** A plugin source dir outside the scope bases, with a valid composer.json for a path install. */
    private function sourceDir(string $name): string
    {
        $dir = $this->project->dir.'/src/'.$name;
        \mkdir($dir, permissions: 0o777, recursive: true);
        \file_put_contents($dir.'/porta.php', data: "<?php\n");
        \file_put_contents($dir.'/composer.json', data: (string) \json_encode(['name' => 'test/'.$name, 'type' => 'porta-plugin'], \JSON_THROW_ON_ERROR));

        return $dir;
    }

    private function fakeComposer(int $exitCode = 0): string
    {
        $bin = new TempProject();
        $this->bins[] = $bin;

        return FakeComposer::write($bin->dir, $exitCode);
    }

    public function testTheIndexListsEachPluginWithWhatItIsAndAButtonToItsPage(): void
    {
        $controller = $this->controller();
        $this->seedPlugin($this->project, 'local');
        \file_put_contents(
            $this->project->dir.'/.porta/plugins/local/composer.json',
            data: (string) \json_encode(
                ['description' => 'Does local things', 'extra' => ['porta' => ['tools' => [['name' => 'nowhere-tool'], ['name' => 'nice-to-have', 'role' => 'suggested']]]]],
                \JSON_THROW_ON_ERROR,
            ),
        );
        $this->seedPlugin($this->global, 'shared');
        $this->lifecycle->names = ['local', 'shared'];
        $this->lifecycle->disabledNames = ['shared'];

        $rows = $controller->rows();
        $this->assertSame(['configScope', 'pluginInstall', 'pluginOpen:local', 'pluginOpen:shared'], \array_column($rows, 'key'));
        $this->assertSame([SettingsRowKind::Choice, SettingsRowKind::Text, SettingsRowKind::Action, SettingsRowKind::Action], \array_column($rows, 'kind'));
        $this->assertSame('project', $rows[0]->value);
        $this->assertSame(['global', 'project'], $rows[0]->options);
        $this->assertSame('.porta.json', $rows[0]->optionLabels['project'] ?? null);
        $this->assertSame('git URL, local path, or vendor/name package', $rows[1]->hint);
        $this->assertSame('local', $rows[2]->label, 'the name alone: a description would size the label column');
        $this->assertSame('on · project · needs nowhere-tool · Does local things', $rows[2]->hint, 'a missing required tool is named, a suggested one is not; the description goes last');
        $this->assertSame('shared', $rows[3]->label);
        $this->assertSame('off · global', $rows[3]->hint, 'no description, nothing after');
        $this->assertSame('Open', $rows[3]->value);
        $this->assertSame('Installed', $rows[3]->fieldset);
    }

    /** In an untrusted project its own copy never loads, so the copy described is the one that does. */
    public function testAnUntrustedProjectsShadowingCopyIsNotTheOneDescribed(): void
    {
        $this->trusted = false;
        $controller = $this->controller();
        $this->seedPlugin($this->project, 'dup');
        $this->seedPlugin($this->global, 'dup');
        $this->lifecycle->names = ['dup'];

        $this->assertSame('on · global', $controller->rows()[2]->hint);
    }

    public function testOpenShowsThePluginsPage(): void
    {
        $controller = $this->controller();
        $requested = [];
        $this->events->addListener(SettingsSectionRequested::class, static function (SettingsSectionRequested $event) use (&$requested): void {
            $requested[] = [$event->section, $event->row];
        });

        $controller->change('pluginOpen:local', 'Open');

        $this->assertSame([['plugin:local', 'pluginEnabled']], $requested, 'its page, on its first own row');
    }

    public function testABundledPluginIsBuiltInAndHasNoRemoveButton(): void
    {
        $embedded = new TempProject(['bundled/porta.php' => "<?php\n"]);
        $this->embeddedDir = $embedded->dir;
        $controller = $this->controller();
        $this->lifecycle->names = ['bundled'];

        $this->assertSame('on · built-in', $controller->rows()[2]->hint);
        $this->assertSame([], \array_filter(\array_column($controller->pluginRows('bundled'), 'key'), static fn (string $key): bool => \str_starts_with($key, 'pluginRemove:')));
        $this->embeddedDir = null;
    }

    /** Remove lives on the plugin's own page, one button per copy that can go, each saying which. */
    public function testRemoveIsOnThePluginsPageOncePerCopy(): void
    {
        $controller = $this->controller();
        $this->seedPlugin($this->project, 'twice');
        $this->seedPlugin($this->global, 'twice');
        $this->lifecycle->names = ['twice'];

        $removes = \array_values(\array_filter($controller->pluginRows('twice'), static fn (SettingsRow $row): bool => \str_starts_with($row->key, 'pluginRemove:')));
        $this->assertSame(['pluginRemove:project:twice', 'pluginRemove:global:twice'], \array_column($removes, 'key'));
        $this->assertSame(['Installed in this project', 'Installed for every project'], \array_column($removes, 'label'));
        $this->assertSame(['Remove', 'Remove'], \array_column($removes, 'value'));
        $this->assertFalse($removes[0]->danger);

        $controller->changePlugin('twice', 'pluginRemove:project:twice', 'Remove');
        $armed = \array_find($controller->pluginRows('twice'), static fn (SettingsRow $row): bool => 'pluginRemove:project:twice' === $row->key);
        $this->assertTrue($armed?->danger, 'the armed half of the confirm reads as danger');
    }

    /**
     * A plugin that ships its own guide offers it on its page, last — a
     * disabled one too, which is when reading what it does is most useful —
     * and the row asks the help window for the page rather than opening it
     * itself.
     */
    public function testAPluginsOwnDocsAreOfferedOnItsPageAndOpenInTheHelpWindow(): void
    {
        $embedded = new TempProject([
            'documented/porta.php' => "<?php\n",
            'documented/docs/README.md' => "# Documented\n",
            'bare/porta.php' => "<?php\n",
        ]);
        $this->embeddedDir = $embedded->dir;
        $controller = $this->controller();
        $this->lifecycle->names = ['documented', 'bare'];
        $this->lifecycle->disabledNames = ['documented'];

        $docs = \array_last($controller->pluginRows('documented'));
        $this->assertSame('pluginDocs', $docs->key);
        $this->assertSame(SettingsRowKind::Action, $docs->kind);
        $this->assertSame('Documentation', $docs->label);
        $this->assertSame('Open', $docs->value, 'an Action shows its value as the button caption');
        $this->assertNotContains('pluginDocs', \array_column($controller->pluginRows('bare'), 'key'), 'no guide, no row');

        $controller->changePlugin('documented', 'pluginDocs', 'Open');
        $this->assertSame([$embedded->dir.'/documented/docs/README.md'], $this->helpPages);

        $controller->changePlugin('bare', 'pluginDocs', 'Open'); // a stale row: the guide went away
        $this->assertCount(1, $this->helpPages, 'nothing to open asks for nothing');
        $this->embeddedDir = null;
    }

    public function testRemovalDeactivatesLive(): void
    {
        $controller = $this->controller();
        $this->seedPlugin($this->project, 'demo');

        $controller->changePlugin('demo', 'pluginRemove:project:demo', 'Remove');
        $controller->changePlugin('demo', 'pluginRemove:project:demo', 'Remove');
        $this->assertSame(['demo'], $this->lifecycle->deactivated, 'a successful removal deactivates the plugin live');
    }

    public function testARemoveKeyNamingNoInstallScopeIsInert(): void
    {
        $controller = $this->controller();
        $this->seedPlugin($this->project, 'demo');

        // rows() never builds such a key; a stray one must neither arm a
        // confirm nor fall through to a removal under some default scope.
        $controller->changePlugin('demo', 'pluginRemove:nowhere:demo', 'Remove');
        $controller->changePlugin('demo', 'pluginRemove:nowhere:demo', 'Remove');
        $this->assertStringNotContainsString('Press Enter again', $this->statusRow($this->status, 120));
        $this->assertSame([], $this->lifecycle->deactivated);
        $this->assertDirectoryExists($this->project->dir.'/.porta/plugins/demo');
    }

    public function testPluginSettingsPageRowsAndChanges(): void
    {
        $controller = $this->controller();
        $this->lifecycle->names = ['demo'];
        $this->assertTrue($controller->hasPlugin('demo'));
        $this->assertFalse($controller->hasPlugin('other'));

        $rows = $controller->pluginRows('demo');
        $this->assertCount(1, $rows, 'no registered settings → just the Enabled toggle');
        $this->assertSame('pluginEnabled', $rows[0]->key);
        $this->assertTrue($rows[0]->value);

        $this->pluginSettings->registerFor('demo', [
            ['key' => 'binary', 'label' => 'Binary', 'kind' => 'text', 'default' => '', 'options' => null, 'hint' => null, 'min' => null, 'max' => null, 'step' => null],
        ]);
        $rows = $controller->pluginRows('demo');
        $this->assertSame(['pluginEnabled', 'pluginSetting:binary'], \array_column($rows, 'key'));

        $controller->changePlugin('demo', 'pluginSetting:binary', '/opt/x');
        $controller->changePlugin('demo', 'someOtherKey', 'ignored');
        $this->assertSame('/opt/x', $this->pluginSettings->value('demo', 'binary'));
        // And the plugin is TOLD, with the bare key it registered — not the
        // `pluginSetting:` row id, which is this page's spelling. A plugin that
        // is never told has to read every setting at activate(), which makes
        // all of them restart-required by construction.
        $this->assertSame([['demo', 'binary', '/opt/x']], $this->lifecycle->pluginSettingChanged);
        $this->assertSame('/opt/x', $this->pluginSettings->value('demo', 'binary'), 'and told AFTER the write, so setting() reads the new value');
    }

    /**
     * The Enabled toggle is the one row on any settings page that carried no
     * provenance, and it is the one whose key REPLACES rather than merges.
     *
     * `enabledPlugins` is taken whole from the nearest layer that has it, so a
     * bundled plugin flipped on from Global scope while the project has that
     * list writes correctly and changes nothing here.
     * {@see \Porta\Settings\ShadowedWriteWarning} is what says so, and it
     * reads `origin` — with none, the warning could not fire and the toggle
     * was silent.
     */
    public function testTheEnabledToggleSaysWhichFileDecidesIt(): void
    {
        $controller = $this->bundled(['git']);
        \file_put_contents($this->project->path('.porta.json'), \json_encode(['enabledPlugins' => ['todo']], \JSON_THROW_ON_ERROR));

        $row = $controller->pluginRows('git')[0];

        $this->assertSame(ConfigScope::Project, $row->origin, 'the project list decides whether a bundled plugin loads');
    }

    /**
     * A project- or globally-INSTALLED plugin's toggle writes
     * `disabledPlugins`, which UNIONS — both layers saying "not this one"
     * agree — so a global write there can never be shadowed, and a tag would
     * claim a conflict that cannot happen.
     */
    public function testAnInstalledPluginsToggleClaimsNoProvenance(): void
    {
        $controller = $this->controller();
        $this->lifecycle->names = ['demo'];
        \mkdir($this->project->path('.porta/plugins/demo'), permissions: 0o755, recursive: true);
        \file_put_contents($this->project->path('.porta/plugins/demo/porta.php'), data: '<?php return new stdClass();');
        \file_put_contents($this->project->path('.porta.json'), \json_encode(['enabledPlugins' => ['todo']], \JSON_THROW_ON_ERROR));

        $this->assertNull($controller->pluginRows('demo')[0]->origin);
    }

    /**
     * A controller whose installer reports $names as plugins bundled with
     * Porta.
     *
     * @param list<string> $names
     */
    private function bundled(array $names): SettingsPluginsController
    {
        // Held, not chained off: TempProject removes its dir in __destruct, so
        // an inline temporary is freed before the installer ever globs it.
        $this->embedded = new TempProject();
        $this->embeddedDir = $this->embedded->dir;
        foreach ($names as $name) {
            \mkdir($this->embeddedDir.'/'.$name, permissions: 0o755, recursive: true);
            \file_put_contents($this->embeddedDir.'/'.$name.'/porta.php', data: '<?php return new stdClass();');
        }
        $controller = $this->controller();
        $this->lifecycle->names = $names;

        return $controller;
    }

    public function testTheEnabledToggleRoutesToSetEnabledAndReflectsDisabledState(): void
    {
        $controller = $this->controller();
        $this->lifecycle->disabledNames = ['demo'];
        $this->assertFalse($controller->pluginRows('demo')[0]->value, 'a disabled plugin shows unchecked');

        $controller->changePlugin('demo', 'pluginEnabled', true);
        $controller->changePlugin('demo', 'pluginEnabled', false);
        $this->assertSame([['demo', true], ['demo', false]], $this->lifecycle->toggled);
    }

    public function testScopeChangeRoutesInstallsAndAnEmptyInstallIsANoOp(): void
    {
        $this->composerBinary = $this->fakeComposer();
        $controller = $this->controller();
        $controller->change('pluginInstall', '   ');
        $this->assertSame(0, $this->lifecycle->refreshed, 'an empty source does nothing');

        $controller->change('configScope', 'global');
        $this->assertSame('global', $controller->rows()[0]->value);

        $controller->change('pluginInstall', $this->sourceDir('demo'));
        $this->await(fn (): bool => 0 < $this->lifecycle->refreshed, what: 'the install to land');
        $this->assertSame([$this->global->dir.'/.porta/plugins/demo/porta.php'], $this->lifecycle->activated, 'installed into the chosen scope and activated');
        $this->assertStringContainsString('Plugin installed', $this->statusRow($this->status, 120));
        $this->assertSame(1, $this->lifecycle->refreshed);
    }

    public function testInstallAndActivationErrorsFlashInstead(): void
    {
        $this->composerBinary = $this->fakeComposer();
        $controller = $this->controller();
        $controller->change('pluginInstall', $this->project->dir.'/missing');
        $this->assertStringContainsString(
            'is not a git URL, a local directory, or a vendor/name package',
            $this->statusRow($this->status, 240),
            'a nonexistent path matches none of the three source kinds',
        );
        $this->assertSame([], $this->lifecycle->activated);
        $this->assertSame(1, $this->lifecycle->refreshed, 'the section still refreshes after a failure');

        $this->lifecycle->activateResult = 'Plugin "demo" failed to load: nope';
        $controller->change('pluginInstall', $this->sourceDir('demo'));
        $this->await(fn (): bool => 2 === $this->lifecycle->refreshed, what: 'the second install to land');
        $this->assertStringContainsString('Plugin "demo" failed to load: nope', $this->statusRow($this->status, 120));
    }

    public function testRemovalNeedsTwoPressesAndDisarmsOnAnythingElse(): void
    {
        $controller = $this->controller();
        $this->seedPlugin($this->project, 'demo');

        $controller->changePlugin('demo', 'pluginRemove:project:demo', 'Remove');
        $this->assertStringContainsString('Press Enter again to remove "demo"', $this->statusRow($this->status, 120));
        $this->assertDirectoryExists($this->project->dir.'/.porta/plugins/demo');

        $controller->change('configScope', 'global'); // anything else disarms
        $controller->changePlugin('demo', 'pluginRemove:project:demo', 'Remove');
        $this->assertStringContainsString('Press Enter again to remove "demo"', $this->statusRow($this->status, 120));
        $this->assertDirectoryExists($this->project->dir.'/.porta/plugins/demo');

        $controller->changePlugin('demo', 'pluginRemove:project:demo', 'Remove'); // the confirming press
        $this->assertStringContainsString('Plugin "demo" removed', $this->statusRow($this->status, 120));
        $this->assertDirectoryDoesNotExist($this->project->dir.'/.porta/plugins/demo');
    }

    public function testArmingADifferentRowResetsTheConfirm(): void
    {
        $controller = $this->controller();
        $this->seedPlugin($this->project, 'one');
        $this->seedPlugin($this->project, 'two');

        $controller->changePlugin('one', 'pluginRemove:project:one', 'Remove');
        $controller->changePlugin('two', 'pluginRemove:project:two', 'Remove'); // re-arms onto "two"
        $this->assertStringContainsString('Press Enter again to remove "two"', $this->statusRow($this->status, 120));
        $this->assertDirectoryExists($this->project->dir.'/.porta/plugins/one');
        $this->assertDirectoryExists($this->project->dir.'/.porta/plugins/two');

        $controller->changePlugin('two', 'pluginRemove:project:two', 'Remove');
        $this->assertDirectoryDoesNotExist($this->project->dir.'/.porta/plugins/two');
        $this->assertDirectoryExists($this->project->dir.'/.porta/plugins/one', 'the first armed row was never confirmed');
    }

    public function testAMissingToolGetsAnInstallRowItsPageCannotPressWithNoManager(): void
    {
        $controller = $this->controller();
        $this->lifecycle->names = ['demo'];

        $this->declareInstaller('demo', 'phpstan', available: false);
        $row = \array_last($controller->pluginRows('demo'));
        $this->assertSame('pluginTool:phpstan', $row->key);
        $this->assertSame(SettingsRowKind::Action, $row->kind);
        $this->assertSame('phpstan', $row->label);
        $this->assertSame('Tools', $row->fieldset);
        $this->assertSame('Install', $row->value, 'an Action row\'s value IS its button caption');
        $this->assertTrue($row->disabled, 'no composer here: the button cannot do anything');
        $this->assertSame('missing · install it yourself', $row->hint);
    }

    public function testAMissingToolWithAManagerHereCanBeInstalled(): void
    {
        $this->managerBinaries = ['composer' => $this->fakeManager(exitCode: 0)];
        $controller = $this->controller();
        $this->lifecycle->names = ['demo'];

        $this->declareInstaller('demo', 'phpstan', available: false);
        $row = \array_last($controller->pluginRows('demo'));
        $this->assertFalse($row->disabled);
        $this->assertSame('missing', $row->hint);
    }

    /**
     * A tool someone else installed stays on the page, with an Update it
     * cannot press: replacing what a package manager Porta did not run put
     * there would fight it.
     */
    public function testAnAvailableToolPortaDidNotInstallIsManagedOutside(): void
    {
        $controller = $this->controller();
        $this->lifecycle->names = ['demo'];

        $this->declareInstaller('demo', 'phpstan', available: true);
        $row = \array_last($controller->pluginRows('demo'));
        $this->assertSame('Update', $row->value);
        $this->assertTrue($row->disabled);
        $this->assertSame('managed outside Porta', $row->hint);
    }

    /** The plugin that downloads its own tool is the same thing that replaces it. */
    public function testASelfInstallerKeepsItsRowAsAnUpdateButton(): void
    {
        $controller = $this->controller();
        $this->lifecycle->names = ['demo'];

        $this->declareInstaller('demo', 'symfony-lsp', available: true, via: [[PluginApi::INSTALL_SELF, 'install']], describe: '0.21.0');

        $row = \array_last($controller->pluginRows('demo'));
        $this->assertSame('pluginTool:symfony-lsp', $row->key);
        $this->assertSame('Update', $row->value);
        $this->assertFalse($row->disabled);
        $this->assertSame('0.21.0', $row->hint, 'what is installed, beside the button');
    }

    /** Before the tool is there the same row says Install, and has nothing to describe. */
    public function testASelfInstallerOffersInstallBeforeTheToolIsThere(): void
    {
        $controller = $this->controller();
        $this->lifecycle->names = ['demo'];

        $this->declareInstaller('demo', 'symfony-lsp', available: false, via: [[PluginApi::INSTALL_SELF, 'install']], describe: '0.21.0');

        $row = \array_last($controller->pluginRows('demo'));
        $this->assertSame('Install', $row->value);
        $this->assertSame('missing', $row->hint, 'a describer answers "what have I got?" — with nothing there, it is not asked');
    }

    /** The button runs the PLUGIN's command: it reports, relaunches and sweeps on its own terms. */
    public function testPressingASelfInstallRowRunsThePluginsOwnCommand(): void
    {
        $controller = $this->controller();
        $this->lifecycle->names = ['demo'];
        $this->declareInstaller('demo', 'symfony-lsp', available: false, via: [[PluginApi::INSTALL_SELF, 'install']]);

        $controller->changePlugin('demo', 'pluginTool:symfony-lsp', 'Install');

        $this->assertSame(['demo:install'], $this->commandsRun);
        $this->assertSame('', \trim($this->statusRow($this->status, 120)), 'no flash from here — the plugin does its own reporting');
    }

    /** What a manifest declares is on the page while the plugin is still off: that is when it is worth knowing. */
    public function testADisabledPluginsManifestToolsAreListed(): void
    {
        $controller = $this->controller();
        $this->seedPlugin($this->project, 'viewer');
        \file_put_contents(
            $this->project->dir.'/.porta/plugins/viewer/composer.json',
            data: (string) \json_encode(
                [
                    'extra' => [
                        'porta' => [
                            'tools' => [
                                ['name' => 'dot', 'role' => 'suggested', 'purpose' => 'renders Graphviz diagrams', 'setting' => 'dotPath', 'sources' => [['brew', 'graphviz']]],
                            ],
                        ],
                    ],
                ],
                \JSON_THROW_ON_ERROR,
            ),
        );
        $this->lifecycle->names = ['viewer'];
        $this->lifecycle->disabledNames = ['viewer'];

        $row = \array_find($controller->pluginRows('viewer'), static fn ($row): bool => 'pluginTool:dot' === $row->key);
        $this->assertInstanceOf(SettingsRow::class, $row);
        $this->assertSame('dot — renders Graphviz diagrams', $row->label);
        $this->assertSame('optional, missing · install it yourself, or set its path above', $row->hint, 'suggested, no brew here, and a setting to name it in');
    }

    /** A tool's version is asked the first time its page shows, once, and the page re-reads itself with it. */
    public function testAToolsVersionIsAskedWhenItsPageFirstShows(): void
    {
        $this->toolPath = ['dot' => '/opt/bin/dot'];
        $controller = $this->controller();
        $this->seedPlugin($this->project, 'viewer');
        \file_put_contents(
            $this->project->dir.'/.porta/plugins/viewer/composer.json',
            data: (string) \json_encode(['extra' => ['porta' => ['tools' => [['name' => 'dot', 'sources' => [['brew', 'graphviz']]]]]]], \JSON_THROW_ON_ERROR),
        );
        $this->lifecycle->names = ['viewer'];

        $controller->pluginRows('viewer');
        $this->assertSame([], $this->probed, 'building the rows runs nothing: the settings search builds every page');

        $controller->pageShown('viewer');
        $this->assertSame(['/opt/bin/dot'], $this->probed);
        $this->assertSame(1, $this->lifecycle->refreshed, 'the page re-reads itself once the version is in');

        $row = \array_find($controller->pluginRows('viewer'), static fn ($row): bool => 'pluginTool:dot' === $row->key);
        $this->assertInstanceOf(SettingsRow::class, $row);
        $this->assertSame('2.3.4 · /opt/bin/dot · managed outside Porta', $row->hint);
        $controller->pageShown('viewer');
        $this->assertSame(['/opt/bin/dot'], $this->probed, 'asked once, not per showing');
        $this->assertSame(1, $this->lifecycle->refreshed);
    }

    public function testInstallingTheToolRunsTheManagerAndRelaunchesOnSuccess(): void
    {
        $this->managerBinaries = ['composer' => $this->fakeManager(exitCode: 0)];
        $controller = $this->controller();
        $this->lifecycle->names = ['demo'];
        $this->declareInstaller('demo', 'phpstan', available: false);

        $controller->changePlugin('demo', 'pluginTool:phpstan', false);
        $this->assertStringContainsString('Installing phpstan', $this->statusRow($this->status, 120));
        $this->assertSame('Working…', \array_last($controller->pluginRows('demo'))->value, 'the button says the job is running');

        $this->await(fn (): bool => [] !== $this->lifecycle->serverBinaryReplaced, what: 'the install to finish');
        $this->assertStringContainsString('Installed phpstan', $this->statusRow($this->status, 120));
        $this->assertSame(['demo'], $this->lifecycle->serverBinaryReplaced, 'a successful install relaunches the provider');
    }

    public function testAFailedInstallSaysWhyAndRelaunchesNothing(): void
    {
        $this->managerBinaries = ['composer' => $this->fakeManager(exitCode: 1)];
        $controller = $this->controller();
        $this->lifecycle->names = ['demo'];
        $this->declareInstaller('demo', 'phpstan', available: false);

        $controller->changePlugin('demo', 'pluginTool:phpstan', false);
        $this->await(fn (): bool => \str_contains($this->statusRow($this->status, 120), 'Could not install'), what: 'the failure');
        $this->assertStringContainsString('Could not install phpstan: composer exited with code 1', $this->statusRow($this->status, 120));
        $this->assertSame([], $this->lifecycle->serverBinaryReplaced, 'a failed install relaunches nothing');
    }

    public function testInstallNamesTheManagersItWouldNeed(): void
    {
        $controller = $this->controller();
        $this->lifecycle->names = ['demo'];
        $this->declareInstaller('demo', 'phpantom', available: false, via: [
            ['brew',  'phpantom-lsp'],
            ['cargo', 'phpantom_lsp'],
        ]);

        $controller->changePlugin('demo', 'pluginTool:phpantom', false);
        $this->assertStringContainsString('No way to install phpantom here (needs brew, cargo)', $this->statusRow($this->status, 120));
        $this->assertSame([], $this->lifecycle->serverBinaryReplaced, 'nothing ran');
    }

    public function testASecondPressWhileItRunsIsNotASecondInstall(): void
    {
        $this->managerBinaries = ['composer' => $this->fakeManager(exitCode: 0, sleepSeconds: 1)];
        $controller = $this->controller();
        $this->lifecycle->names = ['demo'];
        $this->declareInstaller('demo', 'phpstan', available: false);

        $controller->changePlugin('demo', 'pluginTool:phpstan', false); // starts, stays running
        $controller->changePlugin('demo', 'pluginTool:phpstan', false); // already queued
        $this->assertStringContainsString('phpstan is already queued', $this->statusRow($this->status, 120));

        // Leaving mid-flight would leave a watcher on a stream the reaped
        // child is about to close.
        $this->await(fn (): bool => [] !== $this->lifecycle->serverBinaryReplaced, what: 'the in-flight install to finish');
    }

    public function testPressingARowNoToolOwnsIsANoOp(): void
    {
        $controller = $this->controller();
        $this->lifecycle->names = ['demo'];

        $controller->changePlugin('demo', 'pluginTool:phpstan', false);
        $this->assertSame(0, $this->lifecycle->refreshed, 'no declaration → nothing to install');
    }

    /** An executable stand-in for a package manager that exits with $exitCode after an optional sleep. */
    private function fakeManager(int $exitCode, int $sleepSeconds = 0): string
    {
        $bin = new TempProject();
        $this->bins[] = $bin;
        $path = $bin->dir.'/manager';
        \file_put_contents($path, "#!/bin/sh\nsleep {$sleepSeconds}\nexit {$exitCode}\n");
        \chmod($path, permissions: 0o755);

        return $path;
    }

    public function testResettingAPluginSettingDropsItAndTellsThePlugin(): void
    {
        $controller = $this->controller();
        $api = $this->plugins->api('demo', $this->project->dir, $this->pluginSettings);
        $api->registerSetting('binary', 'Binary path', 'text', '/stock');
        $this->plugins->commit($api);
        $this->pluginSettings->change('demo', 'binary', '/custom');
        $this->assertSame('/custom', $this->pluginSettings->value('demo', 'binary'));

        $controller->resetPlugin('demo', 'pluginSetting:binary');

        $this->assertSame('/stock', $this->pluginSettings->value('demo', 'binary'), 'back to the descriptor default');
        $this->assertContains(
            ['demo', 'binary', '/stock'],
            $this->lifecycle->pluginSettingChanged,
            'the plugin was told, so a holder it keeps is put back rather than staying on the removed value',
        );
    }

    /** Enabled is a lifecycle toggle and the action rows are buttons: neither resets. */
    public function testResetIgnoresRowsThatAreNotThePluginsOwnSettings(): void
    {
        $controller = $this->controller();
        $api = $this->plugins->api('demo', $this->project->dir, $this->pluginSettings);
        $api->registerSetting('binary', 'Binary path', 'text', '/stock');
        $this->plugins->commit($api);
        $this->pluginSettings->change('demo', 'binary', '/custom');

        $controller->resetPlugin('demo', 'pluginEnabled');
        $controller->resetPlugin('demo', 'pluginInstallBinary');

        $this->assertSame('/custom', $this->pluginSettings->value('demo', 'binary'), 'nothing else was disturbed');
        $this->assertSame([], $this->lifecycle->pluginSettingChanged);
    }
}
