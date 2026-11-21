<?php

declare(strict_types=1);

namespace Porta\Tests\Behat\Context;

use Behat\Behat\Context\Environment\InitializedContextEnvironment;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Janalis\Tuink\Context\TuinkAwareContextInterface;
use Janalis\Tuink\Context\TuinkAwareTrait;
use PHPUnit\Framework\Assert;
use Porta\Plugin\Store\PackagistMetadata;
use Porta\Presence\Beacon;
use Porta\Presence\PresenceBoard;
use Porta\Settings\SettingsRow;
use Porta\Tests\Support\FakeComposer;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Toasts;
use Porta\Widget\Settings\SettingsFormWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Symfony\Component\Process\Process;

/**
 * Settings, the keymap and plugins: the Settings view walked by its page and
 * row names, a Porta home of the scenario's own for what is written globally,
 * stand-in tools and a stand-in network on the PATH, and the presence board
 * another Porta on the same folder writes to.
 */
final class SettingsContext implements TuinkAwareContextInterface
{
    use TuinkAwareTrait;

    /** Where a scenario's stand-in executables go: first on its PATH. */
    private const string BIN = '.stand-ins';

    /** Enough arrow presses to cross any page or the whole nav. */
    private const int MAX_ROWS = 96;

    private PortaContext $porta;

    #[BeforeScenario]
    public function gatherContexts(BeforeScenarioScope $scope): void
    {
        $environment = $scope->getEnvironment();
        Assert::assertInstanceOf(InitializedContextEnvironment::class, $environment);
        $this->porta = $environment->getContext(PortaContext::class);
    }

    // --- The Settings view ------------------------------------------------------

    /**
     * Settings opened, then its sidebar walked with the arrows to the page
     * named $page ("Keybindings", "Command line", "Plugins", "phpstan", "PHP")
     * and opened with Enter, as a user does.
     */
    #[When('I open the Settings page :page')]
    public function iOpenTheSettingsPage(string $page): void
    {
        $session = $this->getSession();
        if (!$this->settingsOpen()) {
            $this->porta->iUseTheAction('ide_settings');
            $session->waitUntil(fn (): bool => $this->settingsOpen(), 'The Settings view did not open.');
        }
        $session->focus('#settings-nav');
        $nav = $this->nav();
        $session->press('Up', self::MAX_ROWS);
        for ($i = 0; $i < self::MAX_ROWS && $page !== $this->navLabel($nav); ++$i) {
            $session->press('Down');
        }
        self::check($page === $this->navLabel($nav), \sprintf('The Settings sidebar has no page "%s".', $page));
        $session->press('Enter');
        $session->waitUntil(
            fn (): bool => \str_contains($session->getScreenText(), 'Settings › ') && \str_contains($session->getScreenText(), ' '.$page),
            \sprintf('The Settings page "%s" did not open.', $page),
        );
    }

    /**
     * The form's selection moved onto the row labelled $label with the arrows,
     * the focus in the form.
     */
    #[When('I select the setting :label')]
    public function iSelectTheSetting(string $label): void
    {
        $session = $this->getSession();
        $session->focus('#settings-form');
        $form = $this->form();
        $session->press('Up', self::MAX_ROWS);
        for ($i = 0; $i < self::MAX_ROWS && $label !== $this->selectedLabel($form); ++$i) {
            $session->press('Down');
        }
        self::check($label === $this->selectedLabel($form), \sprintf('The Settings page has no row "%s"; it has: %s.', $label, \implode(', ', $this->labels())));
    }

    /** A text row edited: Enter, its old value cleared, $value typed, Enter. */
    #[When('I set the setting :label to :value')]
    public function iSetTheSettingTo(string $label, string $value): void
    {
        $this->iSelectTheSetting($label);
        $session = $this->getSession();
        $session->press('Enter');
        $session->press('End');
        $session->press('Backspace', \mb_strlen((string) $this->selectedRow($this->form())?->value));
        $session->type($value);
        $session->press('Enter');
    }

    /**
     * A choice row stepped with → (or ←, from the end of the list) until it
     * shows $choice, named by its value ("macos") or by what the row shows
     * ("~/.porta/config.json"). Each step applies, as a user's would.
     */
    #[When('I choose :choice for the setting :label')]
    public function iChooseForTheSetting(string $choice, string $label): void
    {
        $this->iSelectTheSetting($label);
        $session = $this->getSession();
        $form = $this->form();
        $row = $this->selectedRow($form);
        $value = \array_search($choice, $row->optionLabels ?? [], strict: true);
        $choice = \is_string($value) ? $value : $choice;
        $options = \array_map('strval', $row->options ?? []);
        self::check(\in_array($choice, $options, strict: true), \sprintf('"%s" is not a choice of "%s": %s.', $choice, $label, \implode(', ', $options)));
        $current = fn (): string => (string) $this->selectedRow($form)?->value;
        $key = \array_search($choice, $options, strict: true) > \array_search($current(), $options, strict: true) ? 'Right' : 'Left';
        for ($i = 0; $i < \count($options) && $choice !== $current(); ++$i) {
            $session->press($key);
        }
        self::check($choice === $current(), \sprintf('"%s" did not become %s: it is %s.', $label, $choice, $current()));
    }

    /**
     * On the store, its Installed tab (Updates, then the tab after it), the
     * install field right of the scope select, the address typed in and
     * Enter: "%workspace%" names the project.
     */
    #[When('I install the plugin from :address')]
    public function iInstallThePluginFrom(string $address): void
    {
        $session = $this->getSession();
        $session->focus('#settings-store');
        $session->press('u');
        $session->press(']');
        $session->waitUntil(static fn (): bool => \str_contains($session->getScreenText(), 'Install from URL or path'), 'The store did not show its Installed tab.');
        $session->press('Right');
        $session->press('Enter');
        $session->type(\str_replace('%workspace%', $session->getWorkspace()->path, $address));
        $session->press('Enter');
    }

    #[Then('the selected setting should be :label')]
    public function theSelectedSettingShouldBe(string $label): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => $label === $this->selectedLabel($this->form()),
            fn (): string => \sprintf('The selected setting is "%s", not "%s".', $this->selectedLabel($this->form()) ?? 'none', $label),
        );
    }

    #[Then('the setting :label should be :value')]
    public function theSettingShouldBe(string $label, string $value): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => $value === self::shown($this->rowLabelled($label)?->value),
            fn (): string => \sprintf('"%s" is %s, not %s.', $label, self::shown($this->rowLabelled($label)?->value), $value),
        );
    }

    private function settingsOpen(): bool
    {
        try {
            $this->porta->tui()->getById('settings-nav');
        } catch (\InvalidArgumentException) {
            return false;
        }

        return true;
    }

    private function nav(): SettingsNavWidget
    {
        $nav = $this->porta->tui()->getById('settings-nav');
        self::check($nav instanceof SettingsNavWidget, 'The Settings sidebar is not a SettingsNavWidget.');

        return $nav;
    }

    private function form(): SettingsFormWidget
    {
        $form = $this->porta->tui()->getById('settings-form');
        self::check($form instanceof SettingsFormWidget, 'The Settings form is not a SettingsFormWidget.');

        return $form;
    }

    /** The sidebar entry under the cursor, as it reads ("Plugins (1)" reads "Plugins"). */
    private function navLabel(SettingsNavWidget $nav): ?string
    {
        $id = $nav->selectedId();
        if (null === $id) {
            return 'Languages';
        }
        $trail = $nav->trailFor($id);

        return \preg_replace('/ \(\d+\)$/', '', \end($trail)['label']);
    }

    private function selectedLabel(SettingsFormWidget $form): ?string
    {
        return $this->selectedRow($form)?->label;
    }

    private function selectedRow(SettingsFormWidget $form): ?SettingsRow
    {
        $rows = Probe::array($form, 'rows');
        $selection = Probe::object($form, 'list');
        $index = Probe::invoke($selection, 'selected');
        $row = \is_int($index) ? $rows[$index] ?? null : null;

        return $row instanceof SettingsRow ? $row : null;
    }

    private function rowLabelled(string $label): ?SettingsRow
    {
        $row = \array_find(Probe::array($this->form(), 'rows'), static fn (mixed $row): bool => $row instanceof SettingsRow && $label === $row->label);

        return $row instanceof SettingsRow ? $row : null;
    }

    /** @return list<string> the labels of the page's rows */
    private function labels(): array
    {
        return \array_values(\array_map(static fn (SettingsRow $row): string => $row->label, \array_filter(Probe::array($this->form(), 'rows'), static fn (mixed $row): bool => $row instanceof SettingsRow)));
    }

    /**
     * A failed expectation, said in Behat's terms: PHPUnit's own assertions
     * need a PHPUnit run to word their failure.
     *
     * @phpstan-assert true $holds
     */
    private static function check(bool $holds, string $message): void
    {
        if (!$holds) {
            throw new \RuntimeException($message);
        }
    }

    private static function shown(bool|int|string|null $value): string
    {
        return match ($value) {
            true => 'on',
            false => 'off',
            null => '(no such row)',
            default => (string) $value,
        };
    }

    /**
     * What a toast said, whole: a long one wraps on screen, mid-sentence.
     */
    #[Then('a notification should say :text')]
    public function aNotificationShouldSay(string $text): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => \array_any(Toasts::all($this->porta->ide()), static fn (string $toast): bool => \str_contains($toast, $text)),
            fn (): string => \sprintf("No notification said \"%s\". They said:\n%s", $text, \implode("\n", Toasts::all($this->porta->ide()))),
        );
    }

    // --- The configuration files -------------------------------------------------

    /**
     * A Porta home of the scenario's own, so what it writes globally neither
     * leaks into the next scenario nor reads the last one's. Its checks were
     * "just made", as the suite's own home's are: nothing asks the network
     * unless the scenario says so.
     */
    #[Given('a Porta home of my own')]
    public function aPortaHomeOfMyOwn(): void
    {
        $session = $this->getSession();
        if ($this->home() === ($session->getLaunch()->env['PORTA_HOME'] ?? null)) {
            return;
        }
        $workspace = $session->getWorkspace();
        $now = \time();
        $workspace->write('home/.porta/ide-updates.json', self::encode(['checkedAt' => $now]));
        $workspace->write('home/.porta/tool-updates.json', self::encode(['checkedAt' => $now, 'latest' => []]));
        $workspace->write('home/.porta/cache/store/catalog.json', self::encode(['fetchedAt' => $now, 'entries' => []]));
        $session->setLaunch($session->getLaunch()->withEnv('PORTA_HOME', $this->home()));
    }

    /** The background tool-update check is due: its note says it last looked at the epoch. */
    #[Given('the tools were last checked for updates long ago')]
    public function theToolsWereLastCheckedLongAgo(): void
    {
        $this->aPortaHomeOfMyOwn();
        $this->getSession()->getWorkspace()->write('home/.porta/tool-updates.json', self::encode(['checkedAt' => 0, 'latest' => []]));
    }

    /** The IDE update check is due: its note says it last looked at the epoch. */
    #[Given('Porta was last checked for updates long ago')]
    public function portaWasLastCheckedLongAgo(): void
    {
        $this->aPortaHomeOfMyOwn();
        $this->getSession()->getWorkspace()->write('home/.porta/ide-updates.json', self::encode(['checkedAt' => 0]));
    }

    #[Given('my global configuration:')]
    public function myGlobalConfiguration(PyStringNode $json): void
    {
        $this->aPortaHomeOfMyOwn();
        $this->getSession()->getWorkspace()->write('home/.porta/config.json', $json->getRaw()."\n");
    }

    /** $json is read as JSON where it parses ("true", "4", "[]"), else as the string it is ("yoda"). */
    #[Then('the project configuration should have :path set to :json')]
    public function theProjectConfigurationShouldHave(string $path, string $json): void
    {
        $this->assertConfigHas(fn (): array => $this->porta->projectConfig(), '.porta.json', $path, $json);
    }

    #[Then('the project configuration should not set :path')]
    public function theProjectConfigurationShouldNotSet(string $path): void
    {
        $this->assertConfigLacks(fn (): array => $this->porta->projectConfig(), '.porta.json', $path);
    }

    #[Then('the project configuration should list :value in :path')]
    public function theProjectConfigurationShouldList(string $value, string $path): void
    {
        $config = fn (): array => $this->porta->projectConfig();
        $this->getSession()->waitUntil(
            static fn (): bool => \in_array($value, (array) self::at($config(), $path)[1], strict: true),
            static fn (): string => \sprintf("%s does not list %s in %s:\n%s", '.porta.json', $value, $path, \json_encode($config(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)),
        );
    }

    #[Then('the project configuration should not list :value in :path')]
    public function theProjectConfigurationShouldNotList(string $value, string $path): void
    {
        $config = fn (): array => $this->porta->projectConfig();
        $this->getSession()->waitUntil(
            static fn (): bool => !\in_array($value, (array) self::at($config(), $path)[1], strict: true),
            static fn (): string => \sprintf("%s still lists %s in %s:\n%s", '.porta.json', $value, $path, \json_encode($config(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)),
        );
    }

    #[Then('my global configuration should have :path set to :json')]
    public function myGlobalConfigurationShouldHave(string $path, string $json): void
    {
        $this->assertConfigHas($this->globalConfig(...), '~/.porta/config.json', $path, $json);
    }

    #[Then('my global configuration should not set :path')]
    public function myGlobalConfigurationShouldNotSet(string $path): void
    {
        $this->assertConfigLacks($this->globalConfig(...), '~/.porta/config.json', $path);
    }

    private static function value(string $json): mixed
    {
        try {
            return \json_decode($json, associative: true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $json;
        }
    }

    private function home(): string
    {
        return $this->getSession()->getWorkspace()->path.'/home';
    }

    /** @return array<array-key, mixed> */
    private function globalConfig(): array
    {
        $raw = $this->getSession()->getWorkspace()->read('home/.porta/config.json');
        $decoded = null === $raw ? [] : \json_decode($raw, associative: true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param \Closure(): array<array-key, mixed> $config
     */
    private function assertConfigHas(\Closure $config, string $file, string $path, string $json): void
    {
        $expected = self::value($json);
        $this->getSession()->waitUntil(
            static fn (): bool => [true, $expected] === self::at($config(), $path),
            static fn (): string => \sprintf("%s does not set %s to %s:\n%s", $file, $path, $json, \json_encode($config(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)),
        );
    }

    /**
     * @param \Closure(): array<array-key, mixed> $config
     */
    private function assertConfigLacks(\Closure $config, string $file, string $path): void
    {
        $this->getSession()->waitUntil(
            static fn (): bool => !self::at($config(), $path)[0],
            static fn (): string => \sprintf("%s still sets %s:\n%s", $file, $path, \json_encode($config(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)),
        );
    }

    /**
     * Whether the dotted $path is set in $config, and to what. A key may hold
     * a dot of its own after a slash-free plugin name: "plugins.php-cs-fixer.args".
     *
     * @param array<array-key, mixed> $config
     *
     * @return array{bool, mixed}
     */
    private static function at(array $config, string $path): array
    {
        $value = $config;
        foreach (\explode('.', $path) as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return [false, null];
            }
            $value = $value[$key];
        }

        return [true, $value];
    }

    // --- Stand-in tools ---------------------------------------------------------

    /**
     * An executable PHP script at $path: the shebang names this PHP, so it
     * runs whatever the PATH holds. "%repo%" in it names this checkout, for
     * a stand-in the PHPUnit suites already have.
     */
    #[Given('an executable :path doing:')]
    public function anExecutableDoing(string $path, PyStringNode $php): void
    {
        $workspace = $this->getSession()->getWorkspace();
        $body = \str_replace('%repo%', \dirname(__DIR__, 3), $php->getRaw());
        $workspace->write($path, '#!'.\PHP_BINARY.' -n'."\n<?php\n".$body."\n");
        \chmod($workspace->resolve($path), 0o755);
    }

    /** A stand-in $name first on the PATH, which holds only it and the system's own tools. */
    #[Given('a stand-in :name on the PATH doing:')]
    public function aStandInOnThePathDoing(string $name, PyStringNode $php): void
    {
        $this->anExecutableDoing(self::BIN.'/'.$name, $php);
        $this->standInsOnThePath();
    }

    /**
     * The PATH is the stand-ins and the system's own tools: a phpstan or a
     * composer this machine happens to have installed is out of reach.
     */
    #[Given('only the stand-ins are on the PATH')]
    public function standInsOnThePath(): void
    {
        $session = $this->getSession();
        $session->getWorkspace()->createDirectory(self::BIN);
        $session->setLaunch($session->getLaunch()->withEnv('PATH', $session->getWorkspace()->resolve(self::BIN).':/usr/bin:/bin'));
    }

    /** A plugin's setting in .porta.json, "%workspace%" naming the project. */
    #[Given('the plugin setting :key is :value')]
    public function thePluginSettingIs(string $key, string $value): void
    {
        [$plugin, $setting] = \explode('.', $key, 2);
        $resolved = \str_replace('%workspace%', $this->getSession()->getWorkspace()->path, $value);
        $this->porta->mergeProjectConfig(['plugins' => [$plugin => [$setting => match ($resolved) {
            'true' => true,
            'false' => false,
            default => $resolved,
        }]]]);
    }

    /**
     * The PHPUnit suites' stand-in composer ({@see FakeComposer}) first on
     * the PATH: `require` of a path repository symlinks it into vendor/, as
     * composer does, with no network.
     */
    #[Given('the stand-in composer is on the PATH')]
    public function theStandInComposerIsOnThePath(): void
    {
        $this->standInsOnThePath();
        FakeComposer::write($this->getSession()->getWorkspace()->resolve(self::BIN));
    }

    /** The stand-in brew run as `brew install <formula>`, before the IDE starts. */
    #[Given('brew has installed :formula :version')]
    public function brewHasInstalled(string $formula, string $version): void
    {
        $brew = new Process([$this->getSession()->getWorkspace()->resolve(self::BIN.'/brew'), 'install', $formula]);
        $brew->mustRun();
        // Not a call the scenario made: its log starts empty.
        $this->getSession()->getWorkspace()->write(self::BIN.'/brew.log', '');
        \unlink($this->getSession()->getWorkspace()->resolve(self::BIN.'/brew.log'));
        self::check(\str_contains((string) \shell_exec(\escapeshellarg($this->getSession()->getWorkspace()->resolve(self::BIN.'/'.$formula)).' --version'), $version), \sprintf('The stand-in brew did not install %s %s.', $formula, $version));
    }

    // --- The network ------------------------------------------------------------

    /**
     * The network is a stand-in curl: each address a scenario gives answers
     * its body, any other fails, and every address asked is written down.
     */
    #[Given('the address :url answers:')]
    public function theAddressAnswers(string $url, PyStringNode $body): void
    {
        $workspace = $this->getSession()->getWorkspace();
        $answers = \json_decode($workspace->read(self::BIN.'/net.json') ?? '{}', associative: true);
        $answers = \is_array($answers) ? $answers : [];
        $answers[$url] = $body->getRaw();
        $workspace->write(self::BIN.'/net.json', self::encode($answers));
        $this->aStandInOnThePathDoing('curl', new PyStringNode([self::CURL], 0));
    }

    /** No address answers: every request fails, and is still written down. */
    #[Given('the network is out of reach')]
    public function theNetworkIsOutOfReach(): void
    {
        $workspace = $this->getSession()->getWorkspace();
        $workspace->write(self::BIN.'/net.json', '{}');
        $this->aStandInOnThePathDoing('curl', new PyStringNode([self::CURL], 0));
    }

    #[Then(':url should have been asked for')]
    public function shouldHaveBeenAskedFor(string $url): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => \in_array($url, $this->asked(), strict: true),
            fn (): string => \sprintf("%s was not asked for. Asked:\n%s", $url, \implode("\n", $this->asked())),
        );
    }

    #[Then('nothing should have been asked of the network')]
    public function nothingShouldHaveBeenAsked(): void
    {
        self::check([] === $this->asked(), "The network was asked:\n".\implode("\n", $this->asked()));
    }

    /** @return list<string> */
    private function asked(): array
    {
        $log = $this->getSession()->getWorkspace()->read(self::BIN.'/net.log') ?? '';

        return \array_values(\array_filter(\explode("\n", $log)));
    }

    /**
     * curl as HttpFetch and LatestVersions run it: `-o <file> <url>` pairs
     * written to their files with one outcome line each, or a lone address
     * answered on stdout.
     */
    private const string CURL = <<<'PHP'
        $answers = json_decode((string) file_get_contents(__DIR__.'/net.json'), true) ?: [];
        $pairs = [];
        $lone = null;
        for ($i = 1; $i < $argc; ++$i) {
            if ('-o' === $argv[$i]) {
                $pairs[] = [$argv[$i + 1], $argv[$i + 2]];
                $i += 2;
            } elseif (preg_match('~^https?://~', $argv[$i])) {
                $lone = $argv[$i];
            }
        }
        $failed = false;
        foreach (null === $lone ? $pairs : [[null, $lone]] as $index => [$file, $url]) {
            file_put_contents(__DIR__.'/net.log', $url."\n", FILE_APPEND);
            $body = $answers[$url] ?? null;
            $failed = $failed || null === $body;
            if (null === $file) {
                echo $body ?? '';
                continue;
            }
            if (null !== $body) {
                file_put_contents($file, $body);
            }
            echo $index, ' ', null === $body ? 22 : 0, ' ', null === $body ? 404 : 200, "\n";
        }
        exit($failed ? 22 : 0);
        PHP;

    // --- The plugin store -------------------------------------------------------

    /**
     * The store's shelves as it last saw them, fetched just now: what it
     * opens on without asking Packagist. Columns: package, title,
     * description, category, version, and optionally readme (an address the
     * stand-in network answers) and featured.
     */
    #[Given('the store lists:')]
    public function theStoreLists(TableNode $table): void
    {
        $this->aPortaHomeOfMyOwn();
        $entries = [];
        foreach ($table->getColumnsHash() as $row) {
            $package = $row['package'];
            $entries[] = [
                'package' => $package,
                'name' => PackagistMetadata::nameOf($package),
                'title' => $row['title'],
                'author' => \explode('/', $package)[0],
                'description' => $row['description'],
                'category' => $row['category'],
                'version' => $row['version'],
                'time' => \time() - 86_400,
                'downloads' => 1200,
                'readme' => $row['readme'] ?? null,
                'license' => 'MIT',
                'verified' => true,
                'featured' => 'yes' === ($row['featured'] ?? 'no'),
                'compatible' => true,
                'compatibleVersions' => [$row['version']],
            ];
        }
        $this->getSession()->getWorkspace()->write('home/.porta/cache/store/catalog.json', self::encode(['fetchedAt' => \time(), 'entries' => $entries]));
    }

    /** The store's copy is older than any cadence: the background check is due. */
    #[Given('the store was last refreshed long ago')]
    public function theStoreWasLastRefreshedLongAgo(): void
    {
        $this->aPortaHomeOfMyOwn();
        $workspace = $this->getSession()->getWorkspace();
        $cache = \json_decode($workspace->read('home/.porta/cache/store/catalog.json') ?? '{}', associative: true);
        $workspace->write('home/.porta/cache/store/catalog.json', self::encode(['fetchedAt' => 0] + (\is_array($cache) ? $cache : [])));
    }

    /**
     * Packagist, through the stand-in network: its search lists $package,
     * whose releases are $versions, newest first, each a Porta plugin.
     */
    #[Given('Packagist publishes :package at :versions')]
    public function packagistPublishes(string $package, string $versions): void
    {
        $releases = \array_map(static fn (string $version): array => [
            'name' => $package,
            'version' => $version,
            'version_normalized' => $version.'.0',
            'type' => 'porta-plugin',
            'description' => 'Says hello',
            'time' => '2026-11-01T00:00:00+00:00',
        ], \array_map(\trim(...), \explode(',', $versions)));
        $this->theAddressAnswers(PackagistMetadata::SEARCH, new PyStringNode([self::encode(['results' => [['name' => $package, 'downloads' => 1200]]])], 0));
        $this->theAddressAnswers(\sprintf(PackagistMetadata::RELEASES, $package), new PyStringNode([self::encode(['packages' => [$package => $releases]])], 0));
    }

    /**
     * $package installed from Packagist at $version into the project, as
     * composer leaves it: in .porta/vendor, required with a caret, recorded
     * in installed.json, its plugin directory linked under .porta/plugins.
     */
    #[Given('the plugin :package :version is installed from Packagist in this project:')]
    public function thePluginIsInstalledFromPackagist(string $package, string $version, PyStringNode $plugin): void
    {
        $workspace = $this->getSession()->getWorkspace();
        $name = (string) PackagistMetadata::nameOf($package);
        $workspace->write('.porta/vendor/'.$package.'/porta.php', $plugin->getRaw()."\n");
        $workspace->write('.porta/vendor/'.$package.'/composer.json', self::encode(['name' => $package, 'type' => 'porta-plugin']));
        $workspace->write('.porta/composer.json', self::encode(['name' => 'porta/local-plugins', 'type' => 'project', 'require' => [$package => '^'.$version]]));
        $workspace->write('.porta/vendor/composer/installed.json', self::encode(['packages' => [['name' => $package, 'version' => $version]]]));
        $workspace->createDirectory('.porta/plugins');
        \symlink($workspace->resolve('.porta/vendor/'.$package), $workspace->resolve('.porta/plugins/'.$name));
    }

    // --- Presence ---------------------------------------------------------------

    /** Another Porta at work on this folder, as it leaves itself on the board. */
    #[Given('another Porta is at work on :file as :name')]
    public function anotherPortaIsAtWork(string $file, string $name): void
    {
        // An id is sixteen hex digits, as Beacon::newId() mints them
        $id = \substr(\hash('sha256', $name), 0, 16);
        $beacon = new Beacon($id, $name, 'local', $file, 1, 0, -1, 0, \time());
        $this->getSession()->getWorkspace()->write(PresenceBoard::DIR.'/'.$id.'.json', $beacon->toJson());
    }

    #[Then('this Porta should be on the presence board as :name')]
    public function thisPortaShouldBeOnTheBoardAs(string $name): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => \in_array($name, $this->namesOnTheBoard(), strict: true),
            fn (): string => \sprintf('No beacon named "%s" on the board, only: %s.', $name, \implode(', ', $this->namesOnTheBoard())),
        );
    }

    /**
     * A negative: the board is read once this Porta has had a beat to write,
     * the seeded beacon read back proving the board is the one looked at.
     */
    #[Then('only :name should be on the presence board')]
    public function onlyShouldBeOnTheBoard(string $name): void
    {
        self::check([$name] === $this->namesOnTheBoard(), \sprintf('The board holds: %s.', \implode(', ', $this->namesOnTheBoard())));
    }

    /** @return list<string> */
    private function namesOnTheBoard(): array
    {
        $names = [];
        foreach (\glob($this->getSession()->getWorkspace()->resolve(PresenceBoard::DIR).'/*.json') ?: [] as $file) {
            $beacon = Beacon::fromJson((string) \file_get_contents($file));
            if (null !== $beacon) {
                $names[] = $beacon->name;
            }
        }
        \sort($names);

        return $names;
    }

    /** @param array<array-key, mixed> $data */
    private static function encode(array $data): string
    {
        return \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
    }
}
