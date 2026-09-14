<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Config\IdeConfig;
use Porta\Config\PortaJsonWriter;
use Porta\Config\RecommendedPlugins;
use Porta\Event\SettingsRowsStale;
use Porta\Highlight\CallableHighlightProvider;
use Porta\Highlight\HighlightPreferences;
use Porta\Highlight\HighlightRegistry;
use Porta\Highlight\SyntaxHighlightProvider;
use Porta\Highlight\SyntaxHighlightProviderSource;
use Porta\I18n\Translator;
use Porta\Settings\SettingsHighlightController;
use Porta\Settings\SettingsProvenance;
use Porta\Settings\SettingsRowKind;
use Porta\Settings\SettingsScope;
use Porta\Tests\Support\FakePluginLifecycle;
use Porta\Tests\Support\TempProject;

/**
 * Settings → Highlighting: derived rows, and persistence into the top-level
 * `highlight` block.
 */
final class SettingsHighlightControllerTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->lifecycle = new FakePluginLifecycle();
    }

    private FakePluginLifecycle $lifecycle;

    private HighlightPreferences $preferences;

    /** @param list<SyntaxHighlightProvider> $providers */
    private function controller(array $providers = [], string $config = ''): SettingsHighlightController
    {
        if ('' !== $config) {
            $this->project->write('.porta.json', $config);
        }

        $source = new class($providers) implements SyntaxHighlightProviderSource {
            /** @param list<SyntaxHighlightProvider> $providers */
            public function __construct(
                private readonly array $providers,
            ) {}

            public function highlightProviders(): array
            {
                return $this->providers;
            }
        };

        // One shared holder between registry and controller, as in the container.
        $this->preferences = HighlightPreferences::fromConfig(IdeConfig::load($this->project->dir)->highlight);

        // Every assertion below reads the project's .porta.json back, so the
        // session scope is pinned to Project; SettingsScope defaults to Global.
        $scope = new SettingsScope(ConfigFiles::for($this->project->dir));
        $scope->set(ConfigScope::Project);

        return new SettingsHighlightController(
            new HighlightRegistry($source, $this->preferences),
            new Translator('en'),
            $this->preferences,
            new PortaJsonWriter(ConfigFiles::for($this->project->dir)),
            $scope,
            new SettingsProvenance(ConfigFiles::for($this->project->dir)),
            $this->lifecycle,
        );
    }

    /** @param list<string> $languages */
    private function provider(string $id, array $languages): CallableHighlightProvider
    {
        return new CallableHighlightProvider(
            $id,
            static fn (): array => $languages,
            static function (string $content, string $language, \Closure $deliver): void {
                $deliver($content);
            },
        );
    }

    /** With no backends there is nothing to contest — only the global default row. */
    public function testWithNoBackendsOnlyTheDefaultRowIsShown(): void
    {
        $rows = $this->controller()->rows();

        $this->assertCount(1, $rows);
        $this->assertSame(SettingsHighlightController::DEFAULT_KEY, $rows[0]->key);
        $this->assertSame(SettingsRowKind::Choice, $rows[0]->kind);
    }

    /**
     * A fresh project has no engine — bundled plugins are opt-in — so the page
     * would otherwise offer only "no colouring" with no hint that one ships.
     */
    public function testOffersToEnableTheBundledEngineWhenNothingHighlights(): void
    {
        $this->lifecycle->names = [RecommendedPlugins::HIGHLIGHTER];

        $rows = $this->controller()->rows();

        $this->assertSame(SettingsHighlightController::ENABLE_KEY, $rows[0]->key);
        $this->assertSame(SettingsRowKind::Action, $rows[0]->kind);
        $this->assertSame('Enable tempest', $rows[0]->value, 'the plugin name is interpolated into the action label');
        $this->assertFalse($rows[0]->restart, 'enabling the plugin applies live');
    }

    public function testActuatingTheOfferEnablesThePluginLive(): void
    {
        $this->lifecycle->names = [RecommendedPlugins::HIGHLIGHTER];
        $controller = $this->controller();

        $controller->change(SettingsHighlightController::ENABLE_KEY, '');

        $this->assertSame([[RecommendedPlugins::HIGHLIGHTER, true]], $this->lifecycle->toggled);
        $this->assertFalse(\is_file($this->project->path('.porta.json')), 'enabling the engine must not also persist a bogus highlighter entry');
    }

    /** No lifecycle wired (a settings page built before the plugin system boots) must not crash on the offer. */
    public function testActuatingTheOfferWithNoLifecycleWiredIsInert(): void
    {
        // Pinned to Project so the persist lands in the temp project rather
        // than the suite's shared fixture home, which the Global default names.
        $scope = new SettingsScope(ConfigFiles::for($this->project->dir));
        $scope->set(ConfigScope::Project);
        $controller = new SettingsHighlightController(
            new HighlightRegistry(new class implements SyntaxHighlightProviderSource {
                public function highlightProviders(): array
                {
                    return [];
                }
            }, new HighlightPreferences()),
            new Translator('en'),
            new HighlightPreferences(),
            new PortaJsonWriter(ConfigFiles::for($this->project->dir)),
            $scope,
            new SettingsProvenance(ConfigFiles::for($this->project->dir)),
        );

        $controller->change(SettingsHighlightController::ENABLE_KEY, '');

        $this->addToAssertionCount(1);
    }

    /** Once an engine is loaded the offer is gone — there is nothing to enable. */
    public function testTheOfferDisappearsOnceAnEngineIsAvailable(): void
    {
        $this->lifecycle->names = [RecommendedPlugins::HIGHLIGHTER];

        $rows = $this->controller([$this->provider('tempest', ['*'])])->rows();

        $this->assertNotSame(SettingsHighlightController::ENABLE_KEY, $rows[0]->key);
    }

    /** Nothing to offer when the bundled engine isn't installed at all. */
    public function testNoOfferWhenTheBundledEngineIsNotInstalled(): void
    {
        $rows = $this->controller()->rows();

        $this->assertNotSame(SettingsHighlightController::ENABLE_KEY, $rows[0]->key);
    }

    /**
     * A language only gets a row when there is a real choice: the plain
     * fallback plus one engine is not a choice.
     */
    public function testOnlyContestedLanguagesGetTheirOwnRow(): void
    {
        $rows = $this->controller([
            $this->provider('tempest', ['*']),
            $this->provider('chroma', ['rust', 'go']),
        ])->rows();

        $keys = \array_column($rows, 'key');

        $this->assertSame([SettingsHighlightController::DEFAULT_KEY, 'lang:go', 'lang:rust'], $keys);
        $this->assertSame(['', 'tempest', 'chroma', 'plain'], $rows[1]->options, 'plugin load order, then the fallback');
        $this->assertFalse($rows[1]->restart, 'a per-language choice applies live');
        $this->assertSame('default', $rows[1]->optionLabels[''] ?? null, 'the auto option is labelled "default" on a per-language row');
    }

    /** One engine claiming everything means no per-language rows at all. */
    public function testASingleBackendContestsNothing(): void
    {
        $rows = $this->controller([$this->provider('tempest', ['*'])])->rows();

        $this->assertCount(1, $rows);
    }

    public function testTheDefaultRowOffersEveryBackendPlusAuto(): void
    {
        $rows = $this->controller([
            $this->provider('tempest', ['*']),
            $this->provider('chroma', ['rust']),
        ])->rows();

        $this->assertSame(['', 'tempest', 'chroma', 'plain'], $rows[0]->options);
        $this->assertSame('auto (first available)', $rows[0]->optionLabels[''] ?? null);
        $this->assertFalse($rows[0]->restart, 'the default highlighter applies live');
    }

    public function testChangingTheDefaultPersistsToTheHighlightBlock(): void
    {
        $controller = $this->controller([$this->provider('chroma', ['rust'])]);

        $controller->change(SettingsHighlightController::DEFAULT_KEY, 'chroma');

        $this->assertSame(['default' => 'chroma'], $this->written()['highlight'] ?? null);
    }

    public function testChangingALanguagePersistsUnderLanguages(): void
    {
        $controller = $this->controller([$this->provider('chroma', ['rust'])]);

        $controller->change('lang:rust', 'chroma');

        $this->assertSame(['languages' => ['rust' => 'chroma']], $this->written()['highlight'] ?? null);
    }

    /** Clearing removes the entry rather than storing an empty backend name. */
    public function testClearingAnOverrideRemovesIt(): void
    {
        $controller = $this->controller(
            [$this->provider('chroma', ['rust'])],
            '{"highlight":{"default":"chroma","languages":{"rust":"chroma"}}}',
        );

        $controller->change('lang:rust', '');
        $controller->change(SettingsHighlightController::DEFAULT_KEY, '');

        $this->assertArrayNotHasKey('highlight', $this->written());
    }

    /** A configured language keeps its row even once nothing contests it. */
    public function testAConfiguredLanguageKeepsItsRowSoItCanBeCleared(): void
    {
        $rows = $this->controller([], '{"highlight":{"languages":{"rust":"chroma"}}}')->rows();

        $keys = \array_column($rows, 'key');
        $this->assertContains('lang:rust', $keys);
        $this->assertSame('chroma', $rows[1]->value);
    }

    /**
     * A provider only answers supports(), so the claim set is discovered by
     * probing every grammar against every backend — and rows() walked that
     * twice, once for the backend list and once for the contested list, on a
     * page the nav rebuilds per arrow press.
     */
    public function testTheGrammarProbeRunsOncePerGeneration(): void
    {
        $probes = 0;
        $counting = new class($probes) implements SyntaxHighlightProviderSource {
            public function __construct(
                private int &$probes,
            ) {}

            public function highlightProviders(): array
            {
                ++$this->probes;

                return [];
            }
        };
        $preferences = new HighlightPreferences();
        $controller = new SettingsHighlightController(
            new HighlightRegistry($counting, $preferences),
            new Translator('en'),
            $preferences,
            new PortaJsonWriter(ConfigFiles::for($this->project->dir)),
            new SettingsScope(ConfigFiles::for($this->project->dir)),
            new SettingsProvenance(ConfigFiles::for($this->project->dir)),
            $this->lifecycle,
        );

        $controller->rows();
        $first = $probes;
        $controller->rows();
        $controller->rows();

        $this->assertSame($first, $probes, 'three page builds, one probe generation');
        $this->assertGreaterThan(0, $first, 'and it really did probe — otherwise this asserts nothing');

        $controller->onSettingsRowsStale(new SettingsRowsStale());
        $controller->rows();

        $this->assertGreaterThan($first, $probes, 'a plugin toggle re-probes: the claim set may have moved');
    }

    /**
     * The defect this pins: change() used to only persist, so the form redrew
     * the OLD value from the boot snapshot and resolution never moved.
     */
    public function testAChangeShowsInTheRowsImmediately(): void
    {
        $controller = $this->controller([$this->provider('chroma', ['rust'])]);

        $controller->change(SettingsHighlightController::DEFAULT_KEY, 'chroma');

        $this->assertSame('chroma', $controller->rows()[0]->value, 'the form must repaint the new value with no restart');
        $this->assertSame(['default' => 'chroma'], $this->written()['highlight'] ?? null, 'and it still persists');
    }

    /** The registry shares the holder, so resolution follows the same edit. */
    public function testAChangeMovesLiveResolution(): void
    {
        $this->controller([$this->provider('chroma', ['rust'])])
            ->change('lang:rust', 'chroma');

        $this->assertSame('chroma', $this->preferences->preferredFor('rust'));
    }

    /** Clearing an override live drops its configured row along with the entry. */
    public function testClearingAnOverrideClearsTheLiveRows(): void
    {
        $controller = $this->controller([], '{"highlight":{"languages":{"rust":"chroma"}}}');

        $controller->change('lang:rust', '');

        $this->assertNotContains('lang:rust', \array_column($controller->rows(), 'key'));
    }

    /** @return array<string, mixed> */
    private function written(): array
    {
        /** @var array<string, mixed> */
        return \json_decode((string) \file_get_contents($this->project->path('.porta.json')), associative: true);
    }
}
